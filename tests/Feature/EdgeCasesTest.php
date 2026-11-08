<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Notifications\Actions\Interop\ReleaseHeldInteropMailAction;
use RoundlyConsulting\Notifications\Actions\Reminders\FireEscalationAction;
use RoundlyConsulting\Notifications\Actions\Reminders\FireReminderAction;
use RoundlyConsulting\Notifications\Casts\UtcDateTime;
use RoundlyConsulting\Notifications\Contracts\HasNotificationOwner;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Events\InboxItemArchived;
use RoundlyConsulting\Notifications\Events\InboxItemUnarchived;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationTypeException;
use RoundlyConsulting\Notifications\Exceptions\NoOwnerException;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Options\DigestPreference;
use RoundlyConsulting\Notifications\Options\QuietHoursPreference;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Support\Anchors;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\DigestRuns;
use RoundlyConsulting\Notifications\Support\Durations;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\ReminderClaims;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\Windows;
use RoundlyConsulting\Notifications\Testing\NotificationExpectations;
use RoundlyConsulting\Notifications\Testing\RecordedOperation;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\VendorThingHappened;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Resolvers\ThrowingResolver;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\SecurityAlert;
use RoundlyConsulting\Options\Facades\Options;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
    NotificationExpectations::register();
});

it('exposes the model relations and helpers', function (): void {
    $owner = user();
    $contract = contract(owner: $owner, expiresAt: CarbonImmutable::parse('2027-02-17 10:00', 'UTC'));
    $manager = user();
    $contract->company->addMember($manager, 'manager');
    Notifications::send('contract.expiring', $contract, $owner, actor: $manager, context: $contract->company);

    $item = InboxItem::query()->sole();
    $delivery = NotificationDelivery::query()->sole();

    expect($item->isRead())->toBeFalse()
        ->and($item->isArchived())->toBeFalse()
        ->and($item->recipient->is($owner))->toBeTrue()
        ->and($item->context->is($contract->company))->toBeTrue()
        ->and($item->reminder)->toBeNull()
        ->and($delivery->recipient->is($owner))->toBeTrue()
        ->and($delivery->subject->is($contract))->toBeTrue()
        ->and($delivery->actor->is($manager))->toBeTrue()
        ->and($delivery->inboxItem->is($item))->toBeTrue()
        ->and($delivery->digestRun)->toBeNull()
        ->and($delivery->status->isTerminal())->toBeTrue();

    at('2026-12-19 10:00:00');
    Notifications::sweep();
    Notifications::for($contract)->acknowledge(by: $owner);
    $reminder = NotificationReminder::query()->where('kind', 'reminder')->where('status', 'sent')->sole();

    expect($reminder->subject->is($contract))->toBeTrue()
        ->and($reminder->acknowledger->is($owner))->toBeTrue()
        ->and($reminder->isEscalation())->toBeFalse()
        ->and($reminder->escalations()->count())->toBe(1)
        ->and($reminder->escalations()->first()->parent->is($reminder))->toBeTrue()
        ->and($reminder->status->isActive())->toBeFalse()
        ->and(ReminderStatus::Pending->isActive())->toBeTrue();

    $run = DigestRun::factory()->recipient($owner)->create();
    NotificationDelivery::factory()->recipient($owner)->status(DeliveryStatus::Digest)->create(['digest_run_id' => $run->id]);

    expect($run->recipient->is($owner))->toBeTrue()->and($run->deliveries()->count())->toBe(1);
});

