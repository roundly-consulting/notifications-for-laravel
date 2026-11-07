<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Contracts\HasNotificationTimezone;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Events\NotificationPreferenceChanged;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\Exceptions\InvalidQuietHoursException;
use RoundlyConsulting\Notifications\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\Notifications\Exceptions\MandatoryNotificationException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Options\Facades\Options;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

it('mutes mail only by default: the inbox keeps the item (A-1)', function (): void {
    $user = user();
    $post = post();
    $user->notificationPreferences()->mute('comment.posted');

    $result = Notifications::send('comment.posted', $post, $user, ['title' => 'Hi']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and($result->outcomes[0]->mail)->toBe(MailOutcome::Muted)
        ->and(Notifications::preferences($user)->muted('comment.posted'))->toBeTrue()
        ->and(Notifications::preferences($user)->enabled('comment.posted', Channel::Inbox))->toBeTrue();
});

it('lets the inbox be muted only when configured (C-39)', function (): void {
    config()->set('notifications.preferences.inbox_mutable', true);
    $user = user();
    Notifications::preferences($user)->mute('comment.posted');

    $result = Notifications::send('comment.posted', post(), $user, ['title' => 'Hi']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Muted)
        ->and($result->mail(MailOutcome::Muted))->toHaveCount(1);
});

it('cannot mute a mandatory type (A-2)', function (Closure $mute): void {
    $user = user();

    expect(fn () => $mute(Notifications::preferences($user)))->toThrow(MandatoryNotificationException::class, 'The notification "security.alert" is required and cannot be switched off.');
})->with([
    'mute' => [fn ($prefs) => $prefs->mute('security.alert')],
    'set off' => [fn ($prefs) => $prefs->set('security.alert', Channel::Mail, false)],
]);

it('ignores a stored mute of a now-mandatory type (A-2)', function (): void {
    $user = user();
    Options::set(ChannelPreferences::class, ['types' => ['security.alert' => ['mail' => false, 'inbox' => false]]], $user);

    $result = Notifications::send('security.alert', null, $user);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and($result->outcomes[0]->mail)->toBe(MailOutcome::Pending);
});

it('keeps mandatory types through a category mute (C-02b)', function (): void {
    $user = user();
    Notifications::preferences($user)->muteCategory('security')->muteCategory('comments');

    expect(Notifications::send('security.alert', null, $user)->outcomes[0]->mail)->toBe(MailOutcome::Pending)
        ->and(Notifications::send('comment.posted', post(), $user, ['title' => 'x'])->outcomes[0]->mail)->toBe(MailOutcome::Muted);

    Notifications::preferences($user)->unmuteCategory('comments');

    expect(Notifications::preferences($user)->muted('comment.posted'))->toBeFalse();
});

it('takes defaults from the type, so a changed default reaches untouched users (C-37)', function (): void {
    $untouched = user();
    $touched = user();
    Notifications::preferences($touched)->set('request.awaiting_approval', Channel::Mail, true);

    Notifications::define('opt.in')->optIn(Channel::Mail)->title(fn (): string => 'x')->register();

    expect(Notifications::preferences($untouched)->enabled('opt.in', Channel::Mail))->toBeFalse()
        ->and(Notifications::preferences($untouched)->enabled('request.awaiting_approval', Channel::Mail))->toBeTrue()
        ->and(Preferences::channelMap($untouched))->toBe(['types' => [], 'categories' => []])
        ->and(Preferences::channelMap($touched)['types'])->toBe(['request.awaiting_approval' => ['mail' => true]]);
});

it('prefers a type override over a category override', function (): void {
    $user = user();
    Notifications::preferences($user)->muteCategory('comments')->set('comment.posted', Channel::Mail, true);

    expect(Notifications::preferences($user)->enabled('comment.posted', Channel::Mail))->toBeTrue();

    Notifications::preferences($user)->unmute('comment.posted');

    expect(Notifications::preferences($user)->enabled('comment.posted', Channel::Mail))->toBeFalse()
        ->and(Notifications::preferences($user)->enabled('comment.posted', Channel::Inbox))->toBeTrue();
});

it('drops overrides of unregistered types on the next write', function (): void {
    $user = user();
    Options::set(ChannelPreferences::class, ['types' => ['gone.type' => ['mail' => false], 'x' => 'junk'], 'categories' => 'junk'], $user);

    Notifications::preferences($user)->mute('comment.posted');

    expect(Preferences::channelMap($user)['types'])->toBe(['comment.posted' => ['mail' => false]]);
});

it('resets every override to the type defaults', function (): void {
    Event::fake([NotificationPreferenceChanged::class]);
    $user = user();
    Notifications::preferences($user)->mute('comment.posted')->muteCategory('requests')->reset();

    expect(Preferences::channelMap($user))->toBe(['types' => [], 'categories' => []]);
    Event::assertDispatched(NotificationPreferenceChanged::class, fn (NotificationPreferenceChanged $event): bool => $event->scope === 'reset');
    Event::assertDispatched(NotificationPreferenceChanged::class, fn (NotificationPreferenceChanged $event): bool => $event->scope === 'type' && $event->new === ['comment.posted' => ['mail' => false]]);
});

it('lists a translated matrix with locked channels', function (): void {
    $user = user();
    Notifications::preferences($user)->mute('comment.posted');

    $rows = collect(Notifications::preferences($user)->matrix())->keyBy(fn ($row) => $row->type->key);

    $comment = collect($rows['comment.posted']->channels)->keyBy(fn ($c) => $c->channel->value);
    $alert = collect($rows['security.alert']->channels)->keyBy(fn ($c) => $c->channel->value);

    expect($comment['mail']->enabled)->toBeFalse()
        ->and($comment['mail']->default)->toBeTrue()
        ->and($comment['mail']->locked)->toBeFalse()
        ->and($comment['inbox']->locked)->toBeTrue()
        ->and($alert['mail']->locked)->toBeTrue();
});

it('refuses an unknown type in a preference write', function (): void {
    Notifications::preferences(user())->mute('nope');
})->throws(UnknownNotificationTypeException::class);

it('keeps both of two concurrent mutes (C-38)', function (): void {
    $user = user();
    $read = Preferences::channelMap($user);

    Notifications::preferences($user)->mute('comment.posted');
    Notifications::preferences($user)->mute('request.awaiting_approval');

    expect(array_keys(Preferences::channelMap($user)['types']))->toBe(['comment.posted', 'request.awaiting_approval'])
        ->and($read)->toBe(['types' => [], 'categories' => []]);
});

it('keeps both mutes inside a host transaction, releasing the lock after its commit', function (): void {
    $user = user();

    DB::transaction(function () use ($user): void {
        Notifications::preferences($user)->mute('comment.posted');
        Notifications::preferences($user)->mute('request.awaiting_approval');
    });

    expect(array_keys(Preferences::channelMap($user)['types']))->toBe(['comment.posted', 'request.awaiting_approval']);
});

it('works with options authorization on (C-40)', function (): void {
    config()->set('options.authorization.enabled', true);
    $user = user();

    Notifications::preferences($user)->mute('comment.posted')->digest(DigestFrequency::Weekly, '07:30');

    expect(Notifications::preferences($user)->muted('comment.posted'))->toBeTrue()
        ->and(Notifications::preferences($user)->digestPreference()->frequency)->toBe(DigestFrequency::Weekly)
        ->and(Notifications::preferences($user)->digestPreference()->time)->toBe('07:30');
});

it('refuses a lock store without atomic locks', function (): void {
    Cache::extend('plain', fn () => Cache::repository(new class implements Store
    {
        public function get($key): mixed
        {
            return null;
        }

        public function many(array $keys): array
        {
            return [];
        }

        public function put($key, $value, $seconds): bool
        {
            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return false;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return false;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }
    }));
    config()->set('cache.stores.nolocks', ['driver' => 'plain']);
    config()->set('notifications.preferences.lock_store', 'nolocks');

    Notifications::preferences(user())->mute('comment.posted');
})->throws(InvalidNotificationsConfigurationException::class, 'notifications.preferences.lock_store');

it('validates quiet hours and timezones', function (Closure $write, string $exception, string $message): void {
    expect(fn () => $write(Notifications::preferences(user())))->toThrow($exception, $message);
})->with([
    'same start and end' => [fn ($p) => $p->quietHours('22:00', '22:00'), InvalidQuietHoursException::class, 'different times'],
    'bad time' => [fn ($p) => $p->quietHours('25:00', '07:00'), InvalidQuietHoursException::class, '"25:00" is not a time'],
    'bad digest time' => [fn ($p) => $p->digest(DigestFrequency::Daily, '8am'), InvalidQuietHoursException::class, '"8am"'],
    'bad timezone' => [fn ($p) => $p->timezone('Mars/Olympus'), InvalidTimezoneException::class, '"Mars/Olympus" is not a timezone.'],
]);

it('stores, reads and clears quiet hours, timezone and digest', function (): void {
    $user = user();
    $prefs = Notifications::preferences($user)->quietHours('22:00', '07:00')->timezone('Europe/Bratislava')->digest(DigestFrequency::Daily);

    expect($prefs->quietHoursPreference()?->start)->toBe('22:00')
        ->and($prefs->resolvedTimezone())->toBe('Europe/Bratislava')
        ->and($prefs->overview()->timezone)->toBe('Europe/Bratislava')
        ->and($prefs->overview()->digest->frequency)->toBe(DigestFrequency::Daily);

    $prefs->quietHours(null)->timezone(null);

    expect($prefs->quietHoursPreference())->toBeNull()
        ->and($prefs->resolvedTimezone())->toBe('UTC');
});

it('uses the configured default quiet hours, and none when switched off', function (): void {
    config()->set('notifications.quiet_hours.default', ['start' => '21:00', 'end' => '06:00']);
    $user = user();

    expect(Notifications::preferences($user)->quietHoursPreference()?->end)->toBe('06:00');

    config()->set('notifications.quiet_hours.enabled', false);

    expect(Notifications::preferences($user)->quietHoursPreference())->toBeNull();
});

it('refuses invalid default quiet hours, naming the key', function (mixed $value): void {
    config()->set('notifications.quiet_hours.default', $value);

    Notifications::preferences(user())->quietHoursPreference();
})->with([
    'not an array' => ['22:00-07:00'],
    'same times' => [['start' => '22:00', 'end' => '22:00']],
    'bad time' => [['start' => '22', 'end' => '07:00']],
])->throws(InvalidNotificationsConfigurationException::class, 'notifications.quiet_hours.default');

it('resolves the timezone from the contract, the preference, the attribute, then the app (C-41)', function (): void {
    config()->set('app.timezone', 'America/New_York');
    $resolver = app(ResolvesTimezone::class);

    $plain = user();
    $withAttribute = user(['timezone' => 'Asia/Tokyo']);
    $invalidAttribute = user(['timezone' => 'Not/AZone']);
    $withPreference = user(['timezone' => 'Asia/Tokyo']);
    Notifications::preferences($withPreference)->timezone('Europe/Bratislava');

    $contract = new class extends User implements HasNotificationTimezone
    {
        protected $table = 'users';

        public function notificationTimezone(): ?string
        {
            return 'Australia/Sydney';
        }
    };
    $contract->forceFill(['name' => 'C'])->save();

    expect($resolver->timezone($plain))->toBe('America/New_York')
        ->and($resolver->timezone($withAttribute))->toBe('Asia/Tokyo')
        ->and($resolver->timezone($invalidAttribute))->toBe('America/New_York')
        ->and($resolver->timezone($invalidAttribute))->toBe('America/New_York')
        ->and($resolver->timezone($withPreference))->toBe('Europe/Bratislava')
        ->and($resolver->timezone($contract))->toBe('Australia/Sydney');

    config()->set('notifications.timezone.attribute', '');

    expect($resolver->timezone($withAttribute))->toBe('America/New_York');

    config()->set('app.timezone', 'Nope/Nope');

    expect($resolver->timezone($plain))->toBe('UTC');
});

it('delivers for a strict-mode host without timezone or name attributes (C-86)', function (): void {
    Model::preventAccessingMissingAttributes();
    $user = User::query()->select(['id', 'email'])->find(user()->id);

    try {
        $result = Notifications::send('security.alert', null, $user);
        Notifications::deliveries()->dispatchDue();
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Sent);
});

it('re-times held mail when quiet hours change (C-44)', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('09:00', '18:00');

    Notifications::send('request.awaiting_approval', $request = purchaseRequest(company: $company = company()), tap($user, fn ($u) => $company->addMember($u, 'approver')), ['reference' => 'A']);

    expect(NotificationDelivery::query()->sole()->send_after->format('H:i'))->toBe('18:00');

    Notifications::preferences($user)->quietHours('09:00', '12:00');
    expect(NotificationDelivery::query()->sole()->send_after->format('H:i'))->toBe('12:00');

    Notifications::preferences($user)->timezone('Asia/Tokyo');
    expect(NotificationDelivery::query()->sole()->send_after->format('Y-m-d H:i'))->toBe('2026-11-09 10:00');

    Notifications::preferences($user)->quietHours(null);
    expect(NotificationDelivery::query()->sole()->send_after->format('Y-m-d H:i'))->toBe('2026-11-09 10:00');
});

it('recovers the preference lock after a host transaction rolled back', function (): void {
    $user = user();

    try {
        DB::transaction(function () use ($user): void {
            Notifications::preferences($user)->mute('comment.posted');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Notifications::preferences($user)->mute('request.awaiting_approval');

    expect(array_keys(Preferences::channelMap($user)['types']))->toBe(['request.awaiting_approval']);
});
