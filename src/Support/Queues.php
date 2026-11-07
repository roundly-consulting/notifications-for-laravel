<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

/**
 * Dispatches the package's jobs on the configured connection and queue — or inline when
 * `notifications.queue.enabled` is off. Always after the surrounding commit.
 *
 * @internal
 */
final class Queues
{
    public static function dispatch(object $job): void
    {
        $connection = (new (NotificationModels::inboxItem()))->getConnection();

        $connection->afterCommit(static function () use ($job): void {
            if (! NotificationsConfig::queueEnabled()) {
                dispatch_sync($job);

                return;
            }

            if (method_exists($job, 'onConnection') && method_exists($job, 'onQueue')) {
                $job->onConnection(NotificationsConfig::queueConnection());
                $job->onQueue(NotificationsConfig::queueName());
            }

            dispatch($job);
        });
    }
}