it('ships Pest expectations that pass and fail', function (): void {
    $user = user();
    $post = post();
    Notifications::send('comment.posted', $post, $user, ['title' => 'x']);
    Notifications::preferences($user)->mute('comment.posted');

    expect($user)->toHaveUnreadInboxItems(1)
        ->toHaveInboxItem('comment.posted', $post)
        ->toHaveInboxItem('comment.posted')
        ->toHaveMuted('comment.posted');

    expect(fn () => expect($user)->toHaveUnreadInboxItems(2))->toThrow(ExpectationFailedException::class, 'Expected 2 unread inbox item(s), found 1.')
        ->and(fn () => expect($user)->toHaveInboxItem('security.alert'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => expect($user)->toHaveMuted('security.alert'))->toThrow(ExpectationFailedException::class);

    NotificationExpectations::register();
    (new NotificationExpectations)->boot();
});

it('resolves closure recipients from code-defined rules', function (): void {
    $alice = user();

    expect(Recipients::from(fn () => $alice)->resolve(null)[0]->is($alice))->toBeTrue()
        ->and(Recipients::from(fn () => Recipients::users($alice))->resolve(null))->toHaveCount(1)
        ->and(Recipients::from(fn () => null)->resolve(null))->toBe([])
        ->and(Recipients::from(fn () => 'nope')->resolve(null))->toBe([])
        ->and(Recipients::from(fn () => $alice)->storable())->toBeFalse()
        ->and(Recipients::merge(Recipients::from(fn () => $alice))->storable())->toBeFalse()
        ->and(fn () => Recipients::from(fn () => $alice)->toStored())->toThrow(UnstorableRecipientsException::class)
        ->and(Recipients::role(fn () => null, 'x')->resolve(null))->toBe([])
        ->and(RecipientSpec::fromStored(['kind' => 'group', 'group' => null, 'role' => 5])->resolve(null))->toBe([])
        ->and(RecipientSpec::fromStored(['kind' => 'owner', 'relation' => 5])->resolve(contract())[0])->toBeInstanceOf(User::class)
        ->and(RecipientSpec::fromStored(['kind' => 'merge', 'specs' => ['junk', ['kind' => 'users', 'refs' => 'junk']]])->resolve(null))->toBe([]);
});

it('resolves owners that list several models, and refuses a method that is no relation', function (): void {
    $alice = user();
    $bob = user();

    $subject = new class([$alice, $bob, 'not a model']) extends Model implements HasNotificationOwner
    {
        public function __construct(private array $owners = []) {}

        public function notificationOwner(): Model|iterable|null
        {
            return $this->owners;
        }
    };

    $resolved = Recipients::owner()->resolve($subject);

    expect($resolved)->toHaveCount(3)->and($resolved[2])->toBeNull()
        ->and(Recipients::owner('companies')->resolve($alice))->toBe([])
        ->and(fn () => Recipients::owner('getKey')->resolve($alice))->toThrow(NoOwnerException::class, 'User does not name an owner.');
});

it('reads anchors from strings, carbons and junk', function (): void {
    $post = new Post(['title' => 'x']);

    $post->setRawAttributes(['due' => '2026-12-01 09:00:00', 'junk' => 'not a date', 'empty' => '  ', 'number' => 5]);

    expect(Anchors::read($post, 'due')?->format('Y-m-d H:i'))->toBe('2026-12-01 09:00')
        ->and(Anchors::read($post, 'junk'))->toBeNull()
        ->and(Anchors::read($post, 'empty'))->toBeNull()
        ->and(Anchors::read($post, 'number'))->toBeNull()
        ->and(Anchors::same(null, null))->toBeTrue()
        ->and(Anchors::same(null, CarbonImmutable::now()))->toBeFalse();
});

it('casts instants to and from UTC strings', function (): void {
    $cast = new UtcDateTime;
    $model = new InboxItem;

    expect($cast->get($model, 'x', CarbonImmutable::parse('2026-01-01 10:00', 'Europe/Bratislava'), [])?->format('H:i e'))->toBe('09:00 UTC')
        ->and($cast->get($model, 'x', '2026-01-01T10:00:00+01:00', [])?->format('H:i'))->toBe('09:00')
        ->and($cast->get($model, 'x', 'nonsense', []))->toBeNull()
        ->and($cast->get($model, 'x', null, []))->toBeNull()
        ->and($cast->set($model, 'x', '2026-01-01T10:00:00+01:00', []))->toBe('2026-01-01 09:00:00')
        ->and($cast->set($model, 'x', '2026-01-01 10:00:00', []))->toBe('2026-01-01 10:00:00')
        ->and($cast->set($model, 'x', 42, []))->toBeNull();
});

it('reads every duration, time and list strictly, naming the key', function (string $key, mixed $value, Closure $read, string $message): void {
    config()->set($key, $value);

    expect($read)->toThrow(Exception::class, $message);
})->with([
    ['notifications.mail.lease', 'ten minutes', fn () => NotificationsConfig::mailLease(), 'must be an ISO-8601 duration'],
    ['notifications.mail.lease', 42, fn () => NotificationsConfig::mailLease(), '[42] given'],
    ['notifications.mail.lease', 'PT30S', fn () => NotificationsConfig::mailLease(), 'of at least 60 seconds'],
    ['notifications.digest.time', '8am', fn () => NotificationsConfig::digestTime(), 'must be a time of day'],
    ['notifications.digest.time', true, fn () => NotificationsConfig::digestTime(), '[true] given'],
    ['notifications.mail.backoff', 'often', fn () => NotificationsConfig::backoff(), 'notifications.mail.backoff'],
    ['notifications.mail.backoff', [0], fn () => NotificationsConfig::backoff(), 'notifications.mail.backoff'],
    ['notifications.mail.address_resolver', ['x'], fn () => NotificationsConfig::addressResolver(), 'notifications.mail.address_resolver'],
    ['notifications.digest.weekly_day', 'someday', fn () => NotificationsConfig::digestWeeklyDay(), 'notifications.digest.weekly_day'],
    ['notifications.interop.database', 'drop', fn () => NotificationsConfig::interopDatabase(), 'notifications.interop.database'],
    ['notifications.mail.strategy', 'sideways', fn () => NotificationsConfig::mailStrategy(), 'notifications.mail.strategy'],
    ['notifications.timezone.resolver', stdClass::class, fn () => app(ResolvesTimezone::class), 'notifications.timezone.resolver'],
    ['notifications.prune.inbox_after', null, fn () => throw new InvalidNotificationsConfigurationException('defaults: '.Durations::toIso(NotificationsConfig::pruneInboxAfter())), 'defaults: P180D'],
]);

it('reads the remaining config defaults', function (): void {
    config()->set('notifications.mail.backoff', null);
    config()->set('notifications.subject_key_type', 'uuid');
    config()->set('notifications.context_key_type', 'ulid');
    config()->set('notifications.interop.notifiables', ['\\'.User::class]);
    config()->set('notifications.routes.auth_middleware', null);

    expect(NotificationsConfig::backoff())->toBe([60, 300, 900])
        ->and(NotificationsConfig::subjectKeyType()->value)->toBe('uuid')
        ->and(NotificationsConfig::contextKeyType()->value)->toBe('ulid')
        ->and(NotificationsConfig::keyTypeKeyFor('subject'))->toBe('notifications.subject_key_type')
        ->and(NotificationsConfig::keyTypeKeyFor('context'))->toBe('notifications.context_key_type')
        ->and(NotificationsConfig::keyTypeKeyFor('actor'))->toBe('notifications.key_type')
        ->and(NotificationsConfig::interopNotifiables())->toBe([User::class])
        ->and(NotificationsConfig::routesAuthMiddleware())->toBe(['auth'])
        ->and(NotificationsConfig::routesPrefix())->toBe('notifications')
        ->and(NotificationsConfig::idempotencyTtl()->d)->toBe(30)
        ->and(NotificationsConfig::pruneDigestRunsAfter()->d)->toBe(90)
        ->and(NotificationsConfig::reminderLease()->i)->toBe(10)
        ->and(Windows::strategy(Notifications::types()->get('security.alert')))->toBe(MailStrategy::Leading);
});

it('refuses a configured type that does not exist', function (): void {
    config()->set('notifications.types', ['Nope\\Missing']);
    app(TypeRegistry::class)->flush();

    app(TypeRegistry::class)->all();
})->throws(InvalidConfigurationException::class);

it('refuses a container binding that resolves a type to something else', function (): void {
    app()->bind(SecurityAlert::class, fn () => new stdClass);
    app(TypeRegistry::class)->flush();

    app(TypeRegistry::class)->all();
})->throws(InvalidNotificationTypeException::class);

it('refuses an invalid window at send time too', function (): void {
    $type = new class extends NotificationType
    {
        public function key(): string
        {
            return 'late.window';
        }

        public function window(): string
        {
            return 'P2D';
        }

        public function title(NotificationPayload $payload): string
        {
            return 'x';
        }
    };

    Windows::of($type);
})->throws(InvalidNotificationTypeException::class);

it('references gone models as null and identities as dashes', function (): void {
    expect(ModelRefs::identity(null))->toBe('-')
        ->and(ModelRefs::find('Nope\\Class', 1))->toBeNull()
        ->and(ModelRefs::find(null, 1))->toBeNull()
        ->and(ModelRefs::load('junk'))->toBeNull()
        ->and(ModelRefs::load(['type' => 'user', 'id' => 1.5]))->toBeNull()
        ->and(ModelRefs::key(new User))->toBe('');
});

describe('escalation fire paths', function (): void {
    function escalationRow(array $attributes = []): NotificationReminder
    {
        $contract = $attributes['subject'] ?? contract();
        unset($attributes['subject']);

        return NotificationReminder::factory()->subject($contract)->create($attributes + [
            'kind' => ReminderKind::Escalation,
            'type' => 'contract.expiring',
            'level' => 1,
            'due_at' => now()->subMinute(),
        ]);
    }

    it('cancels for an unknown type, a missing chain, a missing level or an unknown escalation type', function (array $attributes, CancelReason $reason): void {
        $row = escalationRow($attributes);

        expect(app(FireEscalationAction::class)->execute($row->id))->toBeFalse()
            ->and($row->fresh()->cancel_reason)->toBe($reason);
    })->with([
        'unknown type' => [['type' => 'gone.type'], CancelReason::UnknownType],
        'no chain' => [['data' => ['payload' => []]], CancelReason::UnknownRule],
        'bad chain entry' => [['data' => ['chain' => ['junk']]], CancelReason::UnknownRule],
        'missing level' => [['level' => 3, 'data' => ['chain' => [['after' => 'P1D', 'to' => ['kind' => 'users', 'refs' => []]]]]], CancelReason::UnknownRule],
        'unknown escalation type' => [['data' => ['chain' => [['after' => 'P1D', 'to' => ['kind' => 'users', 'refs' => []], 'type' => 'gone.type']]]], CancelReason::UnknownType],
        'acknowledged' => [['acknowledged_at' => now()], CancelReason::Acknowledged],
    ]);

    it('cancels when the subject is gone or the root rule is gone or its condition fails', function (): void {
        $contract = contract();
        $root = NotificationReminder::factory()->subject($contract)->create(['type' => 'contract.expiring', 'rule' => 'expires_at:before', 'status' => ReminderStatus::Sent]);
        $orphan = NotificationReminder::factory()->subject($contract)->create(['type' => 'contract.expiring', 'rule' => 'gone:rule', 'status' => ReminderStatus::Sent]);

        $unknownRule = escalationRow(['subject' => $contract, 'parent_id' => $orphan->id]);
        $condition = escalationRow(['subject' => $contract, 'parent_id' => $root->id, 'active_key' => 'c']);
        $contract->update(['terminated_at' => now()]);

        app(FireEscalationAction::class)->execute($unknownRule->id);
        app(FireEscalationAction::class)->execute($condition->id);

        $gone = escalationRow(['subject' => contract()]);
        DB::table('contracts')->where('id', $gone->subject_id)->delete();
        app(FireEscalationAction::class)->execute($gone->id);

        expect($unknownRule->fresh()->cancel_reason)->toBe(CancelReason::UnknownRule)
            ->and($condition->fresh()->cancel_reason)->toBe(CancelReason::Condition)
            ->and($gone->fresh()->cancel_reason)->toBe(CancelReason::SubjectDeleted)
            ->and(app(FireEscalationAction::class)->execute($gone->id))->toBeFalse();
    });

    it('retries a failing escalation, then cancels it', function (): void {
        $down = ['kind' => 'using', 'resolver' => ThrowingResolver::class, 'args' => []];
        $row = escalationRow(['data' => ['chain' => [['after' => 'P1D', 'to' => $down]]], 'recipients' => $down, 'attempts' => 2]);

        expect(app(FireEscalationAction::class)->execute($row->id))->toBeFalse()
            ->and($row->fresh()->status)->toBe(ReminderStatus::Cancelled)
            ->and($row->fresh()->cancel_reason)->toBe(CancelReason::Unknown);

        $retry = escalationRow(['data' => ['chain' => [['after' => 'P1D', 'to' => $down]]], 'recipients' => $down]);
        app(FireEscalationAction::class)->execute($retry->id);

        expect($retry->fresh()->status)->toBe(ReminderStatus::Pending);
    });

    it('retries a failing reminder, then cancels it', function (): void {
        $contract = contract(expiresAt: CarbonImmutable::parse('2027-02-17 10:00', 'UTC'));
        $row = NotificationReminder::query()->where('offset', '-P60D')->sole();
        $row->forceFill(['due_at' => now()->subMinute(), 'attempts' => 2, 'recipients' => ['kind' => 'using', 'resolver' => ThrowingResolver::class, 'args' => []]])->save();

        expect(app(FireReminderAction::class)->execute($row->id))->toBeFalse()
            ->and($row->fresh()->cancel_reason)->toBe(CancelReason::Unknown)
            ->and(app(FireReminderAction::class)->execute($row->id))->toBeFalse()
            ->and($contract->exists)->toBeTrue();
    });
});

it('releases nothing it cannot claim, and cancels a held mapped mail whose recipient is gone', function (): void {
    expect(fn () => app(ReleaseHeldInteropMailAction::class)->execute(999))->not->toThrow(Throwable::class);

    config()->set('notifications.interop.map', [VendorThingHappened::class => 'vendor.thing']);
    app()->forgetInstance(ChannelManager::class);
    Notification::clearResolvedInstance(ChannelManager::class);
    Notification::swap(app(ChannelManager::class));

    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '11:00');
    $user->notify(new VendorThingHappened(post()));
    $user->delete();

    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::RecipientGone);
});

