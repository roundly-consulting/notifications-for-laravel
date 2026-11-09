<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Notifications\Http\Controllers\UnsubscribeController;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/*
|--------------------------------------------------------------------------
| notifications-for-laravel — one-click unsubscribe (RFC 8058)
|--------------------------------------------------------------------------
|
| Loaded only with `notifications.mail.list_unsubscribe.enabled` on. The token
| is the credential: no login, no session, no CSRF (a mail client POSTs here
| without either). GET only shows a confirmation page — link scanners must
| never unsubscribe anyone. Publish (`--tag=notifications-routes`) to change it.
|
*/

Route::prefix(NotificationsConfig::routesPrefix())
    ->middleware(NotificationsConfig::listUnsubscribeMiddleware())
    ->group(static function (): void {
        Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])
            ->where('token', '[A-Za-z0-9_-]+')
            ->name('notifications.unsubscribe.show');
        Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'store'])
            ->where('token', '[A-Za-z0-9_-]+')
            ->name('notifications.unsubscribe');
    });
