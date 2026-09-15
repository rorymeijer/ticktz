<?php

declare(strict_types=1);

use App\Jobs\Automation\RunScheduledRulesJob;
use App\Jobs\Mail\PollMailboxJob;
use App\Jobs\Sla\SweepSlaTimersJob;
use App\Models\AutomationExecution;
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

Schedule::call(function (): void {
    if (! Schema::hasTable('automation_rules')) {
        return;
    }

    // The job asks each rule whether its cron wants this minute. Rules are
    // data and are edited while the scheduler is running, so they cannot be
    // registered here as schedule entries.
    RunScheduledRulesJob::dispatch();
})
    ->name('ticktz:automation-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(function (): void {
    if (! Schema::hasTable('automation_executions')) {
        return;
    }

    $days = (int) config('ticktz.automation.log_retention_days', 30);

    if ($days <= 0) {
        return;
    }

    // An execution is written on every evaluation, skips included, so the log
    // grows by rules × ticket events per day. Trimming it keeps the admin
    // screen fast and the database honest about what it is for.
    AutomationExecution::query()
        ->where('occurred_at', '<', now()->subDays($days))
        ->delete();
})
    ->name('ticktz:prune-automation-log')
    ->dailyAt('03:20')
    ->withoutOverlapping();