it('retries a held mapped mail whose transport fails', function (): void {
    config()->set('notifications.interop.map', [VendorThingHappened::class => 'vendor.thing']);
    app()->forgetInstance(ChannelManager::class);
    Notification::clearResolvedInstance(ChannelManager::class);
    $manager = app(ChannelManager::class);
    Notification::swap($manager);

    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '11:00');
    $user->notify(new VendorThingHappened(post()));

    $manager->extend('mail', fn () => new class
    {
        public function send(): never
        {
            throw new RuntimeException('down');
        }
    });

    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Pending)
        ->and(NotificationDelivery::query()->sole()->send_after->format('H:i'))->toBe('11:01');

    config()->set('notifications.mail.max_attempts', 1);
    at('2026-11-09 11:01:00');
    Notifications::sweep();

    expect(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Failed);
});

it('rolls unattached digest items forward when their run is taken, by frequency', function (): void {
    $reader = user();
    Notifications::preferences($reader)->digest(DigestFrequency::Daily);
    DigestRun::factory()->recipient($reader)->create(['period' => 'off-202611090900', 'status' => 'sent']);
    DigestRun::factory()->recipient($reader)->create(['period' => '2026-11-09', 'status' => 'sent']);

    foreach (['off-202611090900', '2026-11-09'] as $period) {
        NotificationDelivery::factory()->recipient($reader)->status(DeliveryStatus::Digest)->create([
            'type' => 'comment.posted', 'digest_frequency' => DigestFrequency::Daily, 'digest_period' => $period, 'send_after' => now()->subMinute(),
        ]);
    }

    $gone = user();
    DigestRun::factory()->recipient($gone)->create(['period' => '2026-11-09', 'status' => 'sent']);
    NotificationDelivery::factory()->recipient($gone)->status(DeliveryStatus::Digest)->create([
        'type' => 'comment.posted', 'digest_frequency' => DigestFrequency::Daily, 'digest_period' => '2026-11-09', 'send_after' => now()->subMinute(),
    ]);
    $gone->delete();

    Notifications::digests()->sendDue();

    expect(NotificationDelivery::query()->orderBy('id')->pluck('digest_period')->all())->toBe(['off-202611091001', '2026-11-10', 'off-202611091000']);
});

