<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\KbController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\QueueController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public REST API — v1
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 and versioned in the path from the first release, so the
| day a payload has to change in a way that breaks callers there is somewhere
| for the new shape to live. Adding a version later means every existing
| integration has to move at once.
|
| Every route is token-authenticated and scope-gated. There is no unscoped
| endpoint here except `me`, which exists precisely so a caller can discover
| what its token may do.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api-token'])
    ->group(function (): void {
        Route::get('/me', MeController::class)->name('me');

        /*
        | Tickets
        */
        Route::middleware('scope:tickets.read')->group(function (): void {
            Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
            Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
            Route::get('/tickets/{ticket}/comments', [CommentController::class, 'index'])
                ->name('tickets.comments.index');
        });

        Route::middleware('scope:tickets.write')->group(function (): void {
            Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
            Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
            Route::post('/tickets/{ticket}/transition', [TicketController::class, 'transition'])
                ->name('tickets.transition');
        });

        // Its own scope: a bot that posts status updates onto tickets needs to
        // be able to comment without also being able to reassign and close
        // them, and that is the common integration, not the exception.
        Route::middleware('scope:comments.write')->group(function (): void {
            Route::post('/tickets/{ticket}/comments', [CommentController::class, 'store'])
                ->name('tickets.comments.store');
        });

        /*
        | Queues
        */
        Route::middleware('scope:queues.read')->group(function (): void {
            Route::get('/queues', [QueueController::class, 'index'])->name('queues.index');
            Route::get('/queues/{queue}', [QueueController::class, 'show'])->name('queues.show');
        });

        /*
        | Assets
        */
        Route::middleware('scope:assets.read')->group(function (): void {
            Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
            Route::get('/asset-types', [AssetController::class, 'types'])->name('assets.types');
            Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
        });

        Route::middleware('scope:assets.write')->group(function (): void {
            Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
            // PUT by tag, so an inventory sync is idempotent without having to
            // remember our ids. See AssetController::upsert().
            Route::put('/assets/tag/{tag}', [AssetController::class, 'upsert'])->name('assets.upsert');
        });

        /*
        | Knowledge base
        */
        Route::middleware('scope:kb.read')->group(function (): void {
            Route::get('/kb/articles', [KbController::class, 'index'])->name('kb.index');
            Route::get('/kb/articles/{slug}', [KbController::class, 'show'])->name('kb.show');
        });

        /*
        | Webhooks
        */
        Route::middleware('scope:webhooks.manage')->group(function (): void {
            Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
            Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])
                ->name('webhooks.deliveries');
        });
    });
