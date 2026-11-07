<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Mail;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Context;

/**
 * What a host notification's `toInbox($notifiable)` returns for the `inbox` channel:
 *
 * ```php
 * return (new InboxMessage('comment.posted'))->about($post)->by($author)->with(['excerpt' => $excerpt]);
 * ```
 */
final class InboxMessage
{
    public ?Model $subject = null;

    public ?Model $actor = null;

    public Context|Model|string|null $context = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function __construct(
        public string $type,
    ) {}

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function about(?Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function in(Context|Model|string|null $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function with(array $data): self
    {
        $this->data = $data;

        return $this;
    }
}
