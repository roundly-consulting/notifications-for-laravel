<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Notifications\Contracts\ResolvesMailAddress;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Events\NotificationMailFailed;
use RoundlyConsulting\Notifications\Events\NotificationMailHeld;
use RoundlyConsulting\Notifications\Events\NotificationMailSent;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Jobs\DeliverMailJob;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PlainAccount;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

/**
 * @return array{0: PurchaseRequest, 1: User}
 */
function mailSetup(array $attributes = []): array
{
    $approver = user($attributes);
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');

    return [$request, $approver];
}

function delivery(): NotificationDelivery
{
    return NotificationDelivery::query()->sole();
}

/**
 * A mail channel whose transport is down.
 */
function failingMail(): void
{
    app()->forgetInstance(ChannelManager::class);
    Notification::clearResolvedInstance(ChannelManager::class);
    $manager = app(ChannelManager::class);
    $manager->extend('mail', fn () => new class
    {
        public function send(): never
        {
            throw new RuntimeException('transport down');
        }
    });
    Notification::swap($manager);
}

it('mails ten events in five minutes once, with one inbox item of count ten (A-4)', function (): void {
    [$request, $approver] = mailSetup();

    foreach (range(0, 9) as $i) {
        at(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC')->addSeconds($i * 25)->format('Y-m-d H:i:s'));
        $outcome = Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1'])->outcomes[0]->mail;
        expect($outcome)->toBe($i === 0 ? MailOutcome::Pending : MailOutcome::Deduplicated);
    }

    Notification::assertSentToTimes($approver, TypedMailNotification::class, 1);

    expect(InboxItem::query()->sole()->count)->toBe(10)
        ->and(delivery()->events_count)->toBe(10)
        ->and(delivery()->status)->toBe(DeliveryStatus::Sent);
});

it('folds later events into a mail that has not gone out yet', function (): void {
    config()->set('notifications.queue.enabled', true);
    config()->set('queue.default', 'database');
    Queue::fake();
    [$request, $approver] = mailSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    $second = Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'B'], actor: $request->author);

    expect($second->outcomes[0]->mail)->toBe(MailOutcome::Folded)
        ->and(delivery()->events_count)->toBe(2)
        ->and(delivery()->data)->toBe(['reference' => 'B'])
        ->and(delivery()->actor_id)->toBe($request->author_id);

    Queue::assertPushed(DeliverMailJob::class, 1);
});

it('says how many more updates a folded mail carries', function (): void {
    config()->set('notifications.mail.strategy', 'trailing');
    [$request, $approver] = mailSetup();

    foreach (range(1, 3) as $i) {
        Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'R'.$i]);
    }

    Notification::assertNothingSent();

    at('2026-11-09 10:05:00');
    Notifications::sweep();

    Notification::assertSentTo($approver, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'Request R3 awaits your approval (3)'
        && in_array('And 2 more updates.', $n->message->introLines, true));
});

it('mails a trailing type once after the window with the final count (C-49)', function (): void {
    Notifications::define('trail.type')->subject(Post::class)
        ->window('PT10M')->mailStrategy(MailStrategy::Trailing)
        ->title(fn (NotificationPayload $p): string => 'Trail '.$p->count)->register();
    $user = user();
    $post = post();

    Notifications::send('trail.type', $post, $user);
    at('2026-11-09 10:09:59');
    Notifications::send('trail.type', $post, $user);
    Notifications::sweep();
    Notification::assertNothingSent();

    at('2026-11-09 10:10:00');
    Notifications::sweep();
    Notifications::sweep();

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
    Notification::assertSentTo($user, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'Trail 2');
});

it('ignores trailing for an urgent type', function (): void {
    Notifications::define('urgent.trail')->priority(Priority::Urgent)->window('PT10M')->mailStrategy(MailStrategy::Trailing)
        ->title(fn (): string => 'Now')->register();
    $user = user();

    Notifications::send('urgent.trail', null, $user);

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
});

