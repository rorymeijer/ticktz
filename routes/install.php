<?php

declare(strict_types=1);

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The setup wizard
|--------------------------------------------------------------------------
|
| Unauthenticated by necessity — there is nobody to authenticate as before the
| first account exists — and therefore behind `installed.not`, which answers
| 404 for every route here the moment the instance is set up.
|
| Both routes are throttled. `install/database` opens a connection to whatever
| host and port it is given, so an unthrottled one is a port scanner with a
| nice interface; and `install` itself creates an administrator, which is not
| something to let anybody retry in a loop.
|
*/

Route::middleware(['installed.not', 'throttle:install'])->group(function (): void {
    Route::get('/install', [InstallController::class, 'show'])->name('install.show');
    Route::post('/install/database', [InstallController::class, 'testDatabase'])->name('install.database');
    Route::post('/install', [InstallController::class, 'store'])->name('install.store');
});
