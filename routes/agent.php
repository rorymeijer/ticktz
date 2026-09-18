<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AssetController;
use App\Http\Controllers\Agent\KbController;
use App\Http\Controllers\Agent\QueueController;
use App\Http\Controllers\Agent\TicketActionController;
use App\Http\Controllers\Agent\TicketApprovalController;
use App\Http\Controllers\Agent\TicketAssetController;
use App\Http\Controllers\Agent\TicketCommentController;
use App\Http\Controllers\Agent\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent console
|--------------------------------------------------------------------------
|
| Mounted under /agent behind `auth`. Tickets are bound by their readable key
| (SUP-1042) so a URL pasted into a chat still means something.
|
| The whole console sits behind `tickets.view`: a requester passes the ticket
| policy for their own ticket, but the agent view of it — internal notes,
| transitions, the audit timeline — is not theirs to see. Each action then
| authorises its own policy on top.
|
*/

/*
|--------------------------------------------------------------------------
| The asset register
|--------------------------------------------------------------------------
|
| Inside /agent but outside the ticket gate below, on purpose. In plenty of
| organisations the CMDB is kept by a procurement or asset team who never
| touch a ticket, and `assets.view` is a permission in its own right — putting
| the register behind `tickets.view` would mean giving those people an agent's
| view of the whole desk to let them update a serial number.
|
*/

Route::prefix('agent')->name('agent.')->middleware('can:assets.view')->group(function (): void {
    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('assets/suggest', [AssetController::class, 'suggest'])->name('assets.suggest');
    Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::put('assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
    Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
    Route::post('assets/{asset}/relations', [AssetController::class, 'relate'])->name('assets.relations.store');
    Route::delete('assets/{asset}/relations/{relation}', [AssetController::class, 'unrelate'])
        ->name('assets.relations.destroy');
});

Route::prefix('agent')->name('agent.')->middleware('can:tickets.view')->group(function (): void {
    Route::get('queues', [QueueController::class, 'index'])->name('queues.index');

    // The knowledge base as an agent reads it. `kb.view` is authorised in the
    // controller rather than here: an agent who works tickets does not
    // automatically get the knowledge base, and vice versa.
    Route::get('kb', [KbController::class, 'index'])->name('kb.index');
    Route::get('kb/{slug}', [KbController::class, 'show'])->name('kb.show');
    Route::get('tickets/{ticket}/kb/suggest', [KbController::class, 'suggest'])->name('tickets.kb.suggest');
    Route::post('tickets/{ticket}/kb', [KbController::class, 'link'])->name('tickets.kb.link');
    Route::delete('tickets/{ticket}/kb/{article}', [KbController::class, 'unlink'])->name('tickets.kb.unlink');

    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::put('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');

    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('tickets.comments.store');

    Route::put('tickets/{ticket}/assignee', [TicketActionController::class, 'assign'])->name('tickets.assign');
    Route::post('tickets/{ticket}/claim', [TicketActionController::class, 'claim'])->name('tickets.claim');
    Route::post('tickets/{ticket}/clone', [TicketActionController::class, 'clone'])->name('tickets.clone');
    Route::post('tickets/{ticket}/transition', [TicketActionController::class, 'transition'])->name('tickets.transition');

    Route::post('tickets/{ticket}/watch', [TicketActionController::class, 'watch'])->name('tickets.watch');
    Route::delete('tickets/{ticket}/watch', [TicketActionController::class, 'unwatch'])->name('tickets.unwatch');
    Route::post('tickets/{ticket}/watchers', [TicketActionController::class, 'addWatcher'])->name('tickets.watchers.store');
    Route::delete('tickets/{ticket}/watchers/{user}', [TicketActionController::class, 'removeWatcher'])->name('tickets.watchers.destroy');

    Route::post('tickets/{ticket}/approvals', [TicketApprovalController::class, 'store'])->name('tickets.approvals.store');

    Route::post('tickets/{ticket}/assets', [TicketAssetController::class, 'store'])->name('tickets.assets.store');
    Route::delete('tickets/{ticket}/assets/{asset}', [TicketAssetController::class, 'destroy'])
        ->name('tickets.assets.destroy');

    Route::post('tickets/{ticket}/links', [TicketActionController::class, 'link'])->name('tickets.links.store');
    Route::delete('tickets/{ticket}/links/{link}', [TicketActionController::class, 'unlink'])->name('tickets.links.destroy');
});
