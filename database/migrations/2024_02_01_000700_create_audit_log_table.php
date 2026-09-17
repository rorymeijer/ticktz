<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of every mutating action, written through
 * App\Services\AuditLogger. Rows are never updated or deleted by the
 * application — pruning is an operator decision (see docs/self-hosting.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();

            // Who. `actor_type` distinguishes a person from the scheduler, the
            // automation engine, an inbound e-mail or an API token.
            $table->enum('actor_type', ['user', 'system', 'automation', 'email', 'api'])->default('user');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();

            // What.
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 60);
            $table->string('description', 500)->nullable();

            // Before/after for the changed attributes only.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Where from.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_subject_index');
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
