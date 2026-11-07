<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\Exceptions\NotificationsException;

/**
 * Every endpoint acts on `$request->user()` only. No user — even with the auth middleware emptied
 * — is a 401. Domain errors answer with their status and a translated message.
 *
 * @internal
 */
abstract class Controller
{
    protected function user(Request $request): Model
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * @param  Closure(): (JsonResource|JsonResponse)  $respond
     */
    protected function guarded(Closure $respond): JsonResource|JsonResponse
    {
        try {
            return $respond();
        } catch (NotificationsException $exception) {
            return new JsonResponse(['message' => $exception->getMessage(), 'code' => $exception->errorCode()], $exception->status());
        }
    }
}
