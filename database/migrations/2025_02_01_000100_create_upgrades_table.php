<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every upgrade this instance has attempted.
 *
 * A record rather than a log line, for three reasons. It is what the web
 * request writes and the worker reads — the web process never touches the
 * application's own code, so the only thing it can do is ask. It is what the
 * screen polls while an upgrade runs, since the process doing the work is not
 * the one answering the browser. And it is the history: which version this
 * instance came from, when, at whose request, and what the run said if it
 * failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrades', function (Blueprint $table): void {
            $table->id();

            $table->string('from_version', 32);
            $table->string('to_version', 32);

            // queued → running → installed, or failed. No 'rolled_back': code
            // can be put back and migrations cannot, so a rollback is a
            // restore from backup and not a state this row moves into.
            $table->string('status', 16)->default('queued');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // What the run did, appended step by step. The screen reads this
            // while it is running, and somebody reads it afterwards when it is
            // the only account of what happened.
            $table->longText('log')->nullable();
            $table->string('error', 1000)->nullable();

            // Where the previous tree was moved to, so a failed swap can be
            // undone without a download.
            $table->string('backup_path', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrades');
    }
};
