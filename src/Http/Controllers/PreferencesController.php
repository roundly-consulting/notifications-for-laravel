<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\Enums\Channel;
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

    public function update(UpdatePreferencesRequest $request): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResource {
            $preferences = $this->notifications->preferences($this->user($request));

            foreach ($request->channels() as $type => $channels) {
                foreach ($channels as $channel => $enabled) {
                    $preferences->set($type, Channel::from($channel), $enabled);
                }
            }

            foreach ($request->categories() as $category => $channels) {
                foreach ($channels as $channel => $enabled) {
                    $enabled
                        ? $preferences->unmuteCategory($category, Channel::from($channel))
                        : $preferences->muteCategory($category, Channel::from($channel));
                }
            }

            if (($digest = $request->digest()) !== null) {
                $preferences->digest($digest->frequency, $digest->time);
            }

            if ($request->has('quiet_hours')) {
                $quiet = $request->quietHours();
                $preferences->quietHours($quiet?->start, $quiet?->end);
            }

            if ($request->has('timezone')) {
                $preferences->timezone($request->timezone());
            }

            return new PreferencesResource($preferences->overview());
        });
    }
}
