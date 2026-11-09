<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\UnsubscribeOutcome;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\InvalidUnsubscribeTokenException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\Doctor;
use RoundlyConsulting\Notifications\Support\Keys;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\UnsubscribeTokens;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    at('2026-11-09 10:00:00');
    URL::forceRootUrl('https://app.test');
    URL::forceScheme('https');
    Notifications::define('news.posted')->label('Product news')->title(fn (): string => 'News')->register();
});

/**
 * The mails the array transport took, as Symfony emails.
 *
 * @return list<Email>
 */
function sentEmails(): array
{
    $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    return array_values(array_filter(array_map(
        static fn (SentMessage $sent): mixed => $sent->getOriginalMessage(),
        $messages->all(),
    ), static fn (mixed $message): bool => $message instanceof Email));
}

function unsubscribeLink(Email $email): string
{
    $header = $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString() ?? '';

    expect($header)->toStartWith('<https://app.test/notifications/unsubscribe/')->toEndWith('>');

    return substr($header, 1, -1);
}

function unsubscribePath(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

it('adds List-Unsubscribe and the one-click header to a mutable type\'s mail', function (): void {
    $user = user();

    Notifications::send('news.posted', null, $user);

    $email = sentEmails()[0];
    $link = unsubscribeLink($email);

    expect($email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($link)->not->toContain('\\')
        ->and(rawurldecode($link))->not->toContain('User')
        ->and(rawurldecode($link))->not->toContain('news.posted');
});

it('adds no unsubscribe header to a mandatory type', function (): void {
    Notifications::send('security.alert', null, user(), ['what' => 'login']);

    $email = sentEmails()[0];

    expect($email->getHeaders()->has('List-Unsubscribe'))->toBeFalse()
        ->and($email->getHeaders()->has('List-Unsubscribe-Post'))->toBeFalse()
        ->and(Notifications::unsubscribe()->url(user(), 'security.alert'))->toBeNull();
});

it('adds only List-Unsubscribe when the link is not https, and the doctor warns', function (): void {
    URL::forceRootUrl('http://app.test');
    URL::forceScheme('http');

    Notifications::send('news.posted', null, user());

    $email = sentEmails()[0];

    expect($email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toStartWith('<http://app.test/notifications/unsubscribe/')
        ->and($email->getHeaders()->has('List-Unsubscribe-Post'))->toBeFalse()
        ->and(collect(Doctor::run())->pluck('message')->filter(fn (string $m): bool => str_contains($m, 'List-Unsubscribe'))->all())->toHaveCount(1);
});

it('unsubscribes with a one-click POST: no login, no CSRF, idempotent', function (): void {
    Event::fake([NotificationPreferenceChanged::class]);
    $user = user();
    Notifications::send('news.posted', null, $user);
    $path = unsubscribePath(unsubscribeLink(sentEmails()[0]));

    $this->post($path, ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('You will no longer receive emails about &quot;Product news&quot;.', false);

    $type = app(TypeRegistry::class)->find('news.posted');
    expect(Preferences::enabled($type, Channel::Mail, $user))->toBeFalse()
        ->and(Preferences::enabled($type, Channel::Inbox, $user))->toBeTrue()
        ->and(Notifications::preferences($user)->muted('news.posted'))->toBeTrue();
    Event::assertDispatchedTimes(NotificationPreferenceChanged::class, 1);

    $this->post($path)->assertOk()->assertSee('You already do not receive emails', false);
    Event::assertDispatchedTimes(NotificationPreferenceChanged::class, 1);
});

it('sends no more of that type\'s mail once unsubscribed', function (): void {
    $user = user();
    $url = Notifications::unsubscribe()->url($user, 'news.posted');

    $this->post(unsubscribePath((string) $url))->assertOk();
    Notifications::send('news.posted', null, $user);

    expect(sentEmails())->toBe([])
        ->and(NotificationDelivery::query()->get())->toHaveCount(0)
        ->and(Notifications::inbox($user)->unreadCount())->toBe(1);
});

it('shows a confirmation page on GET and changes nothing', function (): void {
    $user = user();
    $path = unsubscribePath((string) Notifications::unsubscribe()->url($user, 'news.posted'));

    $this->get($path)
        ->assertOk()
        ->assertSee('Stop emails about &quot;Product news&quot;?', false)
        ->assertSee('<form method="post">', false)
        ->assertSee('name="List-Unsubscribe" value="One-Click"', false);

    expect(Notifications::preferences($user)->muted('news.posted'))->toBeFalse();
});

it('renders the pages in the recipient\'s language', function (): void {
    $user = user(['locale' => 'sk']);
    $path = unsubscribePath((string) Notifications::unsubscribe()->url($user, 'news.posted'));

    $this->get($path)->assertOk()->assertSee('Odhlásiť sa')->assertSee('<html lang="sk">', false);
    $this->post($path)->assertOk()->assertSee('vám už nebudeme posielať');
});

it('refuses a tampered, foreign or expired link with 403', function (): void {
    config()->set('notifications.mail.list_unsubscribe.ttl', 'P1D');
    $user = user();
    $token = basename(unsubscribePath((string) Notifications::unsubscribe()->url($user, 'news.posted')));
    $foreign = rtrim(strtr((new Encrypter(str_repeat('x', 32), 'aes-256-cbc'))->encryptString('{"v":1}'), '+/', '-_'), '=');

    foreach ([substr($token, 0, -4).'AAAA', $foreign, 'not-a-token'] as $bad) {
        $this->post('/notifications/unsubscribe/'.$bad)->assertForbidden()->assertSee('invalid or has expired');
        $this->get('/notifications/unsubscribe/'.$bad)->assertForbidden();
    }

    at('2026-11-10 10:00:01');
    $this->post('/notifications/unsubscribe/'.$token)->assertForbidden();
    expect(Notifications::preferences($user)->muted('news.posted'))->toBeFalse();

    at('2026-11-10 10:00:00');
    $this->post('/notifications/unsubscribe/'.$token)->assertOk();
});

it('refuses a link whose payload is not ours', function (string $payload): void {
    $token = rtrim(strtr(Crypt::encryptString($payload), '+/', '-_'), '=');

    Notifications::unsubscribe()->claim($token);
})->with([
    'not json' => ['nope'],
    'another version' => ['{"v":2,"r":"x","i":1,"t":"news.posted","e":null}'],
    'a float id' => ['{"v":1,"r":"x","i":1.5,"t":"news.posted","e":null}'],
    'a string expiry' => ['{"v":1,"r":"x","i":1,"t":"news.posted","e":"soon"}'],
])->throws(InvalidUnsubscribeTokenException::class);

it('refuses an empty or oversized token', function (string $token): void {
    UnsubscribeTokens::read($token);
})->with(['', str_repeat('a', 2049), 'a+b'])->throws(InvalidUnsubscribeTokenException::class);

it('keeps a type that became mandatory switched on', function (): void {
    $user = user();
    $token = UnsubscribeTokens::issue($user, 'security.alert');

    $result = Notifications::unsubscribe()->redeem($token);

    expect($result->outcome)->toBe(UnsubscribeOutcome::Required)
        ->and($result->recipient?->is($user))->toBeTrue();
    $this->post('/notifications/unsubscribe/'.$token)->assertOk()->assertSee('cannot be switched off');
});

it('answers gone for a deleted recipient or an unregistered type', function (): void {
    $user = user();
    $gone = user();
    $deleted = UnsubscribeTokens::issue($gone, 'news.posted');
    $gone->delete();
    $unknown = UnsubscribeTokens::issue($user, 'no.longer.here');

    expect(Notifications::unsubscribe()->redeem($deleted)->outcome)->toBe(UnsubscribeOutcome::Gone)
        ->and(Notifications::unsubscribe()->redeem($unknown)->outcome)->toBe(UnsubscribeOutcome::Gone)
        ->and(Notifications::unsubscribe()->redeem($unknown)->type)->toBe('no.longer.here');
    $this->get('/notifications/unsubscribe/'.$deleted)->assertOk()->assertSee('nothing to unsubscribe');
    $this->post('/notifications/unsubscribe/'.$unknown)->assertOk()->assertSee('nothing to unsubscribe');
});

it('answers already-unsubscribed for a type that sends no mail', function (): void {
    Notifications::define('inbox.only')->channels(Channel::Inbox)->title(fn (): string => 'x')->register();
    $user = user();

    expect(Notifications::unsubscribe()->url($user, 'inbox.only'))->toBeNull()
        ->and(Notifications::unsubscribe()->redeem(UnsubscribeTokens::issue($user, 'inbox.only'))->outcome)->toBe(UnsubscribeOutcome::AlreadyUnsubscribed);
});

it('runs the same through the injected manager and reads a claim', function (): void {
    $user = user();
    $url = (string) app(NotificationsManager::class)->unsubscribe()->url($user, 'news.posted');
    $token = basename(unsubscribePath($url));

    $claim = app(NotificationsManager::class)->unsubscribe()->claim($token);
    $result = app(NotificationsManager::class)->unsubscribe()->redeem($token);

    expect($claim->recipient?->is($user))->toBeTrue()
        ->and($claim->type)->toBe('news.posted')
        ->and($result->outcome)->toBe(UnsubscribeOutcome::Unsubscribed)
        ->and($result->type)->toBe('news.posted');
});

it('keeps old links working after an app key rotation', function (): void {
    $user = user();
    $token = basename(unsubscribePath((string) Notifications::unsubscribe()->url($user, 'news.posted')));
    $old = config('app.key');

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
    config()->set('app.previous_keys', [$old]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    expect(Notifications::unsubscribe()->redeem($token)->outcome)->toBe(UnsubscribeOutcome::Unsubscribed);
});

it('records a redeem under the fake, and asserts it', function (): void {
    $user = user();
    $other = user();
    $token = UnsubscribeTokens::issue($user, 'news.posted');
    $fake = Notifications::fake();

    $fake->assertNothingUnsubscribed();
    $result = Notifications::unsubscribe()->redeem($token);

    expect($result->outcome)->toBe(UnsubscribeOutcome::Unsubscribed)
        ->and($result->recipient?->is($user))->toBeTrue()
        ->and(Notifications::preferences($user)->muted('news.posted'))->toBeFalse();
    Notifications::assertUnsubscribed();
    Notifications::assertUnsubscribed($user);
    Notifications::assertUnsubscribed($user, 'news.posted');

    expect(fn () => Notifications::assertUnsubscribed($other))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Notifications::assertUnsubscribed($user, 'security.alert'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Notifications::assertNothingUnsubscribed())->toThrow(ExpectationFailedException::class)
        ->and(fn () => Notifications::unsubscribe()->redeem('forged'))->toThrow(InvalidUnsubscribeTokenException::class);
});

it('fails assertUnsubscribed when nothing was redeemed', function (): void {
    Notifications::fake();

    Notifications::assertUnsubscribed();
})->throws(ExpectationFailedException::class, 'Expected an unsubscribe link to be redeemed, but none was.');

it('answers a busy preference lock with its status, so the client retries', function (): void {
    $user = user();
    $token = UnsubscribeTokens::issue($user, 'news.posted');
    config()->set('notifications.preferences.lock_seconds', 1);
    // The lock is timed on the real clock.
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    $lock = Cache::lock('notifications:prefs:'.Keys::digest(ModelRefs::identity($user)), 10);
    $lock->get();

    $this->post('/notifications/unsubscribe/'.$token)->assertStatus(423)->assertSee('Try again');

    $lock->release();
    expect(Notifications::preferences($user)->muted('news.posted'))->toBeFalse();
});

it('cancels the suppressed delivery reason distinctly from a mute', function (): void {
    expect(CancelReason::Suppressed->value)->toBe('suppressed')
        ->and(CancelReason::RateLimited->value)->toBe('rate_limited');
});

it('is a User link the app can also build for its own mail', function (): void {
    $user = user();

    expect(Notifications::unsubscribe()->url($user, 'news.posted'))->toStartWith('https://app.test/notifications/unsubscribe/')
        ->and($user)->toBeInstanceOf(User::class);
});
