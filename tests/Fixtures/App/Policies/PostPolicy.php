<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies;

use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

final class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        return ! $post->private || $post->author_id === $user->id;
    }
}
