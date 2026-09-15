<?php

declare(strict_types=1);

use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Approvals\ApprovalTokenController;
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

/*
|--------------------------------------------------------------------------
| Deciding an approval from an e-mail
|--------------------------------------------------------------------------
|
| The only unauthenticated route in Ticktz that changes anything, so: the link
| in the mail is a GET that decides nothing and only renders the page, the
| decision is a POST from that page, and the whole thing is throttled. See
| ApprovalTokenController for why each of those matters.
|
*/

Route::middleware('throttle:portal')->group(function (): void {
    Route::get('/approvals/decide/{token}', [ApprovalTokenController::class, 'show'])
        ->name('approvals.token.show');
    Route::post('/approvals/decide/{token}', [ApprovalTokenController::class, 'decide'])
        ->name('approvals.token.decide');
    Route::get('/approvals/decided/{outcome}', [ApprovalTokenController::class, 'done'])
        ->name('approvals.token.done');
});

Route::middleware('auth')->group(function (): void {
    // Mounted here rather than under /agent or /portal: an approver is very
    // often a budget holder who is not an agent, and one inbox serves both.
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/decisions/{decision}', [ApprovalController::class, 'decide'])
        ->name('approvals.decide');
    Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])
        ->name('approvals.cancel');

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
