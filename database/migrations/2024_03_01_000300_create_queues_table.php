<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A queue is a named, saved filter over tickets — "Unassigned first line",
 * "My team, breaching today". Tickets are not *moved into* a queue; they match
 * one or they do not. A queue may additionally be a routing target, which is
 * what `team_id` is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queues', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // Filter definition, e.g.
            // {"status_category": ["new","open"], "assignee": "unassigned"}.
            $table->json('filters')->nullable();
            // Default ordering: column plus direction.
            $table->string('sort_by', 40)->default('updated_at');
            $table->enum('sort_direction', ['asc', 'desc'])->default('desc');
            // Columns the list shows, in order.
            $table->json('columns')->nullable();

            // Only members of the owning team see a team-scoped queue.
            $table->boolean('is_shared')->default(true);
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};
