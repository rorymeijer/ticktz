<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public API, and the events it sends back out.
 *
 * `personal_access_tokens` is Sanctum's own table with two columns added:
 *
 * - **`rate_limit`** — a per-token ceiling. One integration polling every
 *   second must not be able to starve the others, and the instance-wide limit
 *   cannot tell them apart.
 * - **`description`** — what this token is for, in words. A list of eight
 *   tokens called "token" is a list nobody dares revoke anything from.
 *
 * Webhooks are two tables on purpose. A **subscription** is configuration: a
 * URL, the events it wants, a secret. A **delivery** is what happened: one row
 * per attempt-set, with the response and the failures. Keeping them apart is
 * what makes "this endpoint has been failing since Tuesday" a query rather
 * than a guess, and it means editing a subscription never rewrites history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            // Hashed, never stored in the clear — the plaintext exists once,
            // in the response that created it.
            $table->string('token', 64)->unique();
            // Sanctum's scopes. A token holds a subset of what its owner may
            // do; it can never be a way to exceed them.
            $table->text('abilities')->nullable();
            $table->string('description')->nullable();
            // Requests per minute for this token alone. NULL falls back to the
            // instance-wide API limit.
            $table->unsignedSmallInteger('rate_limit')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('url', 2048);
            // Which events this endpoint wants. An endpoint that asks for
            // everything gets everything, which is how a quiet integration
            // becomes a noisy one nobody can explain.
            $table->json('events');
            // Used to sign the body. Stored rather than hashed: we have to
            // reproduce the signature on every delivery, so this is a shared
            // secret and not a credential we verify.
            $table->string('secret', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Counters kept on the subscription so the admin list does not
            // aggregate the delivery log once per row.
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload');
            $table->enum('status', ['pending', 'delivered', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            // Truncated on write: a misconfigured endpoint answering with a
            // 200KB HTML error page must not fill the disk one delivery at a
            // time.
            $table->text('response_body')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_subscription_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('personal_access_tokens');
    }
};
