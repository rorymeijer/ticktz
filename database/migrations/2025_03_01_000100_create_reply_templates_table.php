<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The replies a desk sends over and over.
 *
 * One table, two readers. An agent opens the reply box and picks one; an
 * automation rule sends one on its own. That is deliberate — a desk that
 * keeps its canned answers in two places ends up with two versions of the
 * same apology, and the one the robot sends is always the stale one.
 *
 * The body is rich text with `{{ placeholder }}` tokens, substituted at the
 * moment of use against the ticket it is being used on. Substitution, never
 * evaluation: these are written by service desk managers and stored in the
 * database, so they must not be able to execute anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_templates', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('description', 255)->nullable();

            $table->longText('body');
            $table->longText('body_text')->nullable();

            // Null means every team. A template scoped to a team is offered to
            // its members and to the rules that act on its tickets, which is
            // how one desk keeps a finance tone of voice out of a hardware
            // reply without giving finance a second admin screen.
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // Null means every language. Otherwise the template is only
            // offered to an agent whose *requester* reads that language — the
            // agent's own language is beside the point, since it is the
            // customer who has to read the reply.
            $table->string('locale', 10)->nullable();

            // An internal template is a note, never a reply. The distinction
            // is carried by the template rather than chosen at send time: a
            // "escalated to the supplier, do not tell the customer yet" note
            // that can be sent to the customer by a mis-click is exactly the
            // mistake canned text is supposed to prevent.
            $table->boolean('is_internal')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'position']);
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_templates');
    }
};
