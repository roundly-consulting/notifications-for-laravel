<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\Http\Requests\InboxIndexRequest;
use RoundlyConsulting\Notifications\Http\Requests\ReadAllRequest;
use RoundlyConsulting\Notifications\Http\Resources\InboxCountsResource;
use RoundlyConsulting\Notifications\Http\Resources\InboxItemResource;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * The authenticated user's inbox. `{item}` is resolved inside this user's inbox only, so another
 * user's id is a 404.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly NotificationsManager $notifications,
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
        return $this->guarded(function () use ($request, $item): JsonResource {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->markRead($item);

            return new InboxItemResource($inbox->find($item));
        });
    }

    public function unread(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResource {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->markUnread($item);

            return new InboxItemResource($inbox->find($item));
        });
    }

    public function readAll(ReadAllRequest $request): JsonResponse
    {
        $marked = $this->notifications->inbox($this->user($request))->markAllRead($request->context(), $request->category());

        return new JsonResponse(['marked' => $marked]);
    }

    public function archive(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResource {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->archive($item);

            return new InboxItemResource($inbox->find($item));
        });
    }

    public function unarchive(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResource {
            $inbox = $this->notifications->inbox($this->user($request));
            $inbox->unarchive($item);

            return new InboxItemResource($inbox->find($item));
        });
    }

    /**
     * Acknowledges the reminder or escalation the item came from (stops its escalation).
     */
    public function acknowledge(Request $request, string $item): JsonResource|JsonResponse
    {
        return $this->guarded(function () use ($request, $item): JsonResponse {
            $user = $this->user($request);
            $found = $this->notifications->inbox($user)->find($item);
            $acknowledged = 0;

            if ($found->subject !== null) {
                $acknowledged = $this->notifications->for($found->subject)->acknowledge($found->type, $user);
            }

            return new JsonResponse(['acknowledged' => $acknowledged]);
        });
    }
}
