<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Exceptions\MandatoryNotificationException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\DigestMailNotification;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\VendorThingHappened;
use RoundlyConsulting\Options\Facades\Options;

/**
 * The owner's acceptance cases (plan §12, A-1 … A-9), run against the test app: three types —
 * `security.alert` (mandatory), `comment.posted` (digestible, defined fluently) and
 * `contract.expiring` (date reminders) — plus `request.awaiting_approval` and a mapped
 * third-party notification.
 */
beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');
});

it('a_muted_type_sends_no_email_but_keeps_the_inbox_item (A-1)', function (): void {
    $user = user();
    Notifications::preferences($user)->mute('comment.posted');

    $result = Notifications::send('comment.posted', post(), $user, ['title' => 'Hi']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::Created)
        ->and($result->outcomes[0]->mail)->toBe(MailOutcome::Muted)
        ->and(Notifications::inbox($user)->unreadCount())->toBe(1);
    Notification::assertNothingSent();
});

it('a_mandatory_type_cannot_be_muted (A-2)', function (): void {
    Notifications::preferences(user())->mute('security.alert');
})->throws(MandatoryNotificationException::class);

it('a_stored_mute_of_a_now_mandatory_type_is_ignored (A-2)', function (): void {
    $user = user();
    Options::set(ChannelPreferences::class, ['types' => ['security.alert' => ['mail' => false]]], $user);

    Notifications::send('security.alert', null, $user);

    Notification::assertSentTo($user, TypedMailNotification::class);
});

it('a_recipient_who_lost_access_gets_nothing (A-3)', function (): void {
    $approver = user();
    $request = purchaseRequest();

    $result = Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::NoAccess)->and(InboxItem::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('a_recipient_who_lost_access_gets_nothing_queued_mail (A-3)', function (): void {
    config()->set('notifications.mail.strategy', 'trailing');
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    DB::table('company_user')->where('user_id', $approver->id)->delete();
    at('2026-11-09 10:05:00');
    Notifications::sweep();

    expect(NotificationDelivery::query()->sole()->cancel_reason)->toBe(CancelReason::NoAccess);
    Notification::assertNothingSent();
});

it('a_recipient_who_lost_access_gets_nothing_held_mail (A-3)', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::preferences($approver)->quietHours('09:00', '11:00');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);

    DB::table('company_user')->where('user_id', $approver->id)->delete();
    at('2026-11-09 11:00:00');
    Notifications::sweep();

    expect(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Cancelled);
    Notification::assertNothingSent();
});

it('a_recipient_who_lost_access_gets_nothing_digest_entry (A-3)', function (): void {
    $reader = user();
    Notifications::preferences($reader)->digest(DigestFrequency::Daily);
    $post = post();
    Notifications::send('comment.posted', $post, $reader, ['title' => 'A']);

    $post->forceFill(['private' => true])->save();
    at('2026-11-10 08:00:00');
    Notifications::sweep();

    Notification::assertNothingSent();
});

