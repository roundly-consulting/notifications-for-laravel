<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Notifications\Contracts\ResolvesMailAddress;
use RoundlyConsulting\Notifications\Contracts\SuppressionList;
use RoundlyConsulting\Notifications\Contracts\ThrottlesMail;
use RoundlyConsulting\Notifications\DataTransferObjects\MailAttempt;
use RoundlyConsulting\Notifications\DataTransferObjects\MailRateLimit;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Events\NotificationMailSent;
use RoundlyConsulting\Notifications\Events\NotificationMailSuppressed;
use RoundlyConsulting\Notifications\Events\NotificationMailThrottled;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\DigestMailNotification;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\Support\DefaultMailThrottle;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\Doctor;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\MailSuppression;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\NullSuppressionList;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Mail\ListedSuppressions;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Mail\RecordingThrottle;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PlainAccount;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
    ListedSuppressions::$suppressed = [];
    ListedSuppressions::$asked = [];
    RecordingThrottle::$attempts = [];
    RecordingThrottle::$until = null;
    Notifications::define('news.posted')->title(fn (): string => 'News')->register();
});

function safetyDelivery(): NotificationDelivery
{
    return NotificationDelivery::query()->latest('id')->firstOrFail();
}

function recipientRateKey(Model $recipient): string
{
    return 'notifications:mail:recipient:'.Keys::digest(ModelRefs::identity($recipient));
}

function rateLimited(int $perRecipient = 1, string $whenLimited = 'defer'): void
{
    config()->set('notifications.mail.rate_limit.enabled', 'on');
    config()->set('notifications.mail.rate_limit.per_recipient.max', $perRecipient);
    config()->set('notifications.mail.rate_limit.when_limited', $whenLimited);
}

// Suppression ------------------------------------------------------------------------------

it('suppresses nothing by default', function (): void {
    expect(app(SuppressionList::class))->toBeInstanceOf(NullSuppressionList::class)
        ->and(app(SuppressionList::class)->isSuppressed('anyone@example.test'))->toBeFalse();
});

it('sends no typed mail to a suppressed address, and says so', function (): void {
    Event::fake([NotificationMailSuppressed::class, NotificationMailSent::class]);
    config()->set('notifications.mail.suppression_list', ListedSuppressions::class);
    $user = user(['email' => 'Bounced@Example.test']);
    ListedSuppressions::$suppressed = ['bounced@example.test'];

    Notifications::send('news.posted', null, $user);

    Notification::assertNothingSent();
    expect(safetyDelivery()->status)->toBe(DeliveryStatus::Undeliverable)
        ->and(safetyDelivery()->cancel_reason)->toBe(CancelReason::Suppressed)
        ->and(ListedSuppressions::$asked)->toBe(['Bounced@Example.test']);
    Event::assertDispatched(NotificationMailSuppressed::class, fn (NotificationMailSuppressed $e): bool => $e->recipient->is($user)
        && $e->addresses === ['Bounced@Example.test']
        && $e->delivery?->is(safetyDelivery()) === true
        && $e->digestRun === null);
    Event::assertNotDispatched(NotificationMailSent::class);
});

it('honours a suppression list a host binds in the container', function (): void {
    app()->bind(SuppressionList::class, ListedSuppressions::class);
    $user = user();
    ListedSuppressions::$suppressed = [(string) $user->email];

    Notifications::send('news.posted', null, $user);

    Notification::assertNothingSent();
    expect(safetyDelivery()->cancel_reason)->toBe(CancelReason::Suppressed);
});

it('checks the address a configured resolver returns', function (): void {
    config()->set('notifications.mail.suppression_list', ListedSuppressions::class);
    config()->set('notifications.mail.address_resolver', SafetyFixedAddress::class);
    ListedSuppressions::$suppressed = ['fixed@example.test'];

    Notifications::send('news.posted', null, user());

    expect(ListedSuppressions::$asked)->toBe(['fixed@example.test'])
        ->and(safetyDelivery()->cancel_reason)->toBe(CancelReason::Suppressed);
});

it('still mails an address that is not suppressed', function (): void {
    config()->set('notifications.mail.suppression_list', ListedSuppressions::class);
    ListedSuppressions::$suppressed = ['someone-else@example.test'];
    $user = user();

    Notifications::send('news.posted', null, $user);

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
    expect(safetyDelivery()->status)->toBe(DeliveryStatus::Sent);
});

