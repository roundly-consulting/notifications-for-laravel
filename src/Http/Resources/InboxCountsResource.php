<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextCount;
use RoundlyConsulting\Notifications\DataTransferObjects\InboxCounts;

/**
 * Unread and total counts, and unread per context.
 *
 * @property InboxCounts $resource
 */
final class InboxCountsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'unread' => $this->resource->unread,
            'total' => $this->resource->total,
            'contexts' => array_map(static fn (ContextCount $context): array => [
                'key' => $context->key,
                'label' => $context->label,
                'unread' => $context->unread,
            ], $this->resource->contexts),
        ];
    }
}
