<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail in and out.
 *
 * A channel is one mailbox: where replies are sent from, and where inbound
 * messages are collected. An instance can run several — servicedesk@,
 * facilities@, hr@ — each routing into its own queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            // The address requesters write to and replies come from.
            $table->string('address');
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->boolean('is_active')->default(true);

            // --- Outgoing (SMTP) -------------------------------------------
            // NULL host means "use the application's default mailer".
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->enum('smtp_encryption', ['none', 'ssl', 'tls'])->default('tls');
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();

            // --- Incoming (IMAP) -------------------------------------------
            $table->boolean('imap_enabled')->default(false);
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->enum('imap_encryption', ['none', 'ssl', 'tls', 'starttls'])->default('ssl');
            $table->boolean('imap_validate_cert')->default(true);
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();
            $table->string('imap_folder')->default('INBOX');
            // Where a processed message is moved; NULL leaves it in place and
            // relies on the seen flag.
            $table->string('imap_processed_folder')->nullable();
            $table->boolean('imap_delete_after_processing')->default(false);

            // --- Routing ----------------------------------------------------
            $table->foreignId('queue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('request_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            // Create an account for an unknown sender instead of rejecting.
            $table->boolean('auto_provision_requesters')->default(true);
            // Addresses and patterns whose mail is dropped without a ticket:
            // bounce handlers, out-of-office, no-reply senders.
            $table->json('ignore_senders')->nullable();

            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_poll_result')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            // Which notification this template renders.
            $table->string('key', 64);
            $table->foreignId('email_channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->default('en');
            $table->string('subject', 500);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One template per notification, channel and language. A NULL
            // channel is the instance-wide default.
            $table->unique(['key', 'email_channel_id', 'locale'], 'email_template_unique');
        });

        /**
         * Every message the poller has seen.
         *
         * This table is what makes inbound processing idempotent: a message is
         * recorded by its RFC 5322 Message-ID before a ticket is created, and
         * a second delivery of the same message finds the row and stops.
         */
        Schema::create('inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_channel_id')->constrained()->cascadeOnDelete();

            $table->string('message_id', 500);
            // Hash of the Message-ID: the raw value can exceed an index's key
            // length, and a hash compares just as well for equality.
            $table->char('message_hash', 64);
            $table->string('in_reply_to', 500)->nullable();
            $table->json('references')->nullable();

            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->json('to_addresses')->nullable();
            $table->string('subject', 500)->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            $table->enum('status', ['pending', 'processed', 'ignored', 'failed'])->default('pending');
            $table->string('reason', 500)->nullable();

            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['email_channel_id', 'message_hash'], 'inbound_message_unique');
            $table->index(['status', 'created_at']);
            $table->index('ticket_id');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('email_channel_id')->nullable()->after('request_type_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('email_channel_id');
        });

        Schema::dropIfExists('inbound_messages');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_channels');
    }
};
