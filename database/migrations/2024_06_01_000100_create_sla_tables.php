<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service level agreements: what is promised, and whether it was kept.
 *
 * Five tables, each with one job:
 *
 * - `business_calendars` + `calendar_holidays` answer "is the clock running
 *   right now?". Everything else defers to them; a four-hour target means four
 *   *working* hours, which over a weekend is next Tuesday.
 * - `sla_policies` decide which promise applies to a ticket.
 * - `sla_goals` are the promises themselves: this metric, this long.
 * - `sla_timers` are one ticket's copy of a goal, with its own clock. The
 *   target is copied onto the timer rather than read through the goal, so
 *   editing a policy never rewrites history — see the column comments below.
 * - `sla_events` are the audit trail of every clock, which is what a dispute
 *   about a breach is settled with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();
            // Working hours are wall-clock times in this zone. A calendar for
            // a desk in Amsterdam and one for a desk in Sydney disagree about
            // what "09:00" means, and both are right.
            $table->string('timezone', 64)->default('Europe/Amsterdam');
            // {"mon": [["09:00","17:00"]], "tue": [...], ...} — a day may have
            // several intervals (a lunch break splits one into two), and a day
            // that is absent or empty is not worked at all.
            $table->json('working_hours');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('is_default');
        });

        Schema::create('calendar_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_calendar_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            // A recurring holiday repeats on the same day every year, so the
            // year part of `date` is ignored. Easter is not recurring in that
            // sense and gets a row per year.
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->unique(['business_calendar_id', 'date', 'name'], 'calendar_holiday_unique');
            $table->index(['business_calendar_id', 'date']);
        });

        Schema::create('sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();
            $table->foreignId('business_calendar_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            // The policy that applies when nothing else matches. Exactly one
            // is enforced in the model, not the schema, because swapping which
            // one is default needs two writes.
            $table->boolean('is_default')->default(false);
            // Which tickets this policy claims: {"queue_ids": [..],
            // "team_ids": [..], "request_type_ids": [..],
            // "organization_ids": [..], "priority_ids": [..]}. An absent or
            // empty key means "any". Policies are evaluated by `position` and
            // the first match wins, so a narrow policy is placed above a broad
            // one.
            $table->json('conditions')->nullable();
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('sla_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sla_policy_id')->constrained()->cascadeOnDelete();
            // first_response: how long until a human answers.
            // resolution: how long until the ticket is resolved.
            $table->enum('metric', ['first_response', 'resolution']);
            // NULL means "whatever the priority", which is the fallback goal.
            // A goal naming a priority beats one that does not.
            $table->foreignId('priority_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('request_type_id')->nullable()->constrained()->cascadeOnDelete();
            // Minutes of *working* time, resolved against the policy calendar.
            $table->unsignedInteger('target_minutes');
            // [{"at": 50, "actions": [{"type": "notify", "to": "assignee"}]}]
            // — `at` is a percentage of the target, 100 meaning the breach
            // itself. Kept with the goal because an escalation is meaningless
            // without the target it is a fraction of; see the decisions doc.
            $table->json('escalations')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['sla_policy_id', 'metric', 'priority_id', 'request_type_id'],
                'sla_goal_unique',
            );
        });

        Schema::create('sla_timers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // The policy and goal a timer came from are recorded for the audit
            // trail, but the timer does not read its target through them: both
            // may be edited or deleted, and a promise already made does not
            // change retroactively.
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sla_goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_calendar_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('metric', ['first_response', 'resolution']);
            $table->unsignedInteger('target_minutes');

            $table->timestamp('started_at');
            // Absolute wall-clock moment the target is reached, computed by
            // walking the calendar. Stored rather than derived so a list of a
            // thousand tickets sorts and filters on SLA in SQL.
            $table->timestamp('due_at');
            $table->timestamp('paused_at')->nullable();
            // Working minutes spent paused, accumulated across every pause.
            $table->unsignedInteger('paused_minutes')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            // running: counting down. paused: a waiting status stopped it.
            // met: finished inside the target. breached: finished outside it,
            // or still open past it. cancelled: the ticket no longer qualifies.
            $table->enum('status', ['running', 'paused', 'met', 'breached', 'cancelled'])
                ->default('running');
            $table->timestamps();

            // One live timer per metric per ticket; completed ones are kept.
            $table->unique(['ticket_id', 'metric'], 'sla_timer_unique');
            // The sweep asks for running timers that are due, every minute.
            $table->index(['status', 'due_at']);
        });

        Schema::create('sla_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sla_timer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->timestamp('occurred_at');
            // For an escalation: which threshold fired, and what it did. The
            // sweep asks "did this threshold already fire?" before acting, so
            // an escalation happens once however often the job runs.
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'occurred_at']);
            $table->index(['sla_timer_id', 'event']);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('sla_policy_id')->nullable()->after('request_type_id')
                ->constrained()->nullOnDelete();
            // Denormalised from the timers so a ticket list can sort and
            // colour by "what is about to breach" without a join per row.
            $table->timestamp('sla_due_at')->nullable()->after('sla_policy_id');
            $table->boolean('sla_breached')->default(false)->after('sla_due_at');

            $table->index(['sla_breached', 'sla_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['sla_policy_id']);
            $table->dropIndex(['sla_breached', 'sla_due_at']);
            $table->dropColumn(['sla_policy_id', 'sla_due_at', 'sla_breached']);
        });

        Schema::dropIfExists('sla_events');
        Schema::dropIfExists('sla_timers');
        Schema::dropIfExists('sla_goals');
        Schema::dropIfExists('sla_policies');
        Schema::dropIfExists('calendar_holidays');
        Schema::dropIfExists('business_calendars');
    }
};
