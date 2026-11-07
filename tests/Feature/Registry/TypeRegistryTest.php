<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use RoundlyConsulting\Notifications\DefinedType;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Exceptions\DuplicateNotificationTypeException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationTypeException;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\SecurityAlert;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('throws for an unknown type key at send (C-01)', function (): void {
    Notifications::send('nope.never', null, user());
})->throws(UnknownNotificationTypeException::class, 'The notification type "nope.never" is not registered.');

it('names a class-string type by its basename, never the class, when it is unknown', function (): void {
    Notifications::send(Post::class, null, user());
})->throws(UnknownNotificationTypeException::class, '"Post"');

it('finds a class type by its class-string', function (): void {
    expect(app(TypeRegistry::class)->find(SecurityAlert::class)->key())->toBe('security.alert');
});

it('refuses a digestible type that is not low priority (C-03)', function (): void {
    Notifications::define('digest.bad')->digestible()->title(fn (): string => 'x')->register();
})->throws(InvalidNotificationTypeException::class, 'is digestible but not low priority');

it('names both sources when a key is registered twice (C-04)', function (): void {
    Notifications::register(SecurityAlert::class);
})->throws(DuplicateNotificationTypeException::class, 'The notification type "security.alert" is registered twice (SecurityAlert and SecurityAlert).');

it('names define() as the source of a fluent duplicate', function (): void {
    Notifications::define('comment.posted')->title(fn (): string => 'x')->register();
})->throws(DuplicateNotificationTypeException::class, '(define() and define())');

it('validates keys, categories, windows, channels and subjects', function (Closure $define, string $message): void {
    expect(fn () => $define()->register())->toThrow(InvalidNotificationTypeException::class, $message);
})->with([
    'key with a class name' => [fn () => Notifications::define('App\\Thing'), 'is invalid'],
    'key too long' => [fn () => Notifications::define(str_repeat('a', 101)), 'is invalid'],
    'uppercase category' => [fn () => Notifications::define('ok.key')->category('Bad'), 'category'],
    'window over a day' => [fn () => Notifications::define('ok.window')->window('P2D'), 'at most one day'],
    'unreadable window' => [fn () => Notifications::define('ok.window2')->window('soonish'), 'at most one day'],
    'no channels' => [fn () => Notifications::define('ok.channels')->channels(), 'at least one channel'],
    'not a model subject' => [fn () => Notifications::define('ok.subject')->subject(stdClass::class), 'must be an Eloquent model'],
]);

it('validates reminder rules at registration', function (Closure $define, string $reason): void {
    expect(fn () => $define()->register())->toThrow(InvalidReminderRuleException::class, $reason);
})->with([
    'no subject' => [fn () => Notifications::define('r.one')->reminders(Reminder::before('due_at', 'P1D')), 'need a subject'],
    'absolute in a rule' => [fn () => Notifications::define('r.two')->subject(Post::class)->reminders(Reminder::at(now())), 'ad-hoc'],
    'empty attribute' => [fn () => Notifications::define('r.three')->subject(Post::class)->reminders(Reminder::before(' ', 'P1D')), 'attribute is empty'],
    'no offsets' => [fn () => Notifications::define('r.four')->subject(Post::class)->reminders(Reminder::before('due_at')), 'between 1 and 25'],
    'too many offsets' => [fn () => Notifications::define('r.five')->subject(Post::class)->reminders(Reminder::before('due_at', ...array_map(fn (int $d): string => "P{$d}D", range(1, 26)))), 'between 1 and 25'],
    'bad offset' => [fn () => Notifications::define('r.six')->subject(Post::class)->reminders(Reminder::before('due_at', 'nonsense')), 'positive ISO-8601'],
    'duplicate rule keys' => [fn () => Notifications::define('r.seven')->subject(Post::class)->reminders(Reminder::before('due_at', 'P1D'), Reminder::before('due_at', 'P2D')), 'unique per type'],
    'zero escalation' => [fn () => Notifications::define('r.eight')->subject(Post::class)->reminders(Reminder::before('due_at', 'P1D')->escalate('nonsense', user())), 'positive delay'],
]);

it('accepts two rules on one attribute with explicit keys', function (): void {
    Notifications::define('r.keys')->subject(Post::class)
        ->reminders(Reminder::before('due_at', 'P1D')->key('a'), Reminder::before('due_at', 'P2D')->key('b'))
        ->title(fn (): string => 'x')
        ->register();

    expect(Notifications::types()->get('r.keys')->reminders())->toHaveCount(2);
});