it('a_recipient_who_lost_access_gets_nothing_pending_reminder (A-3)', function (): void {
    $contract = contract(expiresAt: CarbonImmutable::parse('2027-02-17 10:00:00', 'UTC'));
    DB::table('company_user')->where('user_id', $contract->owner_id)->delete();

    at('2026-12-19 10:00:00');
    Notifications::sweep();

    expect(InboxItem::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('a_recipient_who_lost_access_gets_nothing_escalation (A-3)', function (): void {
    $approver = user();
    $director = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver')->addMember($director, 'director');

    Notifications::type('request.awaiting_approval')->about($request)->to($approver)->with(['reference' => 'A'])
        ->escalate(after: 'P1D', to: $director)
        ->send();

    DB::table('company_user')->where('user_id', $director->id)->delete();
    at('2026-11-10 10:00:00');
    Notifications::sweep();

    Notification::assertNotSentTo($director, TypedMailNotification::class);
    expect(InboxItem::query()->where('recipient_id', $director->id)->count())->toBe(0);
});

it('ten_events_in_five_minutes_make_one_mail_and_one_item_with_count_ten (A-4)', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');

    foreach (range(0, 9) as $i) {
        at(CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC')->addSeconds($i * 29)->format('Y-m-d H:i:s'));
        Notifications::send('request.awaiting_approval', $request, Recipients::role($request->company, 'approver'), ['reference' => 'PR-1']);
    }

    Notification::assertSentToTimes($approver, TypedMailNotification::class, 1);
    expect(InboxItem::query()->sole()->count)->toBe(10);
});

it('quiet_hours_hold_mail_until_they_end (A-5)', function (): void {
    $approver = user(['timezone' => 'Europe/Bratislava']);
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::preferences($approver)->quietHours('22:00', '07:00');

    at('2026-11-09 22:00:00');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'A']);
    Notifications::sweep();
    Notification::assertNothingSent();

    at('2026-11-10 06:00:00');
    Notifications::sweep();
    Notification::assertSentTo($approver, TypedMailNotification::class);
});

it('urgent_types_bypass_quiet_hours (A-5)', function (): void {
    $user = user();
    Notifications::preferences($user)->quietHours('00:00', '23:59');

    Notifications::send('security.alert', null, $user);

    Notification::assertSentTo($user, TypedMailNotification::class);
});

it('a_digest_batches_low_priority_items_once_per_period (A-6)', function (): void {
    $reader = user();
    Notifications::preferences($reader)->digest(DigestFrequency::Daily);

    foreach (range(1, 3) as $i) {
        Notifications::send('comment.posted', post(), $reader, ['title' => 'Post '.$i]);
    }

    at('2026-11-10 08:00:00');
    Notifications::sweep();
    Notifications::sweep();

    Notification::assertSentToTimes($reader, DigestMailNotification::class, 1);
    expect(Notifications::inbox($reader)->unreadCount())->toBe(3);
});

it('a_reminder_moves_when_the_date_changes (A-7)', function (): void {
    $contract = contract(expiresAt: CarbonImmutable::parse('2027-02-17 10:00:00', 'UTC'));

    $contract->update(['expires_at' => CarbonImmutable::parse('2027-03-19 10:00:00', 'UTC')]);

    expect(Notifications::for($contract)->reminders()->pending()->map(fn (NotificationReminder $r): string => $r->due_at->format('Y-m-d'))->all())
        ->toBe(['2027-01-18', '2027-02-17', '2027-03-12']);
});

it('reminders_are_cancelled_when_the_subject_is_deleted (A-7)', function (): void {
    $contract = contract();

    $contract->delete();

    expect(Notifications::for($contract)->reminders()->pending())->toHaveCount(0);
});

it('each_recipient_gets_mail_in_their_locale (A-8)', function (): void {
    $english = user(['locale' => 'en']);
    $slovak = user(['locale' => 'sk']);

    Notifications::send('security.alert', null, [$english, $slovak]);

    Notification::assertSentTo($english, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'New notification from Roundly');
    Notification::assertSentTo($slovak, TypedMailNotification::class, fn (TypedMailNotification $n): bool => $n->message->subject === 'Nové upozornenie z Roundly');
});

describe('a mapped third-party notification (A-9)', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance(ChannelManager::class);
        Notification::clearResolvedInstance(ChannelManager::class);
        Notification::swap(app(ChannelManager::class));
        config()->set('notifications.interop.map', [VendorThingHappened::class => 'vendor.thing']);
    });

    it('a_mapped_package_notification_respects_mutes', function (): void {
        $user = user();
        Notifications::preferences($user)->mute('vendor.thing');

        $user->notify(new VendorThingHappened(post()));

        expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('a_mapped_package_notification_respects_mandatory', function (): void {
        Notifications::define('vendor.mandatory')->mandatory()->subject(Post::class)
            ->access(fn (): bool => true)->title(fn (): string => 'x')->register();
        config()->set('notifications.interop.map', [VendorThingHappened::class => 'vendor.mandatory']);
        $user = user();
        Options::set(ChannelPreferences::class, ['types' => ['vendor.mandatory' => ['mail' => false]]], $user);

        $user->notify(new VendorThingHappened(post()));

        expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
    });

    it('a_mapped_package_notification_respects_dedup', function (): void {
        $user = user();
        $post = post();

        $user->notify(new VendorThingHappened($post));
        $user->notify(new VendorThingHappened($post));

        expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
    });

    it('a_suppressed_notification_is_never_sent', function (): void {
        config()->set('notifications.interop.suppress', [VendorThingHappened::class]);

        user()->notify(new VendorThingHappened(post()));

        expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
            ->and(InboxItem::query()->count())->toBe(0);
    });
});