it('records the fake\'s context helpers', function (): void {
    $op = new RecordedOperation(NotificationOperation::Send, ['subject' => 'not a model']);

    expect($op->concerns(null, 'subject'))->toBeTrue()
        ->and($op->concerns(user(), 'subject'))->toBeFalse();

    $fake = Notifications::fake();
    Notifications::send('gone.type', null, user());
    Notifications::send('request.awaiting_approval', null, Recipients::owner());

    expect($fake->recorded())->toHaveCount(2)
        ->and($fake->recorded()[0]->recipients)->toBe([])
        ->and($fake->recorded()[1]->recipients)->toBe([]);
});

it('keeps a type\'s default hooks', function (): void {
    $type = Notifications::types()->get('request.awaiting_approval');
    $payload = new NotificationPayload('x', user(), null, null, null, null, [], 1, now()->toImmutable(), now()->toImmutable(), Channel::Mail);

    expect($type->canAccess(user(), null))->toBeTrue()
        ->and($type->subjectFrom(new VendorThingHappened(post())))->toBeNull()
        ->and($type->allowsTrashedSubject())->toBeFalse()
        ->and($type->notifyActor())->toBeFalse()
        ->and($type->sensitive(null))->toBeFalse()
        ->and($type->mandatory())->toBeFalse()
        ->and($type->digestible())->toBeFalse()
        ->and($type->reminders())->toBe([])
        ->and($type->priority())->toBe(Priority::Normal)
        ->and($type->mail(new MailMessage, $payload))->toBeInstanceOf(MailMessage::class)
        ->and(Notifications::types()->get('comment.posted')->mail(new MailMessage, $payload))->toBeInstanceOf(MailMessage::class);
});

