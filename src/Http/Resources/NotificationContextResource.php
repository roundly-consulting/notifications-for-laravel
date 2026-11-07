<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Resources;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;

/**
 * How an inbox item's context renders. It wraps the stored ContextReference (the label is a
 * snapshot — no query): `{key, label, id, type}`, where `type` is the model's basename, never a
 * class name. Swap it through `notifications.resources.context`.
 *
 * @property ContextReference $resource
 */
final class NotificationContextResource extends JsonResource
{
    /**
     * @return array{key: string, label: string, id?: int|string|null, type?: string}
     */
    public function toArray(Request $request): array
    {
        $reference = $this->resource;
        $output = ['key' => $reference->key, 'label' => $reference->label];

        if ($reference->type !== null) {
            $output['id'] = $reference->id;
            $output['type'] = class_basename(Relation::getMorphedModel($reference->type) ?? $reference->type);
        }

        return $output;
    }
}
