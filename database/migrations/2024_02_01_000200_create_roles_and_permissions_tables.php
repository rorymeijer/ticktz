<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control.
 *
 * Permissions are a fixed, code-defined vocabulary (seeded from
 * App\Support\PermissionCatalog); roles are data and can be created by
 * administrators. A role flagged `is_super` bypasses individual permission
 * checks through a Gate::before hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            // Dotted slug, e.g. "tickets.assign".
            $table->string('name', 100)->unique();
            // Grouping used by the admin UI, e.g. "tickets".
            $table->string('group', 50)->index();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name');
            $table->string('description')->nullable();
            // Which shell the role lands in after signing in.
            $table->enum('scope', ['admin', 'agent', 'requester'])->default('agent');
            $table->boolean('is_super')->default(false);
            // System roles cannot be renamed or deleted, only re-permissioned.
            $table->boolean('is_system')->default(false);
            // Assigned to brand new self-service / auto-provisioned accounts.
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['scope', 'position']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
