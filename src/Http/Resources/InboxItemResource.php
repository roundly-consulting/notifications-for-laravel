<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Resources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\DeliveryPayloads;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\Urls;
use Throwable;

/**
 * An inbox item for a JSON API, in the request locale. The title, body and url render from the
 * live type; when the type is gone or its render throws, from the snapshot stored with the item.
 * Actor, subject and context render through the swappable reference resources — no class names.
 * `InboxItemResource::collection()` eager-loads actors and subjects (no N+1).
 *
 * @property InboxItem $resource
 */
final class InboxItemResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        if ($resource instanceof AbstractPaginator || $resource instanceof AbstractCursorPaginator) {
            $items = $resource->getCollection();

            if ($items instanceof Collection) {
                $items->loadMissing(['actor', 'subject']);
            }
        } elseif ($resource instanceof Collection) {
            $resource->loadMissing(['actor', 'subject']);
        }

        return parent::collection($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $type = app(TypeRegistry::class)->get($item->type);
        $data = $item->data;
        $meta = is_array($data[DeliveryPayloads::META] ?? null) ? $data[DeliveryPayloads::META] : [];
        unset($data[DeliveryPayloads::META]);

        [$title, $body, $url] = $this->render($type, $item, $data, $meta, $request);

        return [
            'id' => $item->getKey(),
            'type' => $item->type,
            'category' => $item->category,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'count' => $item->count,
            // Fail closed: without the type, nothing says which data is public.
            'data' => $type === null ? [] : $type->publicData($data),
            'actor' => $this->reference($item->actor, NotificationsConfig::actorResource(), $request),
            'subject' => $this->reference($item->subject, NotificationsConfig::subjectResource(), $request),
            'context' => $this->context($request),
            'priority' => ['value' => $item->priority->value, 'label' => $item->priority->label()],
            'read_at' => $item->read_at?->toIso8601String(),
            'archived_at' => $item->archived_at?->toIso8601String(),
            'first_event_at' => $item->first_event_at->toIso8601String(),
            'last_event_at' => $item->last_event_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<array-key, mixed>  $meta  the stored `_notifications` metadata
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function render(?NotificationType $type, InboxItem $item, array $data, array $meta, Request $request): array
    {
        if ($type === null || ($meta['source'] ?? null) === 'interop') {
            return [$item->title, null, $item->url];
        }

        $recipient = $request->user();
        $recipient = $recipient instanceof Model ? $recipient : $item->recipient;

        if ($recipient === null) {
            return [$item->title, null, $item->url];
        }

        $payload = new NotificationPayload(
            type: $item->type,
            recipient: $recipient,
            subject: $item->subject,
            subjectKey: $item->subject_id,
            actor: $item->actor,
            context: $item->contextReference(),
            data: $data,
            count: $item->count,
            firstAt: $item->first_event_at,
            lastAt: $item->last_event_at,
            channel: Channel::Inbox,
            escalated: DeliveryPayloads::escalated($meta),
            reminder: DeliveryPayloads::reminderOf($meta),
        );

        try {
            return [$type->title($payload), $type->body($payload), Urls::safe($type->url($payload))];
        } catch (Throwable $exception) {
            report($exception);

            return [$item->title, null, $item->url];
        }
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @return array<array-key, mixed>|null
     */
    private function reference(?Model $model, string $resource, Request $request): ?array
    {
        return $model === null ? null : (new $resource($model))->resolve($request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function context(Request $request): ?array
    {
        $reference = $this->resource->contextReference();
        $resource = NotificationsConfig::contextResource();

        return $reference === null ? null : (new $resource($reference))->resolve($request);
    }
}
