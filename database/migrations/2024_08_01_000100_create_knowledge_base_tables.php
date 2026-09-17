<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base: what the desk knows, written down once.
 *
 * Two things in here are load-bearing beyond the obvious:
 *
 * - `visibility` exists on both categories and articles, and the portal reads
 *   it on every query. An internal article leaking onto the portal is the
 *   failure this feature must not have.
 * - `body` is sanitised HTML and `body_text` is the same content flattened.
 *   Search runs on the flattened copy so a query never matches a tag name or
 *   an attribute value, and the excerpt an administrator did not write can be
 *   generated without parsing markup on every list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->string('slug', 128)->unique();
            $table->string('description')->nullable();
            $table->json('description_translations')->nullable();
            $table->string('icon', 64)->nullable();
            // One level of nesting is allowed; deeper turns a help centre into
            // a filing cabinet nobody opens.
            $table->foreignId('parent_id')->nullable()
                ->constrained('kb_categories')->nullOnDelete();
            // public: visible on the portal. internal: agents only.
            $table->enum('visibility', ['public', 'internal'])->default('public');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();

            $table->index(['is_active', 'visibility', 'position']);
        });

        Schema::create('kb_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kb_category_id')->nullable()
                ->constrained('kb_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug', 160)->unique();
            // Written by the author, or generated from the body when they did
            // not bother. Shown in search results and suggestions.
            $table->string('excerpt', 500)->nullable();
            // Sanitised on the way in — never on the way out. A body that is
            // safe in the database is safe everywhere it is rendered, and
            // anything else means remembering to escape at every call site.
            $table->longText('body');
            // The same content without markup, for searching and excerpting.
            $table->longText('body_text');

            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            // An article inherits nothing: a public article in an internal
            // category is still checked against the category on read, but the
            // article's own setting is what an author edits.
            $table->enum('visibility', ['public', 'internal'])->default('public');
            // NULL means "any language". A reader sees articles in their own
            // language plus the ones marked for everybody.
            $table->string('locale', 8)->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            // Bumped on every save so a version number never has to be derived
            // by counting rows.
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'visibility', 'locale']);
            $table->index(['kb_category_id', 'position']);
        });

        // Free-text search. MySQL gets a real index; SQLite (the test database)
        // falls back to LIKE in the query builder.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE kb_articles ADD FULLTEXT kb_articles_search (title, excerpt, body_text)');
        }

        Schema::create('kb_article_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kb_article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->string('excerpt', 500)->nullable();
            $table->longText('body');
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            // What changed, in the editor's words. Optional, and worth asking
            // for: "fixed the VPN port" beats "version 7" a year later.
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['kb_article_id', 'version']);
            $table->index(['kb_article_id', 'created_at']);
        });

        // Article <-> ticket. Agents link the article they answered with, which
        // is how "what do we get asked most" stops being a guess.
        Schema::create('kb_article_ticket', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kb_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kb_article_id', 'ticket_id']);
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_article_ticket');
        Schema::dropIfExists('kb_article_versions');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