it('fails closed on a suppression list that is misconfigured', function (): void {
    config()->set('notifications.mail.suppression_list', PlainAccount::class);
    config()->set('notifications.mail.max_attempts', 1);

    Notifications::send('news.posted', null, user());

    Notification::assertNothingSent();
    expect(safetyDelivery()->status)->toBe(DeliveryStatus::Failed)
        ->and(safetyDelivery()->cancel_reason)->toBe(CancelReason::Transport);
    expect(fn () => app(SuppressionList::class))->toThrow(InvalidConfigurationException::class);
});

it('sends no digest to a suppressed address', function (): void {
    Event::fake([NotificationMailSuppressed::class]);
    config()->set('notifications.mail.suppression_list', ListedSuppressions::class);
    $reader = user();
    ListedSuppressions::$suppressed = [(string) $reader->email];
    at('2026-11-09 06:00:00');
    Notifications::preferences($reader)->digest(DigestFrequency::Daily);
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);

    at('2026-11-09 08:00:00');
    Notifications::sweep();

    Notification::assertNotSentTo($reader, DigestMailNotification::class);
    $run = DigestRun::query()->sole();
    expect($run->status)->toBe(DigestRunStatus::Failed)
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::Suppressed);
    Event::assertDispatched(NotificationMailSuppressed::class, fn (NotificationMailSuppressed $e): bool => $e->digestRun?->is($run) === true
        && $e->delivery === null && $e->addresses === [(string) $reader->email]);
});

it('reads every address a route can name', function (mixed $route, array $expected): void {
    $user = new class extends Model
    {
        public mixed $route = null;

        public function routeNotificationFor(string $driver): mixed
        {
            return $this->route;
        }
    };
    $user->route = $route;

    expect(MailSuppression::addresses($user, true))->toBe($expected);
})->with([
    'a string' => [' a@example.test ', ['a@example.test']],
    'a list' => [['a@example.test', 'b@example.test'], ['a@example.test', 'b@example.test']],
    'a map of address => name' => [['a@example.test' => 'A', 'b@example.test' => 'B'], ['a@example.test', 'b@example.test']],
    'objects with an email' => [[(object) ['email' => 'a@example.test'], (object) ['name' => 'x'], ''], ['a@example.test']],
    'nothing' => [null, []],
]);

it('reads no address from a recipient that cannot route mail, or an undeliverable one', function (): void {
    expect(MailSuppression::addresses(new PlainAccount, true))->toBe([])
        ->and(MailSuppression::addresses(user(), false))->toBe([])
        ->and(MailSuppression::addresses(new PlainAccount, ' x@example.test'))->toBe(['x@example.test']);
});

it('stops a whole mail when one of its addresses is suppressed', function (): void {
    app()->bind(SuppressionList::class, ListedSuppressions::class);
    ListedSuppressions::$suppressed = ['b@example.test'];
    $user = new class extends Model
    {
        public function routeNotificationFor(string $driver): mixed
        {
            return ['a@example.test', 'b@example.test'];
        }
    };

    expect(MailSuppression::suppressed($user, true))->toBe(['b@example.test']);
});

// Rate limit -------------------------------------------------------------------------------

it('never asks the throttle while the rate limit is off', function (): void {
    config()->set('notifications.mail.rate_limit.throttle', RecordingThrottle::class);
    RecordingThrottle::$until = CarbonImmutable::parse('2026-11-09 11:00:00', 'UTC');
    $user = user();

    Notifications::send('news.posted', null, $user);

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
    expect(RecordingThrottle::$attempts)->toBe([])
        ->and(NotificationsConfig::rateLimitEnabled())->toBeFalse();
});

it('defers mail over the per-recipient limit until it frees up', function (): void {
    Event::fake([NotificationMailThrottled::class, NotificationMailSent::class]);
    rateLimited(perRecipient: 1);
    $user = user();

    Notifications::send('news.posted', null, $user);
    Notifications::send('news.posted', null, $user);

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
    $deferred = safetyDelivery();
    expect($deferred->status)->toBe(DeliveryStatus::Pending)
        ->and($deferred->attempts)->toBe(0)
        ->and($deferred->claimed_at)->toBeNull()
        ->and($deferred->send_after?->format('Y-m-d H:i:s'))->toBe('2026-11-09 11:00:00');
    Event::assertDispatched(NotificationMailThrottled::class, fn (NotificationMailThrottled $e): bool => $e->deferred
        && $e->delivery->is($deferred) && $e->until->format('H:i:s') === '11:00:00');

    at('2026-11-09 10:59:59');
    Notifications::sweep();
    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);

    at('2026-11-09 11:00:00');
    Notifications::sweep();
    Notification::assertSentToTimes($user, TypedMailNotification::class, 2);
    expect($deferred->refresh()->status)->toBe(DeliveryStatus::Sent);
});