it('holds mail during quiet hours until they end (A-5)', function (): void {
    Event::fake([NotificationMailHeld::class, NotificationMailSent::class]);
    [$request, $approver] = mailSetup(['timezone' => 'Europe/Bratislava']);
    Notifications::preferences($approver)->quietHours('22:00', '07:00');

    at('2026-11-09 22:30:00');
    $result = Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    expect($result->outcomes[0]->mail)->toBe(MailOutcome::Held)
        ->and(delivery()->send_after->format('Y-m-d H:i'))->toBe('2026-11-10 06:00');
    Notification::assertNothingSent();

    at('2026-11-10 05:59:00');
    Notifications::sweep();
    Notification::assertNothingSent();

    at('2026-11-10 06:00:00');
    Notifications::sweep();

    Notification::assertSentToTimes($approver, TypedMailNotification::class, 1);
    Event::assertDispatched(NotificationMailHeld::class);
    Event::assertDispatched(NotificationMailSent::class);
});

it('lets urgent types bypass quiet hours (A-5)', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('00:00', '23:59');

    expect(Notifications::send('security.alert', null, $user)->outcomes[0]->mail)->toBe(MailOutcome::Pending);
    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
});

it('holds a mandatory type that is not urgent (C-05b)', function (): void {
    Notifications::define('mandatory.normal')->mandatory()->title(fn (): string => 'x')->register();
    $user = user();
    Notifications::preferences($user)->quietHours('00:00', '23:59');

    expect(Notifications::send('mandatory.normal', null, $user)->outcomes[0]->mail)->toBe(MailOutcome::Held);
});

it('holds again when quiet hours started between the send and the delivery', function (): void {
    config()->set('notifications.mail.strategy', 'trailing');
    [$request, $approver] = mailSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::preferences($approver)->quietHours('10:03', '11:00');

    at('2026-11-09 10:05:00');
    Notifications::sweep();

    expect(delivery()->status)->toBe(DeliveryStatus::Held)
        ->and(delivery()->send_after->format('H:i'))->toBe('11:00');
    Notification::assertNothingSent();
});

it('sends nothing more for a retried or re-dispatched job (C-23, C-27)', function (): void {
    $user = user();
    Notifications::send('security.alert', null, $user);

    $job = new DeliverMailJob(delivery()->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    Notifications::sweep();
    Notifications::sweep();

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
});

it('cancels a delivery whose type is gone (C-24)', function (): void {
    config()->set('notifications.queue.enabled', true);
    config()->set('queue.default', 'database');
    Queue::fake();
    Notifications::send('security.alert', null, user());

    app(TypeRegistry::class)->flush();
    config()->set('notifications.types', []);
    app()->call([new DeliverMailJob(delivery()->id), 'handle']);

    expect(delivery()->status)->toBe(DeliveryStatus::Cancelled)
        ->and(delivery()->cancel_reason)->toBe(CancelReason::UnknownType)
        ->and(delivery()->open_key)->toBeNull();
});

it('fails a stale sending claim instead of re-sending it (C-25)', function (): void {
    Event::fake([NotificationMailFailed::class]);
    $delivery = NotificationDelivery::factory()->recipient(user())->status(DeliveryStatus::Sending)->create(['claimed_at' => now()->subMinutes(11), 'type' => 'security.alert']);

    expect(Notifications::sweep()->expired)->toBe(1)
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->fresh()->cancel_reason)->toBe(CancelReason::Unknown);

    Notification::assertNothingSent();
    Event::assertDispatched(NotificationMailFailed::class, fn (NotificationMailFailed $e): bool => $e->reason === CancelReason::Unknown);
});

it('re-checks the recipient, the subject, access and mutes before sending (C-17)', function (Closure $change, CancelReason $reason): void {
    [$request, $approver] = mailSetup();
    Notifications::preferences($approver)->quietHours('09:00', '11:00');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    $change($request, $approver);
    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(delivery()->status)->toBe(DeliveryStatus::Cancelled)->and(delivery()->cancel_reason)->toBe($reason);
    Notification::assertNothingSent();
})->with([
    'lost access' => [fn ($request, $approver) => DB::table('company_user')->where('user_id', $approver->id)->delete(), CancelReason::NoAccess],
    'muted meanwhile' => [fn ($request, $approver) => Notifications::preferences($approver)->mute('request.awaiting_approval'), CancelReason::Muted],
    'subject deleted' => [fn ($request) => $request->delete(), CancelReason::SubjectGone],
    'recipient deleted' => [fn ($request, $approver) => $approver->delete(), CancelReason::RecipientGone],
]);

