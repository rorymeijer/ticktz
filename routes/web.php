<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentController;
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
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    // Signed-in people go straight to the surface that matches their role;
    // anonymous visitors get the landing page.
    $user = request()->user();

    if ($user) {
        return redirect()->route($user->isAgent() ? 'dashboard' : 'portal.index');
    }

    return Inertia::render('Welcome');
})->name('home');

/*
|--------------------------------------------------------------------------
| Authenticated application
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Attachments are streamed through a controller so the ticket policy runs
    // before the file does; they are never on a public disk.
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    require __DIR__.'/portal.php';
    require __DIR__.'/agent.php';
    require __DIR__.'/admin.php';
});

require __DIR__.'/auth.php';
