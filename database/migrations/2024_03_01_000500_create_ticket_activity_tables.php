<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that hangs off a ticket: the conversation, files, watchers,
 * labels and relationships between tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->longText('body');
            // An internal note is never rendered on the portal and never
            // included in an outgoing notification to the requester.
            $table->boolean('is_internal')->default(false);
            $table->enum('source', ['web', 'email', 'api', 'automation', 'system'])->default('web');

            // Set for replies that arrived by e-mail (phase 4); used to thread
            // conversations and to keep inbound processing idempotent.
            $table->string('email_message_id', 500)->nullable();

            $table->timestamp('edited_at')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['ticket_id', 'created_at']);
            $table->index(['ticket_id', 'is_internal']);
            $table->index('email_message_id');
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            // SHA-256 of the stored file; lets us spot duplicates and verify
            // an archive restore.
            $table->string('checksum', 64)->nullable();
            // An internal attachment follows the visibility of its comment.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
            $table->index('comment_id');
        });

        Schema::create('ticket_watchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Watching because you were added, or because you commented.
            $table->boolean('is_automatic')->default(false);
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('ticket_labels', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();

            $table->primary(['ticket_id', 'label_id']);
            $table->index('label_id');
        });

        Schema::create('ticket_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_ticket_id')->constrained('tickets')->cascadeOnDelete();
            // Stored one-way; the inverse is derived when rendering.
            $table->enum('type', ['relates', 'duplicates', 'blocks', 'causes', 'parent'])->default('relates');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'related_ticket_id', 'type'], 'ticket_link_unique');
            $table->index('related_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_links');
        Schema::dropIfExists('ticket_labels');
        Schema::dropIfExists('ticket_watchers');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('comments');
    }
};
