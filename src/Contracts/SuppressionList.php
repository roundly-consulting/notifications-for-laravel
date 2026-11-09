<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

/**
 * Addresses that must not get mail (hard bounces, complaints, opt-outs kept elsewhere). Asked
 * before every typed mail and digest; a suppressed address gets nothing and
 * NotificationMailSuppressed fires. Bind the contract or set `notifications.mail.suppression_list`.
 */
interface SuppressionList
{
    /**
     * The address as the recipient routes it (trimmed) — compare case-insensitively if your list
     * needs it.
     */
    public function isSuppressed(string $address): bool;
}