it('accepts a fluent body that is not a string as none, and a mail hook that returns junk', function (): void {
    Notifications::define('odd.hooks')->title(fn (): string => 'x')->body(fn (): int => 5)->url(fn (): int => 5)
        ->mail(fn (): string => 'junk')->context(fn (): int => 5)->subjectFrom(fn (): string => 'junk')->register();
    $type = Notifications::types()->get('odd.hooks');
    $payload = new NotificationPayload('x', user(), null, null, null, null, [], 1, now()->toImmutable(), now()->toImmutable(), Channel::Mail);
    $message = new MailMessage;

    expect($type->body($payload))->toBeNull()
        ->and($type->url($payload))->toBeNull()
        ->and($type->mail($message, $payload))->toBe($message)
        ->and($type->context(null))->toBeNull()
        ->and($type->subjectFrom(new VendorThingHappened(post())))->toBeNull();
});

it('re-times a held delivery inside a host transaction lock path, and ignores escalation templates in sync', function (): void {
    Notifications::define('rule.sans.attr')->subject(Contract::class)
        ->reminders(Reminder::before('expires_at', 'P1D')->to(fn () => null))
        ->title(fn (): string => 'x')->register();

    $contract = contract(expiresAt: CarbonImmutable::parse('2026-11-20 10:00', 'UTC'));

    expect(Notifications::for($contract)->reminders()->pending('rule.sans.attr'))->toHaveCount(1);
});

