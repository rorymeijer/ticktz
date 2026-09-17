<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The heart of Ticktz.
 *
 * Indexing note: every agent list view filters on some combination of status,
 * assignee, team and recency, so those get composite indexes rather than
 * single-column ones — at 10.000 requesters a table scan per queue refresh is
 * not an option.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ticket numbers come from a dedicated row that is locked for update,
        // so two concurrent submissions can never claim the same key.
        Schema::create('ticket_sequences', function (Blueprint $table): void {
            $table->string('prefix', 10)->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();

            // Human-readable identifier, e.g. SUP-1042.
            $table->string('key', 32)->unique();
            $table->string('prefix', 10);
            $table->unsignedBigInteger('number');

            $table->string('subject', 500);
            $table->longText('description')->nullable();

            $table->foreignId('status_id')->constrained('ticket_statuses')->restrictOnDelete();
            $table->foreignId('priority_id')->constrained('priorities')->restrictOnDelete();
            $table->foreignId('workflow_id')->constrained()->restrictOnDelete();
            // Routing hint rather than ownership: a request type (phase 3) or
            // an automation rule (phase 6) can file a ticket into a queue.
            // Queues are saved filters, so a ticket also appears in every queue
            // whose criteria it happens to match.
            $table->foreignId('queue_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // How the ticket reached us. Drives notification behaviour.
            $table->enum('source', ['portal', 'email', 'agent', 'api', 'automation'])->default('agent');

            // Lifecycle timestamps, all UTC.
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_public_reply_at')->nullable();
            $table->timestamp('last_requester_reply_at')->nullable();

            $table->unsignedInteger('reopen_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['prefix', 'number']);
            $table->index(['status_id', 'updated_at']);
            $table->index(['assignee_id', 'status_id']);
            $table->index(['team_id', 'status_id']);
            $table->index(['queue_id', 'status_id']);
            $table->index(['requester_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index('priority_id');
            $table->index('last_activity_at');
            $table->index('created_at');
        });

        // Free-text search over subject and description. MySQL only: the test
        // suite runs on SQLite, which falls back to LIKE (see TicketFilter).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tickets ADD FULLTEXT ticket_search (subject, description)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
    }
};
