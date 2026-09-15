<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation: when this happens, and these things are true, do that.
 *
 * Two tables, as the data model calls for. Conditions and actions are JSON on
 * the rule rather than tables of their own: they are only ever read as a whole
 * rule, they are edited as a whole rule, and normalising them would turn every
 * evaluation into three joins for no query anybody needs.
 *
 * `automation_executions` is the other half of the feature. An automation that
 * cannot be inspected is an automation nobody trusts, so every evaluation is
 * recorded — including the ones that decided to do nothing, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();

            // What sets it off. One trigger per rule: a rule that fires on
            // three different things is three rules wearing a trenchcoat, and
            // its execution log is unreadable.
            $table->string('trigger', 32);
            // Extra settings the trigger needs — for `scheduled`, the cron
            // expression it runs on.
            $table->json('trigger_config')->nullable();

            // 'all' = every condition must hold, 'any' = at least one. Nesting
            // is deliberately not supported; see the decisions doc.
            $table->enum('match_type', ['all', 'any'])->default('all');
            // [{"field": "priority", "operator": "is", "value": 1}]
            $table->json('conditions')->nullable();
            // [{"type": "assign_team", "team_id": 3}]
            $table->json('actions');

            $table->boolean('is_active')->default(true);
            // Rules run in this order; a matching rule with stop_processing
            // set means the ones below it do not run on this ticket.
            $table->unsignedSmallInteger('position')->default(100);
            $table->boolean('stop_processing')->default(false);

            // Cheap counters for the admin list, so it does not aggregate the
            // execution log on every page load.
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'trigger', 'position']);
        });

        Schema::create('automation_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('trigger', 32);

            // matched: conditions held and the actions ran.
            // skipped: conditions did not hold — the normal case, and still
            //          worth recording, because "why did my rule not fire?" is
            //          the question this table exists to answer.
            // failed:  an action threw.
            $table->enum('status', ['matched', 'skipped', 'failed']);
            // A plain-language fallback, and the condition itself. The string
            // alone would read "priority is 1", which is not an answer to
            // "why did my rule not fire?" — the structured form lets the admin
            // screen render it as "Priority is Urgent", in the reader's own
            // language, using the same code that prints the rule.
            $table->string('reason', 500)->nullable();
            $table->json('context')->nullable();
            // What each action did, or why it did not.
            $table->json('actions')->nullable();
            // How deep in a cascade this was: 0 is a human's change, 1 is a
            // rule reacting to a rule. Recorded so a runaway chain is visible
            // in the log rather than only in the loop guard's refusals.
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['automation_rule_id', 'occurred_at']);
            $table->index(['ticket_id', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_rules');
    }
};
