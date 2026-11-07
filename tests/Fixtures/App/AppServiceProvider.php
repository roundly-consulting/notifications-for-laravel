<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies\ContractPolicy;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies\PostPolicy;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies\PurchaseRequestPolicy;

/**
 * The test app's AppServiceProvider: its policies, and the digestible type defined fluently —
 * after the package booted, exactly like a host.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);

        app('translator')->addLines([
            'app.security_alert' => 'Security alert: :what',
            'app.awaiting' => 'Request :ref awaits your approval (:count)',
            'app.comment' => '{1} :count new comment on :title|[0,*] :count new comments on :title',
        ], 'en');

        app('translator')->addLines([
            'app.security_alert' => 'Bezpečnostné upozornenie: :what',
            'app.awaiting' => 'Žiadosť :ref čaká na vaše schválenie (:count)',
            'app.comment' => '{1} :count nový komentár k :title|[2,4] :count nové komentáre k :title|[0,*] :count nových komentárov k :title',
        ], 'sk');

        Notifications::define('comment.posted')
            ->category('comments')
            ->priority(Priority::Low)
            ->digestible()
            ->window('PT10M')
            ->subject(Post::class)
            ->title(fn (NotificationPayload $p): string => trans_choice('app.comment', $p->count, ['count' => $p->count, 'title' => (string) ($p->data['title'] ?? '')]))
            ->url(fn (NotificationPayload $p): string => 'https://app.test/posts/'.$p->subjectKey)
            ->register();
    }
}
