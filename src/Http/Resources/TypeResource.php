<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\DataTransferObjects\TypeDefinition;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Support\TypeDefinitions;

/**
 * A registered type for a settings screen, in the request locale.
 *
 * @property TypeDefinition $resource
 */
final class TypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource;

        return [
            'key' => $type->key,
            'category' => ['key' => $type->category, 'label' => TypeDefinitions::category($type->category)->label],
            'label' => $type->label,
            'description' => $type->description,
            'channels' => array_map(static fn (Channel $channel): array => [
                'value' => $channel->value,
                'label' => $channel->label(),
                'default' => in_array($channel, $type->defaultChannels, true),
            ], $type->channels),
            'mandatory' => $type->mandatory,
            'priority' => ['value' => $type->priority->value, 'label' => $type->priority->label()],
            'digestible' => $type->digestible,
        ];
    }
}