it('retries a transport failure with backoff, then fails it', function (): void {
    Event::fake([NotificationMailFailed::class]);
    failingMail();
    $user = user();

    Notifications::send('security.alert', null, $user);

    expect(delivery()->status)->toBe(DeliveryStatus::Pending)
        ->and(delivery()->attempts)->toBe(1)
        ->and(delivery()->send_after->format('H:i:s'))->toBe('10:01:00');

    at('2026-11-09 10:01:00');
    Notifications::sweep();
    expect(delivery()->send_after->format('H:i:s'))->toBe('10:06:00');

    at('2026-11-09 10:06:00');
    Notifications::sweep();

    expect(delivery()->status)->toBe(DeliveryStatus::Failed)
        ->and(delivery()->cancel_reason)->toBe(CancelReason::Transport)
        ->and(delivery()->attempts)->toBe(3);
    Event::assertDispatched(NotificationMailFailed::class, fn (NotificationMailFailed $e): bool => $e->reason === CancelReason::Transport);
});

it('fails a mail whose type cannot render, without retrying', function (): void {
    Notifications::define('broken.render')->title(fn (): string => 'ok')->body(fn (): string => throw new RuntimeException('render'))->register();

    Notifications::send('broken.render', null, user());

    expect(delivery()->status)->toBe(DeliveryStatus::Failed)
        ->and(delivery()->cancel_reason)->toBe(CancelReason::Render);
});

it('makes a recipient without an address undeliverable, never a fatal', function (): void {
    $user = user(['email' => null]);

    Notifications::send('security.alert', null, $user);

    expect(delivery()->status)->toBe(DeliveryStatus::Undeliverable)
        ->and(delivery()->cancel_reason)->toBe(CancelReason::NoAddress);
});

it('makes a recipient that cannot route mail undeliverable', function (): void {
    $plain = PlainAccount::query()->create(['name' => 'Plain']);

    Notifications::send('security.alert', null, $plain);

    expect(delivery()->status)->toBe(DeliveryStatus::Undeliverable);
});

