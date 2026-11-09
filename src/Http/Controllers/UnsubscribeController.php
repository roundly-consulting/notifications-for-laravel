<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Closure;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use RoundlyConsulting\Notifications\DataTransferObjects\UnsubscribeResult;
use RoundlyConsulting\Notifications\Enums\UnsubscribeOutcome;
use RoundlyConsulting\Notifications\Exceptions\NotificationsException;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\Locales;
use RoundlyConsulting\Notifications\Support\Messages;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * The one-click unsubscribe endpoint (RFC 8058). POST unsubscribes — what a mail client's
 * one-click button and the confirmation page's form send; it answers 200 for every valid link,
 * again and again. GET changes nothing: it shows the confirmation page, so a link scanner that
 * opens the URL never unsubscribes anyone. Pages render in the recipient's locale.
 *
 * @internal
 */
final class UnsubscribeController
{
    public function __construct(
        private readonly NotificationsManager $notifications,
        private readonly Factory $views,
        private readonly TypeRegistry $types,
    ) {}

    public function show(string $token): Response
    {
        return $this->page(function () use ($token): Response {
            $claim = $this->notifications->unsubscribe()->claim($token);
            $type = $this->types->get($claim->type);

            if ($claim->recipient === null || $type === null) {
                return $this->render($claim->recipient, Messages::get('unsubscribe.gone'));
            }

            return $this->render(
                $claim->recipient,
                Locales::for($claim->recipient, static fn (): string => Messages::get('unsubscribe.confirm', ['type' => $type->label()])),
                confirm: true,
            );
        });
    }

    public function store(string $token): Response
    {
        return $this->page(function () use ($token): Response {
            $result = $this->notifications->unsubscribe()->redeem($token);

            return $this->render($result->recipient, $this->message($result));
        });
    }

    private function message(UnsubscribeResult $result): string
    {
        $key = match ($result->outcome) {
            UnsubscribeOutcome::Unsubscribed => 'unsubscribe.done',
            UnsubscribeOutcome::AlreadyUnsubscribed => 'unsubscribe.already',
            UnsubscribeOutcome::Required => 'unsubscribe.required',
            UnsubscribeOutcome::Gone => 'unsubscribe.gone',
        };

        $type = $this->types->get($result->type);
        $resolve = static fn (): string => Messages::get($key, ['type' => $type?->label() ?? $result->type]);

        return $result->recipient === null ? $resolve() : Locales::for($result->recipient, $resolve);
    }

    /**
     * @param  Closure(): Response  $respond
     */
    private function page(Closure $respond): Response
    {
        try {
            return $respond();
        } catch (NotificationsException $exception) {
            return $this->render(null, $exception->getMessage(), status: $exception->status());
        }
    }

    private function render(?Model $recipient, string $message, bool $confirm = false, int $status = 200): Response
    {
        $html = static fn (Factory $views): string => $views->make('notifications::unsubscribe', [
            'title' => Messages::get('unsubscribe.title'),
            'message' => $message,
            'hint' => $confirm ? Messages::get('unsubscribe.confirm_hint') : null,
            'button' => $confirm ? Messages::get('unsubscribe.button') : null,
            'locale' => app()->getLocale(),
        ])->render();

        $body = $recipient === null ? $html($this->views) : Locales::for($recipient, fn (): string => $html($this->views));

        return new Response($body, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
