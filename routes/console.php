<?php

declare(strict_types=1);

use App\Jobs\Mail\PollMailboxJob;
use App\Jobs\Sla\SweepSlaTimersJob;
use App\Models\EmailChannel;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Runs in the worker container (`php artisan schedule:work`). Everything here
| dispatches a job rather than doing the work inline, so a slow mailbox or a
| large report never blocks the scheduler tick.
|
*/

Schedule::call(function (): void {
    // The schedule is evaluated on every tick, including before the first
    // migration has run.
    if (! Schema::hasTable('email_channels')) {
        return;
    }

    EmailChannel::query()
        ->where('is_active', true)
        ->where('imap_enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => PollMailboxJob::dispatch($id));
})
    ->name('ticktz:poll-mailboxes')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(function (): void {
    if (! Schema::hasTable('sla_timers')) {
        return;
    }

    SweepSlaTimersJob::dispatch();
})
    ->name('ticktz:sweep-sla')
    ->everyMinute()
    ->withoutOverlapping();
