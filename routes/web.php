<?php

declare(strict_types=1);

use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Approvals\ApprovalTokenController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\RichTextImageController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Operations
|--------------------------------------------------------------------------
*/

Route::get('/health', HealthController::class)->name('health');

// The setup wizard. Disappears the moment the instance is installed.
require __DIR__.'/install.php';

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
        return redirect()->to($user->homePath());
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
    // Reporting reads the rollups rather than the ticket table, so it is not
    // behind `tickets.view` — a service owner who reports on the desk without
    // working it needs `reports.view` and nothing else.
    Route::middleware('can:reports.view')->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::post('/reports/saved', [ReportController::class, 'store'])->name('reports.saved.store');
        Route::delete('/reports/saved/{report}', [ReportController::class, 'destroy'])
            ->name('reports.saved.destroy');
    });

    // Mounted here rather than under /agent or /portal: an approver is very
    // often a budget holder who is not an agent, and one inbox serves both.
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/decisions/{decision}', [ApprovalController::class, 'decide'])
        ->name('approvals.decide');
    Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])
        ->name('approvals.cancel');

    // Who a picker may offer. Its own endpoint rather than a list rendered
    // into each page, because a desk with four hundred agents has no page big
    // enough — the lists this replaced sent the first few hundred users and
    // dropped the rest silently.
    //
    // Mounted here rather than under /agent or /admin because half the screens
    // that pick a person are administrative — team membership, a user's
    // manager, an approval step, an automation action — and none of those
    // permissions implies `tickets.view`. Each scope authorises itself; see
    // the controller.
    //
    // Throttled because it fires while somebody types: generous for typing,
    // mean for scraping.
    Route::get('/people', [PeopleController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('people.index');

    // The manual, and the `?` that opens it at the right place without
    // leaving the screen somebody is stuck on. Behind `auth` and nothing else:
    // every chapter gates itself on the permission its subject needs, so the
    // table of contents is already the part of Ticktz this reader can reach.
    Route::get('/manual', [ManualController::class, 'index'])->name('manual.index');
    Route::get('/manual/{slug}', [ManualController::class, 'show'])->name('manual.show');
    Route::get('/help', [ManualController::class, 'panel'])->name('manual.panel');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // A person's own API tokens. Not under /admin: a token acts as its owner
    // and carries their permissions, so it is theirs to mint and theirs to
    // revoke. `api.tokens.manage` decides who may use the API at all.
    Route::middleware('can:api.tokens.manage')->group(function (): void {
        Route::get('/settings/api-tokens', [ApiTokenController::class, 'index'])->name('settings.tokens.index');
        Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->name('settings.tokens.store');
        Route::delete('/settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->name('settings.tokens.destroy');
    });

    // Attachments are streamed through a controller so the ticket policy runs
    // before the file does; they are never on a public disk.
    // Images pasted into a rich text field. Same shape as attachments and for
    // the same reason: never on a public disk, always through a policy. The
    // upload is throttled because it is the one endpoint in the application
    // that turns a keystroke into a file on disk.
    Route::post('/rich-text/images', [RichTextImageController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('rich-text.images.store');
    Route::get('/rich-text/images/{image}', [RichTextImageController::class, 'show'])
        ->name('rich-text.images.show');

    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    require __DIR__.'/portal.php';
    require __DIR__.'/agent.php';
    require __DIR__.'/admin.php';
});

require __DIR__.'/auth.php';
