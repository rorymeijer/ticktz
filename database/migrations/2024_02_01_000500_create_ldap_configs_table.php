<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directory connections managed from the admin UI.
 *
 * The bind password is stored encrypted with the application key (cast
 * `encrypted` on the model), never in plaintext and never in the repository.
 * `.env` values remain supported as a fallback for deployments that prefer
 * configuration-as-code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ldap_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // LdapRecord connection key.
            $table->string('connection', 64)->unique();
            $table->boolean('is_enabled')->default(false);

            $table->json('hosts');
            $table->unsignedSmallInteger('port')->default(389);
            $table->enum('encryption', ['none', 'ssl', 'tls'])->default('none');
            $table->unsignedSmallInteger('timeout')->default(5);
            $table->string('base_dn', 512);
            $table->string('bind_dn', 512)->nullable();
            $table->text('bind_password')->nullable();

            // Which attribute a person types at the login prompt, and the
            // filter used to find them.
            $table->string('login_attribute', 64)->default('samaccountname');
            $table->string('user_filter', 512)->nullable();
            // LDAP attribute -> users column, e.g. {"mail": "email"}.
            $table->json('attribute_map');

            $table->boolean('sync_groups')->default(true);
            $table->string('group_base_dn', 512)->nullable();
            $table->string('group_filter', 512)->nullable();
            $table->string('group_member_attribute', 64)->default('memberof');
            // Group DN or CN -> role name, e.g. {"IT Servicedesk": "agent"}.
            $table->json('group_role_map')->nullable();
            $table->foreignId('default_role_id')->nullable()->constrained('roles')->nullOnDelete();

            // Create a Ticktz account on first successful bind.
            $table->boolean('auto_provision')->default(true);
            // Deactivate local accounts that vanished from the directory.
            $table->boolean('deactivate_missing')->default(false);

            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_configs');
    }
};
