<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\RecipientResolver;
use RoundlyConsulting\Notifications\Exceptions\InvalidRecipientException;
use RoundlyConsulting\Notifications\Recipients\ClosureRecipients;
use RoundlyConsulting\Notifications\Recipients\GroupRecipients;
use RoundlyConsulting\Notifications\Recipients\MergedRecipients;
use RoundlyConsulting\Notifications\Recipients\OwnerRecipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Recipients\ResolverRecipients;
use RoundlyConsulting\Notifications\Recipients\UserRecipients;

/**
 * Who to notify. Every spec resolves at send time with the subject; no email is hard-coded.
 */
final class Recipients
{
    /**
     * Explicit models — single models, collections or arrays of them. Nulls are dropped.
     *
     * @param  Model|iterable<mixed>|null  ...$users
     */
    public static function users(Model|iterable|null ...$users): UserRecipients
    {
        $models = [];

        foreach ($users as $entry) {
            foreach ($entry instanceof Model || $entry === null ? [$entry] : $entry as $user) {
                if ($user === null) {
                    continue;
                }

                if (! $user instanceof Model) {
                    throw InvalidRecipientException::notAModel(get_debug_type($user) === 'string' ? 'string' : class_basename(get_debug_type($user)));
                }

                $models[] = $user;
            }
        }

        return new UserRecipients($models);
    }

    /**
     * The members of a group with a role (through `notifications.recipients.group_resolver`).
     */
    public static function role(Model|Closure $group, string $role): GroupRecipients
    {
        return new GroupRecipients($group, $role);
    }

    /**
     * Every member of a group.
     */
    public static function members(Model|Closure $group): GroupRecipients
    {
        return new GroupRecipients($group);
    }

    /**
     * The subject's owner: HasNotificationOwner, else the named relation.
     */
    public static function owner(?string $relation = null): OwnerRecipients
    {
        return new OwnerRecipients($relation);
    }

    /**
     * Your own RecipientResolver, with scalar arguments.
     *
     * @param  class-string<RecipientResolver>  $resolver
     * @param  array<string, mixed>  $args
     */
    public static function using(string $resolver, array $args = []): ResolverRecipients
    {
        ResolverRecipients::assertStorableArgs($resolver, $args);

        return new ResolverRecipients($resolver, $args);
    }

    public static function merge(RecipientSpec ...$specs): MergedRecipients
    {
        return new MergedRecipients(array_values($specs));
    }

    /**
     * Whatever a send accepts as recipients, as a spec.
     *
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $recipients
     */
    public static function from(RecipientSpec|Model|Closure|iterable $recipients): RecipientSpec
    {
        return match (true) {
            $recipients instanceof RecipientSpec => $recipients,
            $recipients instanceof Closure => new ClosureRecipients($recipients),
            default => self::users($recipients),
        };
    }
}
