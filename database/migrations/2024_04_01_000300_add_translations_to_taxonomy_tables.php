<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statuses, priorities and labels are shown to requesters on the portal, so
 * their names have to follow the reader's language like every other string in
 * the product. The base `name` stays the fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ticket_statuses', 'priorities', 'labels'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('name_translations')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        foreach (['ticket_statuses', 'priorities', 'labels'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('name_translations');
            });
        }
    }
};
