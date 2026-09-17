<?php

declare(strict_types=1);

namespace App\Services\RichText;

use Illuminate\Support\Facades\DB;

/**
 * Converts a column of stored plain text into rich text, in place.
 *
 * Its own class rather than a private method on one migration because more
 * than one migration needs it and because a migration is the one kind of code
 * that gets run once, on somebody else's data, with no way to check the result
 * afterwards. Here it can be tested.
 *
 * Everything already in the database is plain text — typed into a textarea, or
 * flattened out of an inbound mail — so it is wrapped in the markup it was
 * already implying, and anything that looks like a tag is escaped. A customer
 * who wrote "<3" still sees "<3" afterwards, and one who pasted a stack trace
 * does not have half of it interpreted.
 */
class RichTextBackfill
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * Wrap stored plain text in markup, and record the text beside it.
     *
     * @param  string  $html  the column holding the content, which becomes rich text
     * @param  string|null  $text  the companion column to fill with the flattened text
     */
    public function convert(string $table, string $html, ?string $text = null): void
    {
        $this->each($table, $html, function (int|string $id, string $value) use ($table, $html, $text): void {
            $update = [$html => $this->sanitizer->fromPlainText($value)];

            if ($text !== null) {
                $update[$text] = trim($value);
            }

            DB::table($table)->where('id', $id)->update($update);
        });
    }

    /**
     * The other direction, for a rollback: put the words back where the markup
     * is, so a downgrade lands on something a textarea can render rather than
     * on tag soup.
     */
    public function flatten(string $table, string $html): void
    {
        $this->each($table, $html, function (int|string $id, string $value) use ($table, $html): void {
            DB::table($table)->where('id', $id)->update([$html => $this->sanitizer->toText($value)]);
        });
    }

    /**
     * @param  callable(int|string, string): void  $handle
     */
    private function each(string $table, string $column, callable $handle): void
    {
        DB::table($table)
            ->select('id', $column)
            ->orderBy('id')
            // Chunked: a desk with a hundred thousand tickets should not need a
            // hundred thousand rows in memory to be upgraded.
            ->chunkById(500, function ($rows) use ($column, $handle): void {
                foreach ($rows as $row) {
                    $value = (string) ($row->{$column} ?? '');

                    // An empty column stays exactly as it is, NULL included.
                    // Writing '' over a NULL would be a change nobody asked
                    // for, on every empty row in the table.
                    if (trim($value) === '') {
                        continue;
                    }

                    $handle($row->id, $value);
                }
            });
    }
}
