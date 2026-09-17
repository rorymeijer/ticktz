<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bringing an estate in from a spreadsheet.
 *
 * Nobody starts a CMDB empty. The first thing a desk does is export whatever
 * they have been keeping in Excel, and the shape of that file is never the
 * shape of this database — so the import is built around three things:
 *
 * - **A dry run that reports rather than writes.** The operator sees exactly
 *   what would be created, what would be updated, and which rows are wrong,
 *   before anything touches the table. An import that only tells you what it
 *   did is one you have to undo by hand.
 * - **Matching on `asset_tag`, so it is idempotent.** Importing the same
 *   export twice updates the rows rather than doubling the estate — which is
 *   what actually happens, because the second import is usually somebody
 *   re-running it after fixing three rows.
 * - **Per-row errors, not a failed file.** One malformed date in row 400 must
 *   not reject the other 399. The bad rows come back with their line numbers
 *   and the file can be fixed and re-run.
 */
class AssetImporter
{
    /** Columns the importer understands. Everything else is ignored. */
    public const COLUMNS = [
        'asset_tag', 'name', 'type', 'serial_number', 'manufacturer', 'model', 'status',
        'location', 'assigned_to', 'organization', 'purchased_at', 'warranty_ends_at',
        'purchase_cost', 'currency', 'notes',
    ];

    /** More than this and it is a migration, not an import. */
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly AssetService $assets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Read a CSV into rows keyed by the header.
     *
     * @return array<int, array<string, string>>
     */
    public function parse(string $contents): array
    {
        // Excel on Windows writes a BOM, and a BOM on the first header turns
        // `asset_tag` into `\xEF\xBB\xBFasset_tag`, which matches nothing.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = $this->sniffDelimiter($contents);
        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(
            static fn ($column) => str_replace(' ', '_', mb_strtolower(trim((string) $column))),
            $header,
        );

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && count($rows) < self::MAX_ROWS) {
            // A trailing newline produces a row of one empty cell.
            if ($row === [null] || $row === ['']) {
                continue;
            }

            $rows[] = array_combine(
                $header,
                array_pad(array_slice($row, 0, count($header)), count($header), ''),
            );
        }

        fclose($handle);