it('lists types for a preferences UI, translated', function (): void {
    Lang::addLines(['notifications.types.security.alert.label' => 'Security alerts', 'notifications.types.security.alert.description' => 'Sign-ins and password changes'], 'en');
    Lang::addLines(['notifications.categories.security' => 'Security'], 'en');

    $types = Notifications::types()->all();
    $alert = collect($types)->firstWhere('key', 'security.alert');
    $comment = collect($types)->firstWhere('key', 'comment.posted');

    expect($types)->toHaveCount(5)
        ->and($alert->label)->toBe('Security alerts')
        ->and($alert->description)->toBe('Sign-ins and password changes')
        ->and($alert->mandatory)->toBeTrue()
        ->and($alert->priority)->toBe(Priority::Urgent)
        ->and($alert->channels)->toBe([Channel::Inbox, Channel::Mail])
        ->and($comment->label)->toBe('Comment Posted')
        ->and($comment->description)->toBeNull()
        ->and($comment->digestible)->toBeTrue()
        ->and(collect(Notifications::types()->categories())->firstWhere('key', 'security')->label)->toBe('Security')
        ->and(collect(Notifications::types()->categories())->firstWhere('key', 'contracts')->label)->toBe('Contracts');
});

it('falls back to the package\'s own category label', function (): void {
    Notifications::define('plain.notice')->title(fn (): string => 'x')->register();

    app()->setLocale('sk');

    expect(collect(Notifications::types()->categories())->firstWhere('key', 'general')->label)->toBe('Všeobecné');
});

it('builds a fluent type with every hook', function (): void {
    Notifications::define('full.type')
        ->category('full')
        ->label('Full', 'Everything')
        ->channels(Channel::Mail)
        ->optIn(Channel::Mail)
        ->mandatory()
        ->priority(Priority::Urgent)
        ->window('PT10M')
        ->mailStrategy(MailStrategy::Trailing)
        ->subject(Post::class, allowsTrashed: true)
        ->notifyActor()
        ->title(fn (NotificationPayload $p): string => 'Title '.$p->count)
        ->body(fn (): string => 'Body')
        ->url(fn (): string => 'https://app.test/x')
        ->access(fn (): bool => false)
        ->context(fn (): string => 'ctx')
        ->sensitive(fn (): bool => true)
        ->mail(fn ($message) => $message->line('extra'))
        ->subjectFrom(fn (): Post => post())
        ->register();

    $type = Notifications::types()->get('full.type');

    expect($type)->toBeInstanceOf(DefinedType::class)
        ->and($type->category())->toBe('full')
        ->and($type->label())->toBe('Full')
        ->and($type->description())->toBe('Everything')
        ->and($type->channels())->toBe([Channel::Mail])
        ->and($type->enabledByDefault(Channel::Mail))->toBeFalse()
        ->and($type->enabledByDefault(Channel::Inbox))->toBeTrue()
        ->and($type->mandatory())->toBeTrue()
        ->and($type->priority())->toBe(Priority::Urgent)
        ->and($type->window())->toBe('PT10M')
        ->and($type->mailStrategy())->toBe(MailStrategy::Trailing)
        ->and($type->subject())->toBe(Post::class)
        ->and($type->allowsTrashedSubject())->toBeTrue()
        ->and($type->notifyActor())->toBeTrue()
        ->and($type->canAccess(user(), post()))->toBeFalse()
        ->and($type->context(null))->toBe('ctx')
        ->and($type->sensitive(null))->toBeTrue()
        ->and($type->subjectFrom(new Notification))->toBeInstanceOf(Post::class)
        ->and($type->defines('access'))->toBeTrue()
        ->and($type->defines('nope'))->toBeFalse();
});

it('keeps the defaults of an unset fluent hook', function (): void {
    Notifications::define('bare.type')->register();

    $type = Notifications::types()->get('bare.type');
    $payload = new NotificationPayload('bare.type', user(), null, null, null, null, [], 1, now()->toImmutable(), now()->toImmutable(), Channel::Inbox);

    expect($type->title($payload))->toBe('Bare Type')
        ->and($type->body($payload))->toBeNull()
        ->and($type->url($payload))->toBeNull()
        ->and($type->context(null))->toBeNull()
        ->and($type->sensitive(null))->toBeFalse()
        ->and($type->subjectFrom(new Notification))->toBeNull()
        ->and($type->mailStrategy())->toBe(MailStrategy::Leading)
        ->and($type->category())->toBe('general')
        ->and($type->channels())->toBe([Channel::Inbox, Channel::Mail])
        ->and($type->mail(new MailMessage, $payload))->toBeInstanceOf(MailMessage::class)
        ->and($type->publicData(['a' => 1]))->toBe(['a' => 1])
        ->and($type->digestLine($payload))->toBe('Bare Type')
        ->and($type->actionText($payload))->toBe('View')
        ->and($type->minimalTitle())->toBe('New notification from Roundly');
});

it('refuses a configured type that is not a NotificationType', function (): void {
    config()->set('notifications.types', [stdClass::class]);
    app(TypeRegistry::class)->flush();

    app(TypeRegistry::class)->all();
})->throws(InvalidConfigurationException::class, 'notifications.types');

it('flushes and reloads the registry', function (): void {
    $registry = app(TypeRegistry::class);
    $registry->flush();

    expect($registry->has('security.alert'))->toBeTrue()
        ->and($registry->has('comment.posted'))->toBeFalse();
});

it('lists the types the subject class is about', function (): void {
    expect(array_map(fn (NotificationType $type): string => $type->key(), app(TypeRegistry::class)->forSubject(post())))
        ->toBe(['vendor.thing', 'comment.posted']);
});
