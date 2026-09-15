<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LdapConfigController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| Mounted under /admin behind the `auth` middleware. Authorisation is *not*
| done with a blanket middleware: each action authorises its own policy or
| gate, so an operator with only `teams.manage` can reach the team screens
| without being handed the whole admin area.
|
*/

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('index');

    Route::resource('users', UserController::class)->except('show');
    Route::patch('users/{user}/active', [UserController::class, 'toggleActive'])->name('users.active');

    Route::resource('roles', RoleController::class)->except('show');
    Route::resource('teams', TeamController::class)->except('show');
    Route::resource('organizations', OrganizationController::class)->except('show');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('directories', [LdapConfigController::class, 'index'])->name('directories.index');
    Route::post('directories', [LdapConfigController::class, 'store'])->name('directories.store');
    Route::put('directories/{directory}', [LdapConfigController::class, 'update'])->name('directories.update');
    Route::delete('directories/{directory}', [LdapConfigController::class, 'destroy'])->name('directories.destroy');
    Route::post('directories/{directory}/test', [LdapConfigController::class, 'test'])->name('directories.test');

    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');
});
