<?php

declare(strict_types=1);

use App\Services\RichText\RichTextBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket descriptions and comment bodies become rich text.
 *
 * Three things happen here, and the order matters:
 *
 * 1. Each column gains a plain-text companion. That is what the FULLTEXT index
 *    covers and what the plain-text part of an outgoing e-mail carries.
 * 2. Everything already stored is converted. It is plain text — typed into a
 *    textarea, or flattened out of an inbound mail — so it is wrapped in the
 *    markup it was already implying: blank lines become paragraphs, single
 *    newlines become breaks, and anything that looks like a tag is escaped so
 *    a customer who wrote "<3" still sees "<3" afterwards.
 * 3. The index moves off the markup. Left where it was, every tag name would
 *    be a token: a search for "code" would match every ticket containing a
 *    code block, and one for "strong" would match half the desk.
 *
 * On a large desk step 3 rebuilds a FULLTEXT index and takes a while. There is
 * no way around it and no way to do it online, which is why it is called out
 * in the upgrade notes rather than left to be discovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->longText('description_text')->nullable()->after('description');
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->longText('body_text')->nullable()->after('body');
        });

        $backfill = app(RichTextBackfill::class);

        $backfill->convert('tickets', 'description', 'description_text');
        $backfill->convert('comments', 'body', 'body_text');

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tickets DROP INDEX ticket_search');
            DB::statement('ALTER TABLE tickets ADD FULLTEXT ticket_search (subject, description_text)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tickets DROP INDEX ticket_search');
        }

        // Put the words back where the markup is, so a downgrade lands on
        // something a textarea can render rather than on tag soup.
        $backfill = app(RichTextBackfill::class);

        $backfill->flatten('tickets', 'description');
        $backfill->flatten('comments', 'body');

        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('description_text'));
        Schema::table('comments', fn (Blueprint $table) => $table->dropColumn('body_text'));

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tickets ADD FULLTEXT ticket_search (subject, description)');
        }
    }
};
