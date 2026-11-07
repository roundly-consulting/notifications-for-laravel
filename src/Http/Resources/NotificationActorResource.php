<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How an inbox item's actor renders: by default only its key and class basename — never a class
 * name. Swap it through `notifications.resources.actor` to add a name or an avatar; it receives
 * the actor model.
 *
 * @property Model $resource
 */
final class NotificationActorResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
