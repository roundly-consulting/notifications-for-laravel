<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Actions\Interop\ApplyInteropRulesAction;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Events\NotificationInteropFailed;
use RoundlyConsulting\Notifications\Exceptions\InvalidInteropMappingException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\Exceptions\MandatoryNotificationException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Http\Resources\InboxItemResource;
use RoundlyConsulting\Notifications\Listeners\ApplyNotificationRules;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\Doctor;
use RoundlyConsulting\Notifications\Support\InteropRules;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Guest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\HostVendorThingHappened;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\OneTimeCode;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\PlainVendorNotice;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\UnserializableNotice;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\VendorThingHappened;

beforeEach(function (): void {
    at('2026-11-09 10:00:00');
    config()->set('notifications.interop.map', [VendorThingHappened::class => 'vendor.thing']);
});

/**
 * @return list<string> the subjects of the mails the array mailer captured
 */
function mailedSubjects(): array
{
    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    return array_values(array_map(fn ($message): string => (string) $message->getOriginalMessage()->getSubject(), iterator_to_array($messages)));
}

it('applies a mute to a mapped notification: no mail, the inbox keeps it (A-9)', function (): void {
    $user = user();
    Notifications::preferences($user)->mute('vendor.thing');

    $user->notify(new VendorThingHappened(post()));

    expect(mailedSubjects())->toBe([])
        ->and(InboxItem::query()->sole()->title)->toBe('Vendor: happened')
        ->and(DB::table('notifications')->count())->toBe(0);
});

it('cannot mute a mapped mandatory type (A-9)', function (): void {
    Notifications::define('vendor.mandatory')->mandatory()->title(fn (): string => 'x')->register();
    Notifications::interop()->map(PlainVendorNotice::class, 'vendor.mandatory');
    $user = user();

    expect(fn () => Notifications::preferences($user)->mute('vendor.mandatory'))->toThrow(MandatoryNotificationException::class);

    $user->notify(new PlainVendorNotice);

    expect(mailedSubjects())->toBe(['Notice: notice']);
});

it('de-duplicates mapped mail within the type\'s window (A-9)', function (): void {
    $user = user();
    $post = post();

    $user->notify(new VendorThingHappened($post, 'one'));
    at('2026-11-09 10:10:00');
    $user->notify(new VendorThingHappened($post, 'two'));

    expect(mailedSubjects())->toBe(['Vendor: one'])
        ->and(NotificationDelivery::query()->sole()->events_count)->toBe(2)
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(InboxItem::query()->sole()->count)->toBe(2);

    at('2026-11-09 10:15:00');
    $user->notify(new VendorThingHappened($post, 'three'));

    expect(mailedSubjects())->toBe(['Vendor: one', 'Vendor: three']);
});

it('never sends a suppressed notification, on every channel or the listed ones (A-9)', function (): void {
    $user = user();
    config()->set('notifications.interop.suppress', [VendorThingHappened::class]);

    $user->notify(new VendorThingHappened(post()));

    expect(mailedSubjects())->toBe([])->and(InboxItem::query()->count())->toBe(0);

    config()->set('notifications.interop.suppress', [VendorThingHappened::class => ['mail']]);
    $user->notify(new VendorThingHappened(post()));

    expect(mailedSubjects())->toBe([])->and(InboxItem::query()->count())->toBe(1);

    Notifications::interop()->suppress(PlainVendorNotice::class);
    (new Guest(['email' => 'g@example.test']))->notify(new PlainVendorNotice);

    expect(mailedSubjects())->toBe([])
        ->and(Notifications::interop()->suppressed())->toHaveKey(PlainVendorNotice::class);
});

it('maps a host subclass of a mapped notification (C-80)', function (): void {
    $user = user();
    Notifications::preferences($user)->mute('vendor.thing');

    $user->notify(new HostVendorThingHappened(post()));

    expect(mailedSubjects())->toBe([]);

    // An exact entry beats the ancestor.
    Notifications::define('vendor.host')->subject(Post::class)->access(fn (): bool => true)->title(fn (): string => 'Host')->register();
    Notifications::interop()->map(HostVendorThingHappened::class, 'vendor.host');
    $user->notify(new HostVendorThingHappened(post()));

    expect(mailedSubjects())->toBe(['Vendor: happened'])
        ->and(Notifications::interop()->mapped())->toHaveKey(HostVendorThingHappened::class);
});

