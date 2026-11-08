<?php

declare(strict_types=1);

use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Notifications\Actions\Digests\SendDigestAction;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Events\DigestSent;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Jobs\SendDigestJob;
use RoundlyConsulting\Notifications\Mail\DigestMailNotification;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 06:00:00');
});

function digestReader(DigestFrequency $frequency = DigestFrequency::Daily, array $attributes = []): User
{
    $reader = user($attributes);
    Notifications::preferences($reader)->digest($frequency);

    return $reader;
}

it('batches low-priority items into one digest per period, the inbox at once (A-6, C-06b)', function (): void {
    Event::fake([DigestSent::class]);
    $reader = digestReader();
    $posts = [post(), post(), post()];

    foreach ($posts as $i => $post) {
        $result = Notifications::send('comment.posted', $post, $reader, ['title' => 'Post '.$i]);
        expect($result->outcomes[0]->mail)->toBe(MailOutcome::Digest)
            ->and($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created);
    }

    expect(Notifications::inbox($reader)->unreadCount())->toBe(3);
    Notifications::sweep();
    Notification::assertNothingSent();

    at('2026-11-09 08:00:00');
    Notifications::sweep();

    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
    Notification::assertSentTo($reader, DigestMailNotification::class, function (DigestMailNotification $n): bool {
        $html = (string) $n->message->render();

        return $n->count === 3 && $n->message->subject === 'Your daily summary: 3 updates'
            && str_contains($html, '1 new comment on Post 0') && str_contains($html, '1 new comment on Post 2');
    });

    expect(DigestRun::query()->sole()->status)->toBe(DigestRunStatus::Sent)
        ->and(DigestRun::query()->sole()->items_count)->toBe(3)
        ->and(NotificationDelivery::query()->where('status', DeliveryStatus::Sent)->count())->toBe(3);
    Event::assertDispatched(DigestSent::class, fn (DigestSent $e): bool => $e->count === 3);
});

it('sends nothing on a second sweep or a retried job (C-06, C-07)', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);

    at('2026-11-09 08:00:00');
    Notifications::sweep();
    Notifications::sweep();
    app()->call([new SendDigestJob(DigestRun::query()->sole()->id), 'handle']);

    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
});

it('mails immediately when the user has no digest', function (): void {
    $reader = user();

    expect(Notifications::send('comment.posted', post(), $reader, ['title' => 'A'])->outcomes[0]->mail)->toBe(MailOutcome::Pending);
});

it('sends a weekly digest on the configured day and time', function (): void {
    config()->set('notifications.digest.weekly_day', 'wednesday');
    $reader = digestReader(DigestFrequency::Weekly);
    Notifications::preferences($reader)->digest(DigestFrequency::Weekly, '07:30');

    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);

    expect(NotificationDelivery::query()->sole()->send_after->format('Y-m-d H:i'))->toBe('2026-11-11 07:30')
        ->and(NotificationDelivery::query()->sole()->digest_period)->toBe('2026-W46');

    at('2026-11-11 07:30:00');
    Notifications::sweep();

    Notification::assertSentTo($reader, DigestMailNotification::class, fn (DigestMailNotification $n): bool => $n->message->subject === 'Your weekly summary: 1 update');
});

it('rolls a late item into the next digest (C-50)', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);
    at('2026-11-09 08:00:00');
    Notifications::sweep();

    // An item parked for the period that already went out.
    NotificationDelivery::factory()->recipient($reader)->status(DeliveryStatus::Digest)->create([
        'type' => 'comment.posted',
        'digest_frequency' => DigestFrequency::Daily,
        'digest_period' => '2026-11-09',
        'send_after' => now()->subMinute(),
    ]);

    Notifications::sweep();

    $late = NotificationDelivery::query()->where('status', DeliveryStatus::Digest)->sole();

    expect($late->digest_period)->toBe('2026-11-10')
        ->and($late->digest_run_id)->toBeNull();
    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
});

it('sends no digest when every entry lost access or was muted (C-51)', function (): void {
    $reader = digestReader();
    $post = post();
    Notifications::send('comment.posted', $post, $reader, ['title' => 'A']);
    Notifications::send('comment.posted', post(), $reader, ['title' => 'B']);

    $post->forceFill(['private' => true])->save();
    Notifications::preferences($reader)->mute('comment.posted');

    at('2026-11-09 08:00:00');
    Notifications::sweep();

    Notification::assertNothingSent();
    expect(DigestRun::query()->sole()->status)->toBe(DigestRunStatus::Empty)
        ->and(NotificationDelivery::query()->pluck('cancel_reason')->all())->toEqualCanonicalizing([CancelReason::NoAccess, CancelReason::Muted]);
});

