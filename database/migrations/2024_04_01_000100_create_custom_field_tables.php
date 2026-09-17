<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable custom fields.
 *
 * A field is defined once and attached wherever it is needed — a request type's
 * form, a ticket, an asset type. Values are stored polymorphically so adding a
 * field never means an ALTER TABLE on a table with a million rows.
 *
 * The trade-off is deliberate: reads cost a join, writes cost nothing, and an
 * administrator can add a field at 16:45 on a Friday without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->id();
            // Stable identifier used in automation conditions and the API.
            $table->string('key', 64)->unique();
            $table->string('label');
            // Per-locale overrides: {"nl": "Kostenplaats"}. Falls back to label.
            $table->json('label_translations')->nullable();

            $table->enum('type', [
                'text', 'textarea', 'number', 'date', 'datetime',
                'select', 'multiselect', 'checkbox', 'email', 'url', 'user',
            ])->default('text');

            // For select/multiselect: [{"value": "vps", "label": "VPS"}, ...].
            $table->json('options')->nullable();

            $table->string('placeholder')->nullable();
            $table->string('help_text', 500)->nullable();
            $table->text('default_value')->nullable();

            $table->boolean('is_required')->default(false);
            // Shown to requesters on the portal, or agents-only.
            $table->boolean('is_public')->default(true);

            // Extra rules: {"min": 1, "max": 100, "regex": "..."}.
            $table->json('validation')->nullable();

            // Which kind of record this field can be attached to.
            $table->enum('entity', ['ticket', 'asset'])->default('ticket');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['entity', 'is_active', 'position']);
        });

        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            // Scalars are stored as text; multiselect stores a JSON array.
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'entity_type', 'entity_id'], 'custom_field_value_unique');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
