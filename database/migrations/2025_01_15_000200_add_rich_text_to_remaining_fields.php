<?php

declare(strict_types=1);

use App\Services\RichText\RichTextBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the prose fields become rich text.
 *
 * Every one of these is somewhere a person writes sentences for another person
 * to read: what an asset's history is, what a request type expects, what an
 * approver is being asked to agree to, why an approval was refused, and the
 * signature that goes out under every reply.
 *
 * What is *not* here is as deliberate as what is. Names, keys, slugs, e-mail
 * addresses, CSV mappings and automation conditions stay plain text — markup
 * in a name is not formatting, it is a way to make one row look like another.
 * E-mail templates stay plain too: they are written with placeholders and
 * rendered into both parts of a message, and asking somebody editing a
 * notification to think about markup would be a worse editor, not a better one.
 *
 * Each column is widened to LONGTEXT on the way. A TEXT column holds 64KB, and
 * markup inflates what somebody wrote — a note that fitted before could be
 * truncated on the save that adds bold to it.
 */
return new class extends Migration
{
    /**
     * The rich text columns, and the plain-text companion each one needs.
     *
     * Only assets have a companion: their notes sit behind a FULLTEXT index.
     * The others are read, not searched, and a second column nothing queries
     * is a second column to keep in step for no reason.
     *
     * @var array<string, array{0: string, 1: string|null, 2: string|null}> table => [column, text, translations]
     */
    private const FIELDS = [
        'assets' => ['notes', 'notes_text', null],
        'organizations' => ['description', null, null],
        'request_types' => ['instructions', null, 'instructions_translations'],
        'approval_workflows' => ['instructions', null, null],
        'approval_requests' => ['reason', null, null],
        'approval_decisions' => ['comment', null, null],
        'users' => ['signature', null, null],
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $table => [$column, $text, $translations]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $text): void {
                $blueprint->longText($column)->nullable()->change();

                if ($text !== null) {
                    $blueprint->longText($text)->nullable()->after($column);
                }
            });
        }

        $backfill = app(RichTextBackfill::class);

        foreach (self::FIELDS as $table => [$column, $text, $translations]) {
            $backfill->convert($table, $column, $text);

            if ($translations !== null) {
                $backfill->convertTranslations($table, $translations);
            }
        }

        // Multiline custom field values, which share one column with dates,
        // numbers and selects — so only the rows belonging to a textarea field
        // are touched. Converting a date through a paragraph wrapper would be
        // a data loss nobody would notice until a report ran.
        $backfill->convert('custom_field_values', 'value', null, $this->onlyTextareaFields());

        // The asset index moves off the markup for the same reason the ticket
        // one did: left where it was, a search for "code" would match every
        // asset whose notes contain a code block.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE assets DROP INDEX assets_search');
            DB::statement(
                'ALTER TABLE assets ADD FULLTEXT assets_search '
                .'(name, serial_number, model, manufacturer, location, notes_text)',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE assets DROP INDEX assets_search');
        }

        $backfill = app(RichTextBackfill::class);

        foreach (self::FIELDS as $table => [$column, $text, $translations]) {
            $backfill->flatten($table, $column);
        }

        // Same filter as on the way up: flattening every value would rewrite
        // every date and number in the table to no purpose.
        $backfill->flatten('custom_field_values', 'value', $this->onlyTextareaFields());

        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('notes_text'));

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE assets ADD FULLTEXT assets_search '
                .'(name, serial_number, model, manufacturer, location, notes)',
            );
        }
    }

    /**
     * The custom field values that hold prose, of the many kinds that share
     * the `value` column.
     */
    private function onlyTextareaFields(): Closure
    {
        return fn ($query) => $query->whereIn(
            'custom_field_id',
            DB::table('custom_fields')->where('type', 'textarea')->pluck('id'),
        );
    }
};