it('leaves on-demand routes alone (C-62) and non-recipient notifiables too (C-85)', function (): void {
    Notification::route('mail', 'anon@example.test')->notify(new VendorThingHappened(post()));
    $guest = Guest::query()->create(['email' => 'guest@example.test']);
    $guest->notify(new VendorThingHappened(post()));

    expect(mailedSubjects())->toBe(['Vendor: happened', 'Vendor: happened'])
        ->and(NotificationDelivery::query()->count())->toBe(0)
        ->and(InboxItem::query()->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('treats listed notifiable classes as recipients', function (): void {
    config()->set('notifications.interop.notifiables', [Guest::class]);
    Notifications::define('vendor.guest')->subject(Post::class)->access(fn (): bool => true)->subjectFrom(fn ($n) => $n->post)->title(fn (): string => 'x')->register();
    Notifications::interop()->map(VendorThingHappened::class, 'vendor.guest');
    $guest = Guest::query()->create(['email' => 'guest@example.test']);

    $guest->notify(new VendorThingHappened(post()));

    expect(NotificationDelivery::query()->count())->toBe(1)->and(InboxItem::query()->count())->toBe(1);
});

it('refuses a mapped mail when the recipient lost access to its subject', function (): void {
    $user = user();

    $user->notify(new VendorThingHappened(post(private: true)));

    expect(mailedSubjects())->toBe([])->and(InboxItem::query()->count())->toBe(0);
});

it('applies preferences but neither access nor de-dup without a subject (C-63)', function (): void {
    Notifications::define('vendor.notice')->subject(Post::class)->window('PT15M')->title(fn (): string => 'Notice')->register();
    Notifications::interop()->map(PlainVendorNotice::class, 'vendor.notice');
    $user = user();

    $user->notify(new PlainVendorNotice('one'));
    $user->notify(new PlainVendorNotice('two'));

    expect(mailedSubjects())->toBe(['Notice: one', 'Notice: two']);

    Notifications::preferences($user)->mute('vendor.notice');
    $user->notify(new PlainVendorNotice('three'));

    expect(mailedSubjects())->toHaveCount(2);
});

it('redirects, keeps or doubles the database channel as configured', function (string $mode, int $inbox, int $table): void {
    config()->set('notifications.interop.database', $mode);

    user()->notify(new VendorThingHappened(post()));

    expect(InboxItem::query()->count())->toBe($inbox)
        ->and(DB::table('notifications')->count())->toBe($table);
})->with([
    'redirect' => ['redirect', 1, 0],
    'keep' => ['keep', 0, 1],
    'both' => ['both', 1, 1],
]);

it('renders a redirected item from the notification\'s own title', function (): void {
    $user = user();
    $user->notify(new VendorThingHappened(post(), 'renamed'));

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    expect((new InboxItemResource(InboxItem::query()->sole()))->resolve($request))
        ->toMatchArray(['title' => 'Vendor: renamed', 'data' => ['title' => 'Vendor: renamed', 'post' => Post::query()->sole()->id]]);
});

it('holds mapped mail in quiet hours and releases it after (A-9)', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '11:00');

    $user->notify(new VendorThingHappened(post()));

    expect(mailedSubjects())->toBe([])
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Held)
        ->and(NotificationDelivery::query()->sole()->payload)->not->toContain('VendorThingHappened');

    at('2026-11-09 11:00:00');
    Notifications::sweep();
    Notifications::sweep();

    expect(mailedSubjects())->toBe(['Vendor: happened'])
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Sent);
});

it('re-checks access and mutes when releasing a held mapped mail', function (Closure $change, CancelReason $reason): void {
    $user = user();
    $post = post();
    Notifications::preferences($user)->quietHours('09:00', '11:00');
    $user->notify(new VendorThingHappened($post));

    $change($user, $post);
    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(mailedSubjects())->toBe([])
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe($reason);
})->with([
    'lost access' => [fn ($user, $post) => $post->forceFill(['private' => true])->save(), CancelReason::NoAccess],
    'muted' => [fn ($user) => Notifications::preferences($user)->mute('vendor.thing'), CancelReason::Muted],
    'type gone' => [function (): void {
        config()->set('notifications.interop.map', []);
        config()->set('notifications.types', []);
        app(TypeRegistry::class)->flush();
    }, CancelReason::UnknownType],
]);

it('fails a tampered held payload instead of unserializing it (C-64)', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '11:00');
    $user->notify(new VendorThingHappened(post()));

    NotificationDelivery::query()->update(['payload' => base64_encode('O:8:"stdClass":0:{}')]);

    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(mailedSubjects())->toBe([])
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::Payload);
});

it('fails a held payload that decrypts to something other than a notification', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '11:00');
    $user->notify(new VendorThingHappened(post()));
    NotificationDelivery::query()->update(['payload' => Crypt::encryptString(serialize(new stdClass))]);

    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::Payload);
});

it('sends an unserializable notification now instead of holding it (C-65)', function (): void {
    Notifications::define('vendor.plain')->title(fn (): string => 'x')->register();
    Notifications::interop()->map(PlainVendorNotice::class, 'vendor.plain');
    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '11:00');

    $user->notify(new UnserializableNotice);

    expect(mailedSubjects())->toBe(['Notice: closure']);
});

it('refuses to map a time-critical notification to a type that could hold, fold or digest it (C-66)', function (string $type): void {
    Notifications::interop()->map(OneTimeCode::class, $type);

    app(InteropRules::class)->validate(app(TypeRegistry::class), force: true);
})->with([
    'not urgent' => ['request.awaiting_approval'],
    'urgent with a window' => ['urgent.windowed'],
])->throws(InvalidInteropMappingException::class, 'is time-critical');

