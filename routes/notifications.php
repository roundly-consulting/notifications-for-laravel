<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Notifications\Http\Controllers\InboxController;
use RoundlyConsulting\Notifications\Http\Controllers\PreferencesController;
use RoundlyConsulting\Notifications\Http\Controllers\TypesController;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/*
|--------------------------------------------------------------------------
| notifications-for-laravel — the JSON API
|--------------------------------------------------------------------------
|
| Loaded only with `notifications.routes.enabled` on. Every endpoint acts on
| the authenticated user — there is no recipient parameter. Publish this file
| (`--tag=notifications-routes`) to change it: the copy then replaces it.
|
*/

Route::prefix(NotificationsConfig::routesPrefix())
    ->middleware([...NotificationsConfig::routesMiddleware(), ...NotificationsConfig::routesAuthMiddleware()])
    ->name('notifications.')
    ->group(static function (): void {
        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('inbox/counts', [InboxController::class, 'counts'])->name('inbox.counts');
        Route::post('inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
        Route::post('inbox/{item}/read', [InboxController::class, 'read'])->name('inbox.read');
        Route::delete('inbox/{item}/read', [InboxController::class, 'unread'])->name('inbox.unread');
        Route::post('inbox/{item}/archive', [InboxController::class, 'archive'])->name('inbox.archive');
        Route::delete('inbox/{item}/archive', [InboxController::class, 'unarchive'])->name('inbox.unarchive');
        Route::post('inbox/{item}/acknowledge', [InboxController::class, 'acknowledge'])->name('inbox.acknowledge');
        Route::get('preferences', [PreferencesController::class, 'show'])->name('preferences.show');
        Route::patch('preferences', [PreferencesController::class, 'update'])->name('preferences.update');
        Route::get('types', TypesController::class)->name('types.index');
    });
