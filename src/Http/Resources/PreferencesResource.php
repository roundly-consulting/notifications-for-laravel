<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\DataTransferObjects\ChannelPreference;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferenceRow;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesOverview;
use RoundlyConsulting\Notifications\Support\Messages;

/**
 * A user's preferences screen: the matrix, digest, quiet hours and timezone.
 *
 * @property PreferencesOverview $resource
 */
final class PreferencesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource;

        return [
            'types' => array_map(static fn (PreferenceRow $row): array => [
                'type' => (new TypeResource($row->type))->resolve($request),
                'channels' => array_map(static fn (ChannelPreference $channel): array => [
                    'channel' => $channel->channel->value,
                    'label' => $channel->channel->label(),
                    'enabled' => $channel->enabled,
                    'default' => $channel->default,
                    'locked' => $channel->locked,
                    'locked_reason' => $channel->locked
                        ? Messages::get($row->type->mandatory ? 'preferences.locked_mandatory' : 'preferences.locked_inbox')
                        : null,
                ], $row->channels),
            ], $overview->rows),
            'digest' => [
                'frequency' => ['value' => $overview->digest->frequency->value, 'label' => $overview->digest->frequency->label()],
                'time' => $overview->digest->time,
            ],
            'quiet_hours' => $overview->quietHours === null ? null : ['start' => $overview->quietHours->start, 'end' => $overview->quietHours->end],
            'timezone' => $overview->timezone,
        ];
    }
}