it('refuses a mapping to an unknown type, and checks it lazily on first use', function (): void {
    Event::fake([NotificationInteropFailed::class]);
    Notifications::interop()->map(PlainVendorNotice::class, 'not.registered');

    $user = user();
    $user->notify(new PlainVendorNotice);

    // Fail-open: the error is reported and the notification goes out untouched.
    expect(mailedSubjects())->toBe(['Notice: notice']);
    Event::assertDispatched(NotificationInteropFailed::class, fn (NotificationInteropFailed $e): bool => $e->notification === 'PlainVendorNotice'
        && $e->exception === 'InvalidInteropMappingException' && $e->channel === 'mail');
});

it('still sends a second one-time code within a minute (C-66b)', function (): void {
    Notifications::interop()->map(OneTimeCode::class, 'security.alert');
    $user = user();

    $user->notify(new OneTimeCode('111111'));
    $user->notify(new OneTimeCode('222222'));

    expect(mailedSubjects())->toBe(['Your code 111111', 'Your code 222222']);
});

it('cannot fold a password reset by mapping an ancestor class', function (): void {
    Notifications::define('vendor.any')->window('PT15M')->title(fn (): string => 'Any')->register();
    Notifications::interop()->map(Illuminate\Notifications\Notification::class, 'vendor.any');
    app(InteropRules::class)->validate(app(TypeRegistry::class), force: true);
    $user = user();

    $user->notify(new OneTimeCode('111111'));
    at('2026-11-09 10:02:00');
    $user->notify(new OneTimeCode('222222'));

    expect(mailedSubjects())->toBe(['Your code 111111', 'Your code 222222'])
        ->and(Notifications::interop()->mapped())->toHaveKey(Illuminate\Notifications\Notification::class);
    expect(collect(Doctor::run())->pluck('message')->filter(fn (string $m): bool => str_contains($m, 'time-critical'))->values()->all())
        ->toHaveCount(1);
});

it('lets the notification through untouched when the interop step throws (C-81)', function (): void {
    Event::fake([NotificationInteropFailed::class]);
    Notifications::define('boom.type')->subject(Post::class)->access(fn (): bool => throw new RuntimeException('boom'))->title(fn (): string => 'x')->register();
    Notifications::define('boom.wrap')->subjectFrom(fn () => post())->subject(Post::class)->access(fn (): bool => throw new RuntimeException('boom'))->title(fn (): string => 'x')->register();
    Notifications::interop()->map(PlainVendorNotice::class, 'boom.wrap');

    user()->notify(new PlainVendorNotice('safe'));

    expect(mailedSubjects())->toBe(['Notice: safe']);
    Event::assertDispatched(NotificationInteropFailed::class, fn (NotificationInteropFailed $e): bool => $e->exception === 'RuntimeException');
});

it('marks the interop delivery failed when the notification fails', function (): void {
    $user = user();
    $notification = new VendorThingHappened(post());
    $notification->id = (string) Str::uuid();
    NotificationDelivery::factory()->recipient($user)->status(DeliveryStatus::Sending)->create(['source' => 'interop', 'notification_id' => $notification->id, 'type' => 'vendor.thing']);

    event(new NotificationFailed($user, $notification, 'mail'));
    event(new NotificationFailed($user, $notification, 'database'));

    expect(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::Transport);
});

it('does not touch the package\'s own mail', function (): void {
    config()->set('notifications.interop.suppress', [TypedMailNotification::class]);
    $user = user();

    Notifications::send('security.alert', null, $user);

    expect(mailedSubjects())->toBe(['New notification from Roundly']);
});

it('never touches channels other than mail and database', function (): void {
    expect(app(ApplyInteropRulesAction::class)->execute(user(), new VendorThingHappened(post()), 'broadcast'))->toBeNull()
        ->and(app(ApplyNotificationRules::class)->handle(new NotificationSending('not-an-object', new PlainVendorNotice, 'mail')))->toBeNull();
});

it('validates the map after every provider booted, seeing types the host defines (C-73)', function (): void {
    Notifications::interop()->map(PlainVendorNotice::class, 'comment.posted');

    expect(fn () => app(InteropRules::class)->validate(app(TypeRegistry::class), force: true))->not->toThrow(Throwable::class)
        ->and(app(InteropRules::class)->active())->toBeTrue();

    app(InteropRules::class)->flush();
    config()->set('notifications.interop.map', []);

    expect(app(InteropRules::class)->active())->toBeFalse();
});

it('reads interop config strictly', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => [app(InteropRules::class)->mapped(), app(InteropRules::class)->suppressed()])
        ->toThrow(InvalidNotificationsConfigurationException::class, $key);
})->with([
    ['notifications.interop.map', 'nope'],
    ['notifications.interop.map', [VendorThingHappened::class => '']],
    ['notifications.interop.suppress', 'nope'],
    ['notifications.interop.suppress', [VendorThingHappened::class => 'mail']],
    ['notifications.interop.suppress', [VendorThingHappened::class => [1]]],
]);

beforeEach(function (): void {
    Notifications::define('urgent.windowed')->priority(Priority::Urgent)->window('PT5M')->title(fn (): string => 'x')->register();
});
