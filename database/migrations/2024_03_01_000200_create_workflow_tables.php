<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflows decide which status changes are legal.
 *
 * A ticket carries the workflow it was created under, so changing a workflow
 * later never strands an in-flight ticket in an unreachable state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Which statuses take part in a workflow, in which order, and which one
        // a new ticket starts in.
        Schema::create('workflow_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_status_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_initial')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['workflow_id', 'ticket_status_id']);
            $table->index('ticket_status_id');
        });

        Schema::create('workflow_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            // NULL means "from any status" — useful for a universal "Close".
            $table->foreignId('from_status_id')->nullable()->constrained('ticket_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('ticket_statuses')->cascadeOnDelete();
            // Button label; falls back to the target status name.
            $table->string('name')->nullable();
            $table->boolean('requires_comment')->default(false);
            $table->boolean('requires_assignee')->default(false);
            // An extra permission on top of `tickets.transition`, e.g. only a
            // team lead may reopen.
            $table->string('required_permission', 100)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workflow_id', 'from_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_statuses');
        Schema::dropIfExists('workflows');
    }
};
