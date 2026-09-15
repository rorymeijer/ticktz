<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vocabulary a ticket is described with: statuses, priorities and labels.
 *
 * All three are data rather than enums so an organisation can model its own
 * process, but every status carries a `category` the application reasons
 * about (an SLA clock cares that a status means "waiting for the customer",
 * not what it is called).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            // What the status *means* to the engine.
            $table->enum('category', ['new', 'open', 'pending', 'resolved', 'closed'])->default('open');
            $table->string('color', 7)->default('#64748b');
            $table->string('description')->nullable();
            // Stops the SLA clock: "waiting for customer", "waiting for vendor".
            $table->boolean('pauses_sla')->default(false);
            // Visible to requesters on the portal (some internal states are not).
            $table->boolean('is_public')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['category', 'position']);
        });

        Schema::create('priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            // 1 = most urgent. Used for ordering and SLA goal lookup.
            $table->unsignedTinyInteger('level')->default(3);
            $table->string('color', 7)->default('#64748b');
            $table->boolean('is_default')->default(false);
            // Offered to requesters on the portal.
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('level');
        });

        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('color', 7)->default('#64748b');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('ticket_statuses');
    }
};
