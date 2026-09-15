<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\User;
use App\Services\Assets\AssetImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bringing an estate in from a spreadsheet.
 *
 * Two steps, always. The upload reports what *would* happen — how many rows
 * create, how many update, and which ones are wrong — and only a second,
 * explicit request writes anything. An import that acts on the first click is
 * one the operator has to undo by hand, and nobody has ever undone four
 * hundred rows by hand.
 *
 * The parsed rows are carried in the session between the two steps rather than
 * the file being stored: the file is somebody's asset register, it is only
 * needed for the next thirty seconds, and a copy of it sitting on disk is one
 * more thing to protect.
 */
class AssetImportController extends Controller
{
    private const SESSION_KEY = 'assets.import.rows';

    public function __construct(private readonly AssetImporter $importer) {}

    public function show(Request $request): Response
    {
        $this->authorize('assets.import');

        return Inertia::render('Admin/Assets/Import', [
            'types' => AssetType::query()->where('is_active', true)->orderBy('name')
                ->get()->map(fn (AssetType $type) => $type->toSummaryArray())->all(),
            'columns' => AssetImporter::COLUMNS,
            'maxRows' => AssetImporter::MAX_ROWS,
            'result' => $request->session()->get('assets.import.result'),
            'pending' => $request->session()->has(self::SESSION_KEY),
        ]);
    }

    /**
     * Step one: read the file and say what it would do.
     */
    public function preview(Request $request): RedirectResponse
    {
        $this->authorize('assets.import');

        $data = $request->validate([
            // `csv` is deliberately not in the mime list: browsers report a
            // CSV as text/plain, application/csv or application/vnd.ms-excel
            // depending on the operating system, and a file rejected for
            // being the thing it is teaches people to rename their files.
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'asset_type_id' => ['nullable', 'integer', Rule::exists('asset_types', 'id')],
        ]);

        $rows = $this->importer->parse((string) file_get_contents($data['file']->getRealPath()));

        if ($rows === []) {
            return back()->with('error', __('assets.import.errors.empty'));
        }

        $default = empty($data['asset_type_id'])
            ? null
            : AssetType::query()->find($data['asset_type_id']);

        $result = $this->importer->dryRun($rows, $default);

        return back()->with([
            self::SESSION_KEY => $rows,
            'assets.import.type' => $data['asset_type_id'] ?? null,
            'assets.import.result' => $result + ['stage' => 'preview'],
        ]);
    }

    /**
     * Step two: do it.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('assets.import');

        $rows = $request->session()->pull(self::SESSION_KEY, []);
        $typeId = $request->session()->pull('assets.import.type');

        if ($rows === []) {
            return back()->with('error', __('assets.import.errors.expired'));
        }

        /** @var User $user */
        $user = $request->user();

        $default = $typeId ? AssetType::query()->find($typeId) : null;

        $result = $this->importer->import($rows, $default, $user);

        return back()->with([
            'assets.import.result' => $result + ['stage' => 'done'],
            'success' => __('assets.import.done', [
                'created' => $result['created'],
                'updated' => $result['updated'],
            ]),
        ]);
    }
}
