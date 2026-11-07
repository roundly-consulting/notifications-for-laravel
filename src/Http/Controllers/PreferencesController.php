<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\Http\Requests\UpdatePreferencesRequest;
use RoundlyConsulting\Notifications\Http\Resources\PreferencesResource;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * The authenticated user's preferences.
 */
final class PreferencesController extends Controller
{
    public function __construct(
        private readonly NotificationsManager $notifications,
    ) {}

    public function show(Request $request): JsonResource
    {
        return new PreferencesResource($this->notifications->preferences($this->user($request))->overview());
    }

    /**
     * All or nothing: a refused part (422) leaves every setting as it was.
     */
    public function update(UpdatePreferencesRequest $request): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResource {
            $preferences = $this->notifications->preferences($this->user($request))->update($request->toUpdate());

            return new PreferencesResource($preferences->overview());
        });
    }
}
