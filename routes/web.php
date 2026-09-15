<?php

declare(strict_types=1);

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Operations
|--------------------------------------------------------------------------
*/

Route::get('/health', HealthController::class)->name('health');

/*
|--------------------------------------------------------------------------
| Application
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