it('routes through a configured address resolver, in the recipient\'s locale', function (): void {
    app()->instance(FixedAddress::class, new FixedAddress('routed@example.test'));
    config()->set('notifications.mail.address_resolver', FixedAddress::class);
    $user = user(['locale' => 'sk']);

    Notifications::send('security.alert', null, $user, ['what' => 'prihlásenie']);

    Notification::assertSentOnDemand(TypedMailNotification::class, fn (TypedMailNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'routed@example.test'
        && $n->locale === 'sk');
    expect(delivery()->status)->toBe(DeliveryStatus::Sent);
});

it('is undeliverable when the address resolver finds nothing, and fails on a bad resolver', function (): void {
    app()->instance(FixedAddress::class, new FixedAddress(null));
    config()->set('notifications.mail.address_resolver', FixedAddress::class);
    Notifications::send('security.alert', null, user());

    expect(delivery()->status)->toBe(DeliveryStatus::Undeliverable);

    config()->set('notifications.mail.address_resolver', stdClass::class);
    Notifications::send('security.alert', null, user());

    expect(NotificationDelivery::query()->latest('id')->first()->cancel_reason)->toBe(CancelReason::Render);
});

it('mails each recipient in their locale (A-8)', function (): void {
    $english = user(['locale' => 'en', 'name' => 'Ann']);
    $slovak = user(['locale' => 'sk', 'name' => 'Jana']);

    Notifications::send('security.alert', null, [$english, $slovak], ['what' => 'login']);

    Notification::assertSentTo($english, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'New notification from Roundly'
        && $n->message->greeting === 'Hello Ann,');
    Notification::assertSentTo($slovak, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'Nové upozornenie z Roundly'
        && $n->message->greeting === 'Dobrý deň, Jana,'
        && in_array('Túto správu dostávate, pretože sa týka vášho účtu a nemožno ju vypnúť.', $n->message->outroLines, true));

    InboxItem::query()->each(fn (InboxItem $item) => expect($item->title)->toBe($item->recipient_id === $slovak->id ? 'Bezpečnostné upozornenie: login' : 'Security alert: login'));
});

it('sends a minimal mail for a sensitive subject (C-47)', function (): void {
    $user = user();

    Notifications::send('security.alert', null, $user, ['ip' => '10.0.0.1']);

    Notification::assertSentTo($user, TypedMailNotification::class, function (TypedMailNotification $n): bool {
        $text = json_encode([$n->message->subject, $n->message->introLines, $n->message->outroLines]);

        return ! str_contains($text, '10.0.0.1') && ! str_contains($text, 'Security alert')
            && $n->message->actionUrl === 'https://app.test/account/security';
    });
});

it('sends the full mail for a sensitive subject when minimal mail is off', function (): void {
    config()->set('notifications.mail.minimal_for_sensitive', false);
    $user = user();

    Notifications::send('security.alert', null, $user, ['ip' => '10.0.0.1', 'what' => 'login']);

    Notification::assertSentTo($user, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'Security alert: login'
        && in_array('Secret detail: 10.0.0.1', $n->message->introLines, true));
});

it('never renders a javascript url (C-45)', function (): void {
    Notifications::define('evil.url')->title(fn (): string => 'x')->url(fn (): string => 'javascript:alert(1)')->register();
    $user = user();

    Notifications::send('evil.url', null, $user);

    Notification::assertSentTo($user, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->actionUrl === null);
    expect(InboxItem::query()->sole()->url)->toBeNull();
});

it('escapes user text in the mail (C-46)', function (): void {
    [$request, $approver] = mailSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => '<b>PR</b>', 'note' => '<script>alert(1)</script>']);

    Notification::assertSentTo($approver, TypedMailNotification::class, function (TypedMailNotification $n): bool {
        $html = (string) $n->message->render();

        return str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && ! str_contains($html, '<script>alert(1)</script>');
    });
});

it('links the preferences page by url or route name, except on mandatory types', function (): void {
    Route::get('/settings/notifications', fn () => 'ok')->name('settings.notifications');
    app('router')->getRoutes()->refreshNameLookups();
    config()->set('notifications.mail.preferences_url', 'settings.notifications');
    [$request, $approver] = mailSetup();

    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    Notification::assertSentTo($approver, TypedMailNotification::class, fn (TypedMailNotification $n): bool => in_array('Manage your notifications: http://localhost/settings/notifications', $n->message->outroLines, true));

    config()->set('notifications.mail.preferences_url', 'ftp://nope');
    Notifications::send('request.awaiting_approval', purchaseRequest(company: $request->company, reference: 'B'), $approver, ['reference' => 'B']);

    Notification::assertSentTo($approver, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->outroLines === []);
});

it('runs the mail inline after the commit when queues are off', function (): void {
    config()->set('notifications.queue.enabled', false);
    $user = user();

    DB::transaction(function () use ($user): void {
        Notifications::send('security.alert', null, $user);
        Notification::assertNothingSent();
    });

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
});

it('dispatches the mail job on the configured connection and queue', function (): void {
    config()->set('notifications.queue.connection', 'redis');
    config()->set('notifications.queue.queue', 'mail');
    Queue::fake();

    Notifications::send('security.alert', null, user());

    Queue::assertPushedOn('mail', DeliverMailJob::class, fn (DeliverMailJob $job): bool => $job->connection === 'redis');
});

/**
 * A fixed-address resolver for the address seam.
 */
final class FixedAddress implements ResolvesMailAddress
{
    public function __construct(private ?string $address) {}

    public function address(Model $recipient, ?NotificationPayload $payload): ?string
    {
        return $this->address;
    }
}