        return $rows;
    }

    /**
     * What this file would do, without doing it.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{create: int, update: int, errors: array<int, array{line: int, message: string}>, preview: array<int, array<string, mixed>>}
     */
    public function dryRun(array $rows, ?AssetType $default = null): array
    {
        $types = $this->typesByKey();
        $create = 0;
        $update = 0;
        $errors = [];
        $preview = [];

        $existing = $this->existingTags($rows);
        // Tags created earlier in this same file count as existing, or a file
        // listing the same tag twice reports two creations and performs one.
        $seen = [];

        foreach ($rows as $index => $row) {
            // +2: one for the header, one because humans count from one.
            $line = $index + 2;

            try {
                $resolved = $this->resolve($row, $types, $default);
            } catch (Throwable $exception) {
                $errors[] = ['line' => $line, 'message' => $exception->getMessage()];

                continue;
            }

            $tag = $resolved['asset_tag'];
            $isUpdate = $tag !== null && (isset($existing[$tag]) || isset($seen[$tag]));

            $isUpdate ? $update++ : $create++;

            if ($tag !== null) {
                $seen[$tag] = true;
            }

            if (count($preview) < 20) {
                $preview[] = [
                    'line' => $line,
                    'action' => $isUpdate ? 'update' : 'create',
                    'asset_tag' => $tag,
                    'name' => $resolved['name'],
                    'type' => $resolved['type']->translatedName(),
                    'status' => $resolved['status'],
                ];
            }
        }

        return ['create' => $create, 'update' => $update, 'errors' => $errors, 'preview' => $preview];
    }

    /**
     * Do it.
     *
     * Each row is its own transaction rather than the file being one: a single
     * bad row in the middle of four hundred good ones should not roll back an
     * afternoon's work, and the operator has already seen the dry run.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{created: int, updated: int, errors: array<int, array{line: int, message: string}>}
     */
    public function import(array $rows, ?AssetType $default = null, ?User $actor = null): array
    {
        $types = $this->typesByKey();
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;

            try {
                $resolved = $this->resolve($row, $types, $default);

                DB::transaction(function () use ($resolved, $actor, &$created, &$updated): void {
                    $existing = $resolved['asset_tag'] === null
                        ? null
                        : Asset::query()->where('asset_tag', $resolved['asset_tag'])->first();

                    if ($existing) {
                        // Only the columns this file actually carried. A CSV
                        // with no `location` column must not blank the
                        // locations somebody typed in by hand.
                        $this->assets->update($existing, $resolved['attributes'], $actor);
                        $updated++;

                        return;
                    }

                    $this->assets->create($resolved['attributes'] + [
                        'type' => $resolved['type'],
                        'asset_tag' => $resolved['asset_tag'],
                    ], $actor);

                    $created++;
                });
            } catch (Throwable $exception) {
                $errors[] = ['line' => $line, 'message' => $exception->getMessage()];
            }
        }

        $this->audit->logGlobal(
            'assets',
            'asset.imported',
            "Imported assets from CSV: {$created} created, {$updated} updated",
            null,
            ['created' => $created, 'updated' => $updated, 'errors' => count($errors)],
        );

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * Turn one row into something the service can accept.
     *
     * @param  array<string, string>  $row
     * @param  Collection<string, AssetType>  $types
     * @return array{asset_tag: string|null, name: string, status: string, type: AssetType, attributes: array<string, mixed>}
     */
    private function resolve(array $row, Collection $types, ?AssetType $default): array
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException(__('assets.import.errors.no_name'));
        }

        $type = $this->resolveType($row['type'] ?? null, $types, $default);

        $status = mb_strtolower(trim((string) ($row['status'] ?? '')));
        $status = str_replace([' ', '-'], '_', $status);

        if ($status !== '' && ! in_array($status, Asset::STATUSES, true)) {
            throw new \RuntimeException(__('assets.import.errors.unknown_status', ['status' => $status]));
        }

        $attributes = array_filter([
            'asset_type_id' => $type->getKey(),
            'name' => $name,
            'serial_number' => $this->value($row, 'serial_number'),
            'manufacturer' => $this->value($row, 'manufacturer'),
            'model' => $this->value($row, 'model'),
            'status' => $status ?: Asset::IN_STOCK,
            'location' => $this->value($row, 'location'),
            'assigned_to' => $this->resolveUser($row['assigned_to'] ?? null),
            'organization_id' => $this->resolveOrganization($row['organization'] ?? null),
            'purchased_at' => $this->date($row, 'purchased_at'),
            'warranty_ends_at' => $this->date($row, 'warranty_ends_at'),
            'purchase_cost' => $this->money($row['purchase_cost'] ?? null),
            'currency' => $this->value($row, 'currency'),
            'notes' => $this->value($row, 'notes'),
        ], static fn ($value) => $value !== null);

        $tag = mb_strtoupper(trim((string) ($row['asset_tag'] ?? '')));

        return [
            'asset_tag' => $tag === '' ? null : $tag,
            'name' => $name,
            'status' => $attributes['status'],
            'type' => $type,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param  Collection<string, AssetType>  $types
     */
    private function resolveType(?string $given, Collection $types, ?AssetType $default): AssetType
    {
        $given = mb_strtolower(trim((string) $given));

        if ($given === '') {
            if ($default === null) {
                throw new \RuntimeException(__('assets.import.errors.no_type'));
            }

            return $default;
        }

        // Matched on name or slug, because an export written by a human says
        // "Laptop" and one written by a system says "laptop".
        $type = $types->get($given);

        if ($type === null) {
            throw new \RuntimeException(__('assets.import.errors.unknown_type', ['type' => $given]));
        }

        return $type;
    }

    private function resolveUser(?string $given): ?int
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($given)])
            ->orWhere('username', $given)
            ->first();

        if ($user === null) {
            throw new \RuntimeException(__('assets.import.errors.unknown_user', ['user' => $given]));
        }

        return $user->getKey();
    }

    private function resolveOrganization(?string $given): ?int
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        $organization = Organization::query()
            ->where('name', $given)
            ->orWhere('slug', mb_strtolower($given))
            ->first();

        if ($organization === null) {
            throw new \RuntimeException(__('assets.import.errors.unknown_organization', ['organization' => $given]));
        }

        return $organization->getKey();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function date(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        if ($value === '') {
            return null;
        }

        // Dutch spreadsheets write 31-12-2026 and everything else writes
        // 2026-12-31. Both are unambiguous once you know which is which, and
        // a desk that has to reformat its own export before importing it will
        // not import it.
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'Y/m/d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);

            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        throw new \RuntimeException(__('assets.import.errors.bad_date', ['value' => $value, 'column' => $key]));
    }

    private function money(?string $given): ?int
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        // "€ 1.299,00" and "1299.00" are the same number written by two
        // different spreadsheets.
        $clean = preg_replace('/[^0-9,.\-]/', '', $given) ?? '';

        if ($clean === '') {
            return null;
        }

        // Whichever separator comes last is the decimal one.
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return (int) round(((float) $clean) * 100);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function value(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return Collection<string, AssetType>
     */
    private function typesByKey(): Collection
    {
        // `toBase()` is load-bearing. Eloquent's Collection overrides merge()
        // to combine by model key rather than by array key, so merging two
        // string-keyed maps of models silently re-keys the result by id and
        // every lookup by name misses.
        $types = AssetType::query()->get()->toBase();

        return $types
            ->mapWithKeys(fn (AssetType $type) => [mb_strtolower($type->name) => $type])
            ->merge($types->mapWithKeys(fn (AssetType $type) => [mb_strtolower($type->slug) => $type]));
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, true>
     */
    private function existingTags(array $rows): array
    {
        $tags = collect($rows)
            ->map(fn (array $row) => mb_strtoupper(trim((string) ($row['asset_tag'] ?? ''))))
            ->filter()
            ->unique()
            ->all();

        if ($tags === []) {
            return [];
        }

        return Asset::query()
            ->whereIn('asset_tag', $tags)
            ->pluck('asset_tag')
            ->mapWithKeys(fn (string $tag) => [$tag => true])
            ->all();
    }

    private function sniffDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n") ?: '';

        // A Dutch Excel export is semicolon-separated, and a desk that has to
        // convert its own export before importing it will not import it.
        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }
}
