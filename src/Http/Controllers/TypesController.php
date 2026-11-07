<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Notifications\Http\Resources\TypeResource;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * The registered types, for a settings screen.
 */
final class TypesController extends Controller
{
    public function __construct(
        private readonly NotificationsManager $notifications,
    ) {}

    public function __invoke(Request $request): JsonResource
    {
        $this->user($request);

        return TypeResource::collection($this->notifications->types()->all());
    }
}
