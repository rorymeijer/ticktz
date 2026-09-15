<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer organisations. A requester normally belongs to one; tickets inherit
 * the organisation so colleagues can be given visibility of each other's
 * requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // E-mail domains that automatically place a new requester in this
            // organisation, e.g. ["example.org", "example.nl"].
            $table->json('email_domains')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            // When true, every member can see every ticket of the organisation.
            $table->boolean('shared_ticket_visibility')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
