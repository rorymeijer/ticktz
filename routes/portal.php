<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\EquipmentController;
use App\Http\Controllers\Portal\KbController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\RequestController;
use App\Http\Controllers\Portal\RequestTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer portal
|--------------------------------------------------------------------------
|
| Everything a requester touches. Throttled as a group: the portal is the
| surface most likely to face the open internet.
|
*/

Route::prefix('portal')->name('portal.')->middleware('throttle:portal')->group(function (): void {
    Route::get('/', [PortalController::class, 'index'])->name('index');

    Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
    Route::get('requests/{ticket}', [RequestController::class, 'show'])->name('requests.show');
    Route::post('requests/{ticket}/comments', [RequestController::class, 'comment'])->name('requests.comment');

    // The help centre. `suggest` is called from the request form as the
    // requester types, which is why it is a GET returning JSON.
    // "What have I got?" — half the tickets about a machine open with the
    // requester not knowing what the machine is called.
    Route::get('equipment', [EquipmentController::class, 'index'])->name('equipment.index');

    Route::get('kb', [KbController::class, 'index'])->name('kb.index');
    Route::get('kb/suggest', [KbController::class, 'suggest'])->name('kb.suggest');
    Route::get('kb/{slug}', [KbController::class, 'show'])->name('kb.show');

    Route::get('new/{requestType}', [RequestTypeController::class, 'show'])->name('request-types.show');
    Route::post('new/{requestType}', [RequestTypeController::class, 'store'])->name('request-types.store');
});
