<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\QueueController;
use App\Http\Controllers\Agent\TicketActionController;
use App\Http\Controllers\Agent\TicketCommentController;
use App\Http\Controllers\Agent\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent console
|--------------------------------------------------------------------------
|
| Mounted under /agent behind `auth`. Tickets are bound by their readable key
| (SUP-1042) so a URL pasted into a chat still means something. Every action
| authorises its own policy.
|
*/

Route::prefix('agent')->name('agent.')->group(function (): void {
    Route::get('queues', [QueueController::class, 'index'])->name('queues.index');

    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::put('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');

    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('tickets.comments.store');

    Route::put('tickets/{ticket}/assignee', [TicketActionController::class, 'assign'])->name('tickets.assign');
    Route::post('tickets/{ticket}/claim', [TicketActionController::class, 'claim'])->name('tickets.claim');
    Route::post('tickets/{ticket}/transition', [TicketActionController::class, 'transition'])->name('tickets.transition');

    Route::post('tickets/{ticket}/watch', [TicketActionController::class, 'watch'])->name('tickets.watch');
    Route::delete('tickets/{ticket}/watch', [TicketActionController::class, 'unwatch'])->name('tickets.unwatch');
    Route::post('tickets/{ticket}/watchers', [TicketActionController::class, 'addWatcher'])->name('tickets.watchers.store');
    Route::delete('tickets/{ticket}/watchers/{user}', [TicketActionController::class, 'removeWatcher'])->name('tickets.watchers.destroy');

    Route::post('tickets/{ticket}/links', [TicketActionController::class, 'link'])->name('tickets.links.store');
    Route::delete('tickets/{ticket}/links/{link}', [TicketActionController::class, 'unlink'])->name('tickets.links.destroy');
});
