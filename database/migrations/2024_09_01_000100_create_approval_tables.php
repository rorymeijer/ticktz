<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approvals: somebody with the authority to say yes has to say it.
 *
 * The brief asks for single, parallel and sequential approval. Those are not
 * three mechanisms — they are one. A workflow is an ordered list of **steps**;
 * the approvers within a step decide in parallel and the step's `mode` says
 * whether one of them is enough or all of them are needed; and more than one
 * step makes it sequential, because step two does not open until step one is
 * settled. "Single approval" is one step, one approver, mode `any`.
 *
 * The running instance is deliberately separate from the definition:
 *
 * - `approval_requests` is one run against one ticket. Editing a workflow
 *   never rewrites an approval somebody has already answered.
 * - `approval_decisions` is one row per approver per step, written when the
 *   step opens. That row *is* the audit: who was asked, when, what they said
 *   and from where. A decision is never deleted, only superseded by a new run.
 *
 * `tickets.approval_state` is a cached summary, maintained by the service, so
 * the ticket list can badge and filter without joining three tables per row —
 * the same bargain `sla_breached` makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();
            // Shown to the approver in the notification and on the decision
            // page: "the budget holder must agree before we order hardware".
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            // any: the first yes settles the step. all: everybody must agree.
            // A single rejection settles either, because an approval that can
            // be overridden by a majority is not an approval.
            $table->enum('mode', ['any', 'all'])->default('any');
            // How the approvers are found when the step opens. Resolved once,
            // at that moment, and written into `approval_decisions` — so a
            // team's membership changing tomorrow does not silently change who
            // was asked today.
            $table->enum('approver_type', ['users', 'team', 'role', 'manager', 'field'])->default('users');
            $table->json('approver_ids')->nullable();
            // For approver_type `field`: the custom field key holding the
            // e-mail address or user id of the person to ask.
            $table->string('approver_field', 100)->nullable();
            // Hours the approver gets before the approval is reported overdue.
            // Nothing is auto-approved on expiry — see the docs.
            $table->unsignedSmallInteger('due_hours')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['approval_workflow_id', 'position']);
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // NULL for an approval an agent raised by hand rather than one a
            // request type opened.
            $table->foreignId('approval_workflow_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            // Which step is open. Steps are ordered by their own `position`,
            // and this holds that position rather than the step id, so a
            // deleted step cannot strand a running approval.
            $table->unsignedSmallInteger('current_position')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_step_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('step_name')->nullable();
            $table->enum('mode', ['any', 'all'])->default('any');
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->enum('decision', ['pending', 'approved', 'rejected', 'skipped'])->default('pending');
            $table->text('comment')->nullable();
            // web, email or system — a decision that arrived by mail should be
            // distinguishable from one somebody clicked while signed in.
            $table->string('source', 16)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            // Hashed, never stored in the clear: this token is a bearer
            // credential that decides things on the holder's behalf, so a
            // leaked database backup must not be a pile of usable approve
            // links. Looked up by hash, exactly like a password reset.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            // One person is asked once per step. Asking twice would let the
            // same approver satisfy an `all` step on their own.
            $table->unique(['approval_request_id', 'position', 'approver_id'], 'approval_decisions_unique_approver');
            $table->index(['approver_id', 'decision']);
        });

        Schema::table('users', function (Blueprint $table): void {
            // "My manager has to sign this off" is the most common approval a
            // service desk has, and it needs somewhere to read the answer from.
            // Self-referential and nullable: most people have a manager, the
            // person at the top does not, and a directory sync can fill it in.
            $table->foreignId('manager_id')->nullable()->after('organization_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('request_types', function (Blueprint $table): void {
            // A request type that needs signing off opens this approval the
            // moment a ticket is filed from it.
            $table->foreignId('approval_workflow_id')->nullable()->after('workflow_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('workflow_transitions', function (Blueprint $table): void {
            // The gate. A transition marked this way is refused while the
            // ticket has an approval that has not been granted.
            $table->boolean('requires_approval')->default(false)->after('requires_assignee');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            // NULL means no approval was ever asked for. Cached from the
            // requests above so a list of five hundred tickets does not become
            // five hundred subqueries.
            $table->string('approval_state', 16)->nullable()->after('sla_breached');

            $table->index('approval_state');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['approval_state']);
            $table->dropColumn('approval_state');
        });

        Schema::table('workflow_transitions', function (Blueprint $table): void {
            $table->dropColumn('requires_approval');
        });

        Schema::table('request_types', function (Blueprint $table): void {
            $table->dropForeign(['approval_workflow_id']);
            $table->dropColumn('approval_workflow_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });

        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
