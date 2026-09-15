<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns Laravel's starter `users` table into a service-desk account:
 * directory provenance, organisation membership, agent metadata and the
 * activation flag used instead of hard deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('name');
            $table->foreignId('organization_id')->nullable()->after('locale')
                ->constrained()->nullOnDelete();

            // Where the account came from. `ldap` accounts keep their identity
            // in the directory and cannot edit name/e-mail locally.
            $table->enum('directory', ['local', 'ldap'])->default('local')->after('organization_id');
            $table->string('ldap_dn', 512)->nullable()->after('directory');
            // objectGUID / entryUUID — stable across renames and moves.
            $table->string('ldap_guid', 64)->nullable()->unique()->after('ldap_dn');
            $table->timestamp('ldap_synced_at')->nullable()->after('ldap_guid');

            $table->string('job_title')->nullable()->after('ldap_synced_at');
            $table->string('phone', 50)->nullable()->after('job_title');
            $table->string('timezone', 64)->nullable()->after('phone');
            // Appended to outgoing e-mail replies written by this agent.
            $table->text('signature')->nullable()->after('timezone');

            $table->boolean('is_active')->default(true)->after('signature');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->softDeletes();

            $table->index('is_active');
            $table->index('directory');
            $table->index('organization_id');
        });

        // Passwords are optional: directory-authenticated accounts never have
        // a local hash.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn([
                'username', 'directory', 'ldap_dn', 'ldap_guid', 'ldap_synced_at',
                'job_title', 'phone', 'timezone', 'signature', 'is_active',
                'last_login_at', 'last_login_ip', 'deleted_at',
            ]);
        });
    }
};
