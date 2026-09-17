<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime settings an administrator can change without editing `.env` or
 * restarting containers. Read through App\Services\SettingsRepository, which
 * caches the whole table in Redis and flushes on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 50)->default('general');
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'integer', 'boolean', 'json', 'array'])->default('string');
            // Settings safe to expose to the browser (branding, portal toggles).
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