it('drops an entry whose subject or type is gone', function (): void {
    $reader = digestReader();
    $post = post();
    Notifications::send('comment.posted', $post, $reader, ['title' => 'A']);
    Notifications::send('comment.posted', post(), $reader, ['title' => 'B']);
    $post->delete();
    NotificationDelivery::factory()->recipient($reader)->status(DeliveryStatus::Digest)->create([
        'type' => 'gone.type', 'digest_frequency' => DigestFrequency::Daily, 'digest_period' => '2026-11-09', 'send_after' => now(),
    ]);

    at('2026-11-09 08:00:00');
    Notifications::sweep();

    Notification::assertSentTo($reader, DigestMailNotification::class, fn (DigestMailNotification $n): bool => $n->count === 1);
    expect(NotificationDelivery::query()->whereNotNull('cancel_reason')->pluck('cancel_reason')->all())->toEqualCanonicalizing([CancelReason::SubjectGone, CancelReason::UnknownType]);
});

it('flushes pending items as one final digest when digests are switched off (C-52)', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);
    Notifications::send('comment.posted', post(), $reader, ['title' => 'B']);

    at('2026-11-09 06:30:00');
    Notifications::preferences($reader)->digest(DigestFrequency::Off);

    expect(NotificationDelivery::query()->pluck('digest_period')->unique()->all())->toBe(['off-202611090630']);

    Notifications::sweep();
    Notifications::sweep();

    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
    Notification::assertSentTo($reader, DigestMailNotification::class, fn (DigestMailNotification $n): bool => $n->count === 2);
    expect(Notifications::send('comment.posted', post(), $reader, ['title' => 'C'])->outcomes[0]->mail)->toBe(MailOutcome::Pending);
});

it('moves pending items to the new slot when the frequency changes', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);

    Notifications::preferences($reader)->digest(DigestFrequency::Weekly);

    expect(NotificationDelivery::query()->sole()->digest_frequency)->toBe(DigestFrequency::Weekly)
        ->and(NotificationDelivery::query()->sole()->digest_period)->toBe('2026-W46');
});

it('fails a crashed digest run and its entries once (C-77)', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);
    $run = DigestRun::factory()->recipient($reader)->create(['period' => '2026-11-09', 'status' => DigestRunStatus::Sending, 'claimed_at' => now()]);
    NotificationDelivery::query()->update(['digest_run_id' => $run->id, 'digest_period' => '2026-11-09']);

    at('2026-11-09 08:16:00');
    Notifications::sweep();
    Notifications::sweep();

    expect($run->fresh()->status)->toBe(DigestRunStatus::Failed)
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::Unknown);
    Notification::assertNothingSent();
});

it('retries a digest whose send failed, then fails it with its entries', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);
    app()->forgetInstance(ChannelManager::class);
    Notification::clearResolvedInstance(ChannelManager::class);
    $manager = app(ChannelManager::class);
    $manager->extend('mail', fn () => new class
    {
        public function send(): never
        {
            throw new RuntimeException('down');
        }
    });
    Notification::swap($manager);

    at('2026-11-09 08:00:00');
    Notifications::sweep();
    expect(DigestRun::query()->sole()->status)->toBe(DigestRunStatus::Pending);

    Notifications::sweep();
    Notifications::sweep();

    expect(DigestRun::query()->sole()->status)->toBe(DigestRunStatus::Failed)
        ->and(DigestRun::query()->sole()->attempts)->toBe(3)
        ->and(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::Transport);
});

it('ends a run whose recipient is gone or cannot be mailed', function (): void {
    $gone = digestReader();
    $noAddress = digestReader(attributes: ['email' => null]);
    Notifications::send('comment.posted', post(), [$gone, $noAddress], ['title' => 'A']);
    $gone->delete();

    at('2026-11-09 08:00:00');
    Notifications::sweep();

    expect(DigestRun::query()->orderBy('id')->pluck('status')->all())->toBe([DigestRunStatus::Empty, DigestRunStatus::Failed]);
});