it('skips mail over the limit when told to', function (): void {
    Event::fake([NotificationMailThrottled::class]);
    rateLimited(perRecipient: 1, whenLimited: 'skip');
    $user = user();

    Notifications::send('news.posted', null, $user);
    Notifications::send('news.posted', null, $user);

    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
    expect(safetyDelivery()->status)->toBe(DeliveryStatus::Cancelled)
        ->and(safetyDelivery()->cancel_reason)->toBe(CancelReason::RateLimited);
    Event::assertDispatched(NotificationMailThrottled::class, fn (NotificationMailThrottled $e): bool => ! $e->deferred);
});

it('counts each recipient on their own', function (): void {
    rateLimited(perRecipient: 1);
    [$a, $b] = [user(), user()];

    Notifications::send('news.posted', null, [$a, $b]);

    Notification::assertSentToTimes($a, TypedMailNotification::class, 1);
    Notification::assertSentToTimes($b, TypedMailNotification::class, 1);
});

it('never limits an urgent type with the default throttle', function (): void {
    rateLimited(perRecipient: 1);
    $user = user();

    Notifications::send('security.alert', null, $user, ['what' => 'a']);
    Notifications::send('security.alert', null, $user, ['what' => 'b']);

    Notification::assertSentToTimes($user, TypedMailNotification::class, 2);
});

it('limits mail in one context across recipients', function (): void {
    rateLimited(perRecipient: 100);
    config()->set('notifications.mail.rate_limit.per_context.max', 1);
    [$a, $b, $c] = [user(), user(), user()];

    Notifications::send('news.posted', null, $a, context: 'workspace-1');
    Notifications::send('news.posted', null, $b, context: 'workspace-1');
    Notifications::send('news.posted', null, $c, context: 'workspace-2');
    Notifications::send('news.posted', null, $c);

    Notification::assertSentToTimes($a, TypedMailNotification::class, 1);
    Notification::assertNotSentTo($b, TypedMailNotification::class);
    Notification::assertSentToTimes($c, TypedMailNotification::class, 2);
});

it('asks a host throttle with the recipient, type and context', function (): void {
    config()->set('notifications.mail.rate_limit.enabled', 'on');
    config()->set('notifications.mail.rate_limit.throttle', RecordingThrottle::class);
    $user = user();

    Notifications::send('news.posted', null, $user, context: 'workspace-9');

    expect(RecordingThrottle::$attempts)->toHaveCount(1)
        ->and(RecordingThrottle::$attempts[0]->recipient->is($user))->toBeTrue()
        ->and(RecordingThrottle::$attempts[0]->type->key())->toBe('news.posted')
        ->and(RecordingThrottle::$attempts[0]->contextKey)->toBe(safetyDelivery()->context_key)
        ->and(RecordingThrottle::$attempts[0]->contextKey)->not->toBeNull()
        ->and(RecordingThrottle::$attempts[0]->deliveryId)->toBe(safetyDelivery()->id);
    Notification::assertSentToTimes($user, TypedMailNotification::class, 1);
});

it('defers at least a second when a host throttle answers a past time', function (): void {
    config()->set('notifications.mail.rate_limit.enabled', 'on');
    config()->set('notifications.mail.rate_limit.throttle', RecordingThrottle::class);
    RecordingThrottle::$until = CarbonImmutable::parse('2026-11-09 09:00:00', 'UTC');

    Notifications::send('news.posted', null, user());

    expect(safetyDelivery()->send_after?->format('Y-m-d H:i:s'))->toBe('2026-11-09 10:00:01');
});

it('counts increment-first, so the last slot is taken once and a refused mail counts nothing', function (): void {
    rateLimited(perRecipient: 2);
    $user = user();
    $type = app(TypeRegistry::class)->find('news.posted');
    $throttle = app(DefaultMailThrottle::class);
    $attempt = new MailAttempt($user, $type, null, 1);

    // Two workers racing for the last slot: the atomic increment lets exactly one through.
    $results = [$throttle->attempt($attempt), $throttle->attempt($attempt), $throttle->attempt($attempt)];

    expect($results[0])->toBeNull()
        ->and($results[1])->toBeNull()
        ->and($results[2]?->format('H:i:s'))->toBe('11:00:00');

    expect(app(RateLimiter::class)->attempts(recipientRateKey($user)))->toBe(2);
});

