<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer portal: what a requester can ask for, and how that question is
 * shaped into a ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->string('slug', 64)->unique();
            $table->string('description', 500)->nullable();
            $table->json('description_translations')->nullable();
            // Name from the built-in icon set, e.g. "server".
            $table->string('icon', 40)->nullable();
            $table->string('color', 7)->default('#4f46e5');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('request_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->string('slug', 64)->unique();
            $table->string('description', 500)->nullable();
            $table->json('description_translations')->nullable();
            // Shown above the form: what to have ready, what happens next.
            $table->text('instructions')->nullable();
            $table->json('instructions_translations')->nullable();
            $table->string('icon', 40)->nullable();

            // How a submission becomes a ticket.
            $table->foreignId('queue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            // Lets the requester pick a priority instead of forcing the default.
            $table->boolean('allow_priority_choice')->default(false);
            // Template for the ticket subject, e.g. "New laptop for :employee".
            $table->string('subject_template')->nullable();

            // Who may submit this request type.
            $table->enum('visibility', ['everyone', 'organizations', 'agents'])->default('everyone');
            $table->json('organization_ids')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
            $table->index('portal_category_id');
        });

        // Which custom fields a request type's form asks for, in which order.
        Schema::create('request_type_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            // Overrides the field's own default for this form only.
            $table->boolean('is_required')->nullable();
            $table->string('help_text', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['request_type_id', 'custom_field_id'], 'request_type_field_unique');
            $table->index('custom_field_id');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('request_type_id')->nullable()->after('queue_id')
                ->constrained()->nullOnDelete();

            $table->index('request_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('request_type_id');
        });

        Schema::dropIfExists('request_type_fields');
        Schema::dropIfExists('request_types');
        Schema::dropIfExists('portal_categories');
    }
};