it('logs an invalid stored digest preference as the default', function (): void {
    $user = user();
    Options::set(DigestPreference::class, ['frequency' => 'hourly'], $user);
    Options::set(QuietHoursPreference::class, ['start' => '22:00', 'end' => '22:00'], $user);

    expect(Notifications::preferences($user)->digestPreference()->frequency)->toBe(DigestFrequency::Off)
        ->and(Notifications::preferences($user)->quietHoursPreference())->toBeNull();
});

it('dispatches no event for an archive or unarchive that changes nothing', function (): void {
    Event::fake([InboxItemArchived::class, InboxItemUnarchived::class]);
    $user = user();
    Notifications::send('security.alert', null, $user);
    $item = InboxItem::query()->sole();

    Notifications::inbox($user)->unarchive($item);

    Event::assertNotDispatched(InboxItemUnarchived::class);
});

it('stamps updated_at on every claim, so prune ages claimed rows from the claim (C-3)', function (): void {
    at('2026-11-01 08:00:00');
    $delivery = NotificationDelivery::factory()->create();
    $run = DigestRun::factory()->create();
    $reminder = NotificationReminder::factory()->create(['due_at' => CarbonImmutable::now('UTC')]);

    at('2026-11-09 10:00:00');

    expect(DeliveryStates::claim($delivery->id, NotificationDelivery::SOURCE_TYPE))->toBeTrue()
        ->and(DigestRuns::claim($run))->toBeTrue()
        ->and(ReminderClaims::claim($reminder->id))->toBeTrue();

    foreach ([$delivery, $run, $reminder] as $row) {
        $fresh = $row->fresh();

        expect($fresh?->updated_at?->format('Y-m-d H:i:s'))->toBe('2026-11-09 10:00:00')
            ->and($fresh?->claimed_at?->format('Y-m-d H:i:s'))->toBe('2026-11-09 10:00:00')
            ->and($fresh?->attempts)->toBe(1);
    }
});
