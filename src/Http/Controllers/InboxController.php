<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\Exceptions\InboxItemNotFoundException;
use RoundlyConsulting\Notifications\Http\Requests\InboxIndexRequest;
use RoundlyConsulting\Notifications\Http\Requests\ReadAllRequest;
use RoundlyConsulting\Notifications\Http\Resources\InboxCountsResource;
use RoundlyConsulting\Notifications\Http\Resources\InboxItemResource;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * The authenticated user's inbox. `{item}` is resolved inside this user's inbox only, so another
 * user's id is a 404.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly NotificationsManager $notifications,
        private readonly TypeRegistry $types,
    ) {}

    public function index(InboxIndexRequest $request): JsonResource
    {
        $inbox = $this->notifications->inbox($this->user($request));

        $inbox = match ($request->status()) {
            'unread' => $inbox->unread(),
            'read' => $inbox->read(),
            'archived' => $inbox->archived(),
            'all' => $inbox->withArchived(),
            default => $inbox,
        };

        if (($context = $request->filter('context')) !== null) {
            $inbox = $inbox->in($context);
        }

        if (($category = $request->filter('category')) !== null) {
            $inbox = $inbox->category($category);
        }

        if (($type = $request->filter('type')) !== null) {
            $inbox = $inbox->type($type);
        }

        return InboxItemResource::collection($inbox->paginate($request->perPage()));
    }

    public function counts(Request $request): JsonResource
    {
        return new InboxCountsResource($this->notifications->inbox($this->user($request))->counts());
    }

    public function read(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->markRead($inbox->find($item));

            return $this->item($inbox->find($item), $request);
        });
    }

    public function unread(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->markUnread($inbox->find($item));

            return $this->item($inbox->find($item), $request);
        });
    }

    public function readAll(ReadAllRequest $request): JsonResponse
    {
        $inbox = $this->notifications->inbox($this->user($request));

        if (($type = $request->type()) !== null) {
            $inbox = $inbox->type($type);
        }

        $marked = $inbox->markAllRead($request->context(), $request->category());

        return new JsonResponse(['marked' => $marked]);
    }

    public function archive(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->archive($inbox->find($item));

            return $this->item($inbox->find($item), $request);
        });
    }

    public function unarchive(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->unarchive($inbox->find($item));

            return $this->item($inbox->find($item), $request);
        });
    }

    /**
     * Deletes (soft) one of the user's items: 204, or 404 for another user's or a malformed id.
     */
    public function destroy(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->delete($inbox->find($item));

            return new JsonResponse(null, 204);
        });
    }

    /**
     * Acknowledges the reminder or escalation the item came from (stops its escalation) — under
     * the type its chain is stored with, also for a level that sends another type. A user
     * who can no longer see the subject gets the same 404 as for a foreign item: an old item is no
     * key to silence an escalation others rely on.
     */
    public function acknowledge(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $user = $this->user($request);
            $found = $this->notifications->inbox($user)->find($item);

            if ($found->subject_type === null) {
                return new JsonResponse(['acknowledged' => 0]);
            }

            $type = $this->types->get($found->type);
            $subject = $type === null ? null : Subjects::find($type, $found->subject_type, $found->subject_id);

            if ($type === null || $subject === null || ! Access::allows($type, $user, $subject, true)) {
                throw InboxItemNotFoundException::notFound();
            }

            // An escalation level with its own `type:` writes items of that type, but its chain is
            // stored under the original type: acknowledge what the item's reminder row belongs to.
            $reminder = $found->reminder_id === null ? null : NotificationModels::reminder()::query()->withTrashed()->find($found->reminder_id);

            return new JsonResponse(['acknowledged' => $this->notifications->for($subject)->acknowledge($reminder->type ?? $found->type, $user)]);
        });
    }

    /**
     * One item, always under `data` — the item's own `data` field would make Laravel's resource
     * response skip the wrapper.
     */
    private function item(InboxItem $item, Request $request): JsonResponse
    {
        return new JsonResponse(['data' => (new InboxItemResource($item))->resolve($request)]);
    }
}
