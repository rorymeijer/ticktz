<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets: the things the desk is asked about.
 *
 * A configuration management database earns its keep in one moment — an agent
 * opens a ticket about a laptop and can see whose it is, what it is plugged
 * into and what else broke last month. Everything here is shaped by that
 * moment rather than by the ambition of modelling an entire estate.
 *
 * Three decisions are visible in the schema:
 *
 * - **`asset_tag` is the human key**, unique and stamped on the sticker, the
 *   way `tickets.key` is. It is what somebody reads off the underside of a
 *   laptop and types into a search box, and it is what a CSV import matches on
 *   so importing the same export twice updates rather than duplicates.
 * - **Custom attributes reuse `custom_fields`**, which already carries an
 *   `entity` column with `asset` in it. An asset type declares which of them
 *   it wants, exactly as a request type declares its form — one field editor,
 *   one validation path, one place this can go wrong.
 * - **Relations are stored once, in one direction.** The other side is derived
 *   when rendering, like `ticket_links`: a row per direction would let the two
 *   halves of "installed on" disagree with each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->string('slug', 64)->unique();
            $table->string('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 9)->default('#64748b');
            // Prefix for generated tags: LAP-0001, SRV-0001. An asset type
            // without one falls back to the instance-wide default.
            $table->string('tag_prefix', 12)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        // Which custom fields an asset type asks for — the same shape as
        // `request_type_fields`, because it is the same idea.
        Schema::create('asset_type_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->nullable();
            $table->string('help_text')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['asset_type_id', 'custom_field_id'], 'asset_type_field_unique');
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_type_id')->constrained()->cascadeOnDelete();
            // The sticker on the box. Unique, searchable, and what an import
            // matches on.
            $table->string('asset_tag', 64)->unique();
            $table->string('name');
            $table->string('serial_number', 128)->nullable();
            $table->string('manufacturer', 128)->nullable();
            $table->string('model', 128)->nullable();
            // in_stock → in_use → in_repair → retired → disposed. Not a
            // workflow: an asset's life is short enough to name outright, and
            // a configurable one would be a second workflow engine.
            $table->enum('status', ['in_stock', 'in_use', 'in_repair', 'retired', 'disposed'])
                ->default('in_stock');
            $table->string('location')->nullable();
            // Who has it, and which customer it belongs to. Both nullable: a
            // laptop in a cupboard has neither.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->date('purchased_at')->nullable();
            $table->date('warranty_ends_at')->nullable();
            // Stored in cents, like every other money column should be.
            $table->unsignedBigInteger('purchase_cost')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Soft-deleted: an asset a ticket was raised about is part of that
            // ticket's history, and erasing it leaves the link dangling.
            $table->softDeletes();

            $table->index(['asset_type_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index('warranty_ends_at');
            $table->index('serial_number');
        });

        Schema::create('asset_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_asset_id')->constrained('assets')->cascadeOnDelete();
            // Stored one-way; the inverse is derived when rendering, so the
            // two halves of a relationship can never disagree.
            $table->enum('type', ['connected_to', 'installed_on', 'part_of', 'depends_on', 'backs_up'])
                ->default('connected_to');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'related_asset_id', 'type'], 'asset_relation_unique');
            $table->index('related_asset_id');
        });

        Schema::create('asset_ticket', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'ticket_id']);
            $table->index('ticket_id');
        });

        // MySQL only: free-text search across the fields somebody actually
        // remembers about a machine. SQLite falls back to LIKE — see
        // Asset::scopeSearch().
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE assets ADD FULLTEXT assets_search (name, serial_number, model, manufacturer, location, notes)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_ticket');
        Schema::dropIfExists('asset_relations');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_type_fields');
        Schema::dropIfExists('asset_types');
    }
};
