<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Testing;

use Illuminate\Database\Eloquent\Model;
use Pest\Contracts\Plugins\Bootable;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Support\InboxQueries;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Pest expectations for host suites, registered automatically (composer `extra.pest.plugins`):
 *
 * ```php
 * expect($user)->toHaveUnreadInboxItems(1);
 * expect($user)->toHaveInboxItem('request.awaiting_approval', $request);
 * expect($user)->toHaveMuted('comment.posted');
 * ```
 */
final class NotificationExpectations implements Bootable
{
    private static bool $registered = false;

    public function boot(): void
    {
        self::register();
    }

    public static function register(): void
    {
        if (self::$registered || ! function_exists('expect')) {
            return;
        }

        self::$registered = true;

        expect()->extend('toHaveUnreadInboxItems', function (int $count): mixed {
            $recipient = $this->value;
            Assert::assertInstanceOf(Model::class, $recipient);

            $unread = InboxQueries::for($recipient)->whereNull('read_at')->whereNull('archived_at')->count();
            Assert::assertSame($count, $unread, sprintf('Expected %d unread inbox item(s), found %d.', $count, $unread));

            return $this;
        });

        expect()->extend('toHaveInboxItem', function (string $type, ?Model $subject = null): mixed {
            $recipient = $this->value;
            Assert::assertInstanceOf(Model::class, $recipient);

            $query = InboxQueries::for($recipient)->where('type', $type);

            if ($subject !== null) {
                $query->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
            }

            Assert::assertTrue($query->exists(), sprintf('Expected an inbox item of [%s], found none.', $type));

            return $this;
        });

        expect()->extend('toHaveMuted', function (string $type): mixed {
            $user = $this->value;
            Assert::assertInstanceOf(Model::class, $user);

            $definition = app(TypeRegistry::class)->find($type);
            Assert::assertFalse(Preferences::enabled($definition, Channel::Mail, $user), sprintf('Expected [%s] to be muted, but its mail is on.', $type));

            return $this;
        });
    }
}