it('gives back the recipient count when the context cap refuses', function (): void {
    rateLimited(perRecipient: 5);
    config()->set('notifications.mail.rate_limit.per_context.max', 1);
    $type = app(TypeRegistry::class)->find('news.posted');
    $throttle = app(DefaultMailThrottle::class);
    $user = user();

    expect($throttle->attempt(new MailAttempt(user(), $type, 'ctx', 1)))->toBeNull()
        ->and($throttle->attempt(new MailAttempt($user, $type, 'ctx', 2)))->not->toBeNull()
        ->and($throttle->attempt(new MailAttempt($user, $type, null, 3)))->toBeNull();

    expect(app(RateLimiter::class)->attempts(recipientRateKey($user)))->toBe(1);
});

it('defers only a row that is still being sent', function (): void {
    Notification::fake();
    $user = user();
    Notifications::send('news.posted', null, $user);
    $sent = safetyDelivery();

    expect(DeliveryStates::defer($sent, CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC')))->toBeFalse()
        ->and($sent->refresh()->status)->toBe(DeliveryStatus::Sent);
});

it('validates the rate limit config', function (string $key, mixed $value): void {
    config()->set($key, $value);

    NotificationsConfig::rateLimitPerRecipient();
    NotificationsConfig::rateLimitWhenLimited();
})->with([
    ['notifications.mail.rate_limit.per_recipient.max', 0],
    ['notifications.mail.rate_limit.per_recipient.max', 'many'],
    ['notifications.mail.rate_limit.per_recipient.per', '1 hour'],
    ['notifications.mail.rate_limit.when_limited', 'drop'],
])->throws(PackageToolkitException::class);

it('turns a cap off with a blank max', function (): void {
    config()->set('notifications.mail.rate_limit.per_recipient.max', '');

    expect(NotificationsConfig::rateLimitPerRecipient())->toBeNull()
        ->and(NotificationsConfig::rateLimitPerContext())->toBeNull();

    config()->set('notifications.mail.rate_limit.per_context.max', '3');
    config()->set('notifications.mail.rate_limit.per_context.per', 'PT1M');

    expect(NotificationsConfig::rateLimitPerContext())->toEqual(new MailRateLimit(3, 60));
});

it('warns when the rate limit counts in a store other servers cannot see', function (): void {
    config()->set('notifications.mail.rate_limit.enabled', 'on');
    config()->set('cache.limiter', 'array');
    config()->set('cache.stores.array', ['driver' => 'array']);

    expect(collect(Doctor::run())->pluck('message')->filter(fn (string $m): bool => str_contains($m, 'mail rate limit'))->all())->toHaveCount(1);

    config()->set('notifications.mail.rate_limit.enabled', 'off');
    expect(collect(Doctor::run())->pluck('message')->filter(fn (string $m): bool => str_contains($m, 'mail rate limit'))->all())->toBeEmpty();
});

it('is bound through the throttle config key, and a bad one throws', function (): void {
    expect(app(ThrottlesMail::class))->toBeInstanceOf(DefaultMailThrottle::class);

    config()->set('notifications.mail.rate_limit.throttle', PlainAccount::class);

    expect(fn () => app(ThrottlesMail::class))->toThrow(InvalidConfigurationException::class);
});

/**
 * A host address resolver with one fixed address.
 */
final class SafetyFixedAddress implements ResolvesMailAddress
{
    public function address(Model $recipient, ?NotificationPayload $payload): string
    {
        return 'fixed@example.test';
    }
}

// List-Unsubscribe (off by default) ----------------------------------------------------------

it('adds no unsubscribe link and loads no unsubscribe route while the feature is off', function (): void {
    expect(NotificationsConfig::listUnsubscribeEnabled())->toBeFalse()
        ->and(Notifications::unsubscribe()->url(user(), 'news.posted'))->toBeNull()
        ->and(Route::has('notifications.unsubscribe'))->toBeFalse();

    Notifications::send('news.posted', null, $user = user());

    Notification::assertSentTo($user, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->callbacks === []);
});

it('validates the unsubscribe link lifetime', function (): void {
    expect(NotificationsConfig::listUnsubscribeTtl())->toBeNull();

    config()->set('notifications.mail.list_unsubscribe.ttl', 'P7D');
    expect(NotificationsConfig::listUnsubscribeTtl()?->totalDays)->toBe(7.0);

    config()->set('notifications.mail.list_unsubscribe.ttl', 'PT1M');
    NotificationsConfig::listUnsubscribeTtl();
})->throws(PackageToolkitException::class);
