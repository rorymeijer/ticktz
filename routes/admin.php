<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CustomFieldController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailChannelController;
use App\Http\Controllers\Admin\LabelController;
use App\Http\Controllers\Admin\LdapConfigController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\PriorityController;
use App\Http\Controllers\Admin\QueueController;
use App\Http\Controllers\Admin\RequestTypeController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TicketStatusController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkflowController;
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

    Route::get('email', [EmailChannelController::class, 'index'])->name('email.index');
    Route::post('email/channels', [EmailChannelController::class, 'store'])->name('email.channels.store');
    Route::put('email/channels/{channel}', [EmailChannelController::class, 'update'])->name('email.channels.update');
    Route::delete('email/channels/{channel}', [EmailChannelController::class, 'destroy'])->name('email.channels.destroy');
    Route::post('email/channels/{channel}/test', [EmailChannelController::class, 'testSending'])->name('email.channels.test');
    Route::post('email/channels/{channel}/poll', [EmailChannelController::class, 'poll'])->name('email.channels.poll');
    Route::post('email/poll', [EmailChannelController::class, 'pollAll'])->name('email.poll');
    Route::post('email/templates', [EmailChannelController::class, 'storeTemplate'])->name('email.templates.store');
    Route::put('email/templates/{template}', [EmailChannelController::class, 'updateTemplate'])->name('email.templates.update');
    Route::delete('email/templates/{template}', [EmailChannelController::class, 'destroyTemplate'])->name('email.templates.destroy');

    Route::get('directories', [LdapConfigController::class, 'index'])->name('directories.index');
    Route::post('directories', [LdapConfigController::class, 'store'])->name('directories.store');
    Route::put('directories/{directory}', [LdapConfigController::class, 'update'])->name('directories.update');
    Route::delete('directories/{directory}', [LdapConfigController::class, 'destroy'])->name('directories.destroy');
    Route::post('directories/{directory}/test', [LdapConfigController::class, 'test'])->name('directories.test');

    // Service desk configuration: the ticket vocabulary, workflows and queues.
    Route::get('service-desk', [TicketStatusController::class, 'index'])->name('service-desk.index');
    Route::post('statuses', [TicketStatusController::class, 'store'])->name('statuses.store');
    Route::put('statuses/{status}', [TicketStatusController::class, 'update'])->name('statuses.update');
    Route::delete('statuses/{status}', [TicketStatusController::class, 'destroy'])->name('statuses.destroy');

    Route::post('priorities', [PriorityController::class, 'store'])->name('priorities.store');
    Route::put('priorities/{priority}', [PriorityController::class, 'update'])->name('priorities.update');
    Route::delete('priorities/{priority}', [PriorityController::class, 'destroy'])->name('priorities.destroy');

    Route::post('labels', [LabelController::class, 'store'])->name('labels.store');
    Route::put('labels/{label}', [LabelController::class, 'update'])->name('labels.update');
    Route::delete('labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');

    Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::post('custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::put('custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
    Route::delete('custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');

    // Bound by id here: RequestType resolves by slug for the public portal
    // URL, but an administrator editing one needs a stable identifier that
    // does not change when they rename it.
    Route::get('request-types', [RequestTypeController::class, 'index'])->name('request-types.index');
    Route::get('request-types/create', [RequestTypeController::class, 'create'])->name('request-types.create');
    Route::post('request-types', [RequestTypeController::class, 'store'])->name('request-types.store');
    Route::get('request-types/{requestType:id}/edit', [RequestTypeController::class, 'edit'])->name('request-types.edit');
    Route::put('request-types/{requestType:id}', [RequestTypeController::class, 'update'])->name('request-types.update');
    Route::delete('request-types/{requestType:id}', [RequestTypeController::class, 'destroy'])->name('request-types.destroy');
    Route::post('portal-categories', [RequestTypeController::class, 'storeCategory'])->name('portal-categories.store');
    Route::put('portal-categories/{category}', [RequestTypeController::class, 'updateCategory'])->name('portal-categories.update');
    Route::delete('portal-categories/{category}', [RequestTypeController::class, 'destroyCategory'])->name('portal-categories.destroy');

    Route::resource('workflows', WorkflowController::class)->except('show');
    Route::resource('queues', QueueController::class)->except('show');

    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');
});
