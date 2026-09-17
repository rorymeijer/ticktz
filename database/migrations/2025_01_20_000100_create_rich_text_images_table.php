<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images pasted into a rich text field.
 *
 * Deliberately not the `attachments` table, though it was the obvious place to
 * look first. An attachment belongs to a ticket — the column is not nullable
 * and the policy is written around it — and an image pasted into a knowledge
 * base article belongs to no ticket at all, while one pasted into a ticket
 * that has not been filed yet belongs to nothing whatsoever. Bending
 * attachments to cover both would have made `ticket_id` nullable, which is the
 * column the whole attachment policy stands on.
 *
 * So: its own table, with a polymorphic owner that is filled in when the thing
 * it was pasted into is saved, and null until then.
 *
 * The `uuid` is what appears in a URL. Sequential ids in an image src would let
 * anybody count how many screenshots the desk holds and walk them one by one;
 * the policy would refuse each, but the count itself is not theirs to have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rich_text_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64)->nullable();

            // Who pasted it. Also who — and only who — may see it until the
            // thing it was pasted into has been saved.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The record whose text references this image: a Ticket, a Comment,
            // a KbArticle, an Asset. Null while the draft is still a draft.
            $table->nullableMorphs('owner');

            // Mirrors the visibility of the comment it landed on, so an image
            // in an internal note is not readable by the requester who can
            // read the ticket.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            // Sweeping unclaimed uploads reads this.
            $table->index(['owner_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rich_text_images');
    }
};