it('shows a sensitive item as the minimal line in the digest (C-48)', function (): void {
    Notifications::define('secret.low')->priority(Priority::Low)->digestible()->subject(Post::class)
        ->sensitive(fn (): bool => true)
        ->title(fn (NotificationPayload $p): string => 'Salary of '.$p->data['name'])->register();
    $reader = digestReader();

    Notifications::send('secret.low', post(), $reader, ['name' => 'Ann']);
    at('2026-11-09 08:00:00');
    Notifications::sweep();

    Notification::assertSentTo($reader, DigestMailNotification::class, function (DigestMailNotification $n): bool {
        $html = (string) $n->message->render();

        return ! str_contains($html, 'Salary') && str_contains($html, 'You have a new notification in Roundly');
    });
});

it('caps the digest at max_items and says how many more, grouped by context', function (): void {
    config()->set('notifications.digest.max_items', 2);
    config()->set('notifications.mail.preferences_url', 'https://app.test/settings');
    $reader = digestReader(attributes: ['locale' => 'sk']);

    foreach (range(1, 4) as $i) {
        Notifications::send('comment.posted', post(), $reader, ['title' => 'P'.$i], context: $i % 2 === 0 ? 'Blog' : null);
    }

    at('2026-11-09 08:00:00');
    Notifications::sweep();

    Notification::assertSentTo($reader, DigestMailNotification::class, function (DigestMailNotification $n): bool {
        $html = (string) $n->message->render();

        return $n->message->subject === 'Denný prehľad: 4 aktualizácie' && str_contains($html, 'A ešte 2 položky vo vašej schránke.')
            && str_contains($html, 'Blog') && str_contains($html, 'https://app.test/settings');
    });
});

it('previews the digest a user would get, without sending it', function (): void {
    $reader = digestReader();
    expect(Notifications::digests()->preview($reader))->toBeNull();

    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);

    expect(Notifications::digests()->preview($reader)?->subject)->toBe('Your daily summary: 1 update');
    Notifications::preferences($reader)->digest(DigestFrequency::Off);
    expect(Notifications::digests()->preview($reader)?->subject)->toBe('Your daily summary: 1 update');
    Notification::assertNothingSent();
});

it('falls back to the type label when a digest line throws', function (): void {
    Notifications::registerType(new class extends NotificationType
    {
        public function key(): string
        {
            return 'digest.broken';
        }

        public function label(): string
        {
            return 'Broken digest type';
        }

        public function priority(): Priority
        {
            return Priority::Low;
        }

        public function digestible(): bool
        {
            return true;
        }

        public function window(): string
        {
            return 'PT10M';
        }

        public function title(NotificationPayload $payload): string
        {
            return 'Broken';
        }

        public function digestLine(NotificationPayload $payload): string
        {
            throw new RuntimeException('cannot render');
        }
    });
    $reader = digestReader();

    Notifications::send('digest.broken', null, $reader);
    Notifications::send('digest.broken', null, $reader);

    $html = (string) Notifications::digests()->preview($reader)?->render();

    expect($html)->toContain('Broken digest type (2)');
});

it('sends a digest whose job waited in the queue longer than the digest lease', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);
    Queue::fake([SendDigestJob::class]);

    at('2026-11-09 08:00:01');
    Notifications::sweep();
    at('2026-11-09 08:16:02');
    Notifications::sweep();

    $run = DigestRun::query()->sole();
    expect($run->status)->toBe(DigestRunStatus::Sending)
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Digest);

    app(SendDigestAction::class)->execute($run->id);

    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
    expect($run->fresh()?->status)->toBe(DigestRunStatus::Sent);
});

it('dispatches a waiting digest again after the lease, and sends it once', function (): void {
    $reader = digestReader();
    Notifications::send('comment.posted', post(), $reader, ['title' => 'A']);
    Queue::fake([SendDigestJob::class]);

    at('2026-11-09 08:00:01');
    Notifications::sweep();
    at('2026-11-09 08:05:00');
    Notifications::sweep();
    Queue::assertPushed(SendDigestJob::class, 1);

    at('2026-11-09 08:16:02');
    Notifications::sweep();
    Queue::assertPushed(SendDigestJob::class, 2);

    $run = DigestRun::query()->sole();
    app(SendDigestAction::class)->execute($run->id);
    app(SendDigestAction::class)->execute($run->id);

    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
});
