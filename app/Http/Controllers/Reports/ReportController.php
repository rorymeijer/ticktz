<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\DailyMetric;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reporting screen.
 *
 * One page, four reports, and the filters in the query string — so a report is
 * a URL somebody can send to a colleague, and a saved report is that URL given
 * a name. That is also why a saved report stores its filters rather than its
 * figures: "SLA compliance, servicedesk, this month" is only worth saving if
 * it says what it says *today*.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportBuilder $reports,
        private readonly ReportExporter $exporter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('reports.view');

        /** @var User $user */
        $user = $request->user();

        $report = $this->reportName($request);
        $filters = $this->filters($request);

        return Inertia::render('Reports/Index', [
            'data' => $this->reports->build($report, $filters),
            'filters' => $filters + ['report' => $report],
            'options' => [
                'reports' => ReportBuilder::REPORTS,
                'dimensions' => DailyMetric::TABLE_DIMENSIONS,
            ],
            'saved' => SavedReport::query()
                ->visibleTo($user)
                ->with('author')
                ->orderBy('name')
                ->get()
                ->map(fn (SavedReport $saved) => $saved->toSummaryArray() + [
                    'is_mine' => (int) $saved->created_by === (int) $user->getKey(),
                ])
                ->all(),
            'can' => ['manage' => $user->can('reports.manage')],
        ]);
    }

    /**
     * CSV out: either the report's own figures, or the tickets behind them.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('reports.view');
        // Exporting a year of tickets is exporting the desk's whole record of
        // who asked for what, so it is held to the same permission the ticket
        // list's own export is.
        $this->authorize('tickets.export');

        $filters = $this->filters($request);

        return $request->string('subject')->toString() === 'tickets'
            ? $this->exporter->tickets($filters)
            : $this->exporter->summary($this->reportName($request), $filters);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('reports.view');

        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'report' => ['required', Rule::in(SavedReport::REPORTS)],
            'filters' => ['array'],
            'is_shared' => ['boolean'],
        ]);

        // Sharing a report puts it on everybody's reporting screen, which is a
        // change to other people's workspace rather than to your own.
        $shared = ($data['is_shared'] ?? false) && $user->can('reports.manage');

        SavedReport::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'report' => $data['report'],
            'filters' => $this->filterable($data['filters'] ?? []),
            'created_by' => $user->getKey(),
            'is_shared' => $shared,
        ]);

        return back()->with('success', __('reports.saved.created', ['name' => $data['name']]));
    }

    public function destroy(Request $request, SavedReport $report): RedirectResponse
    {
        $this->authorize('reports.view');

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            (int) $report->created_by === (int) $user->getKey() || $user->can('reports.manage'),
            403,
        );

        $report->delete();

        return back()->with('success', __('reports.saved.deleted'));
    }

    private function reportName(Request $request): string
    {
        $report = $request->string('report')->toString();

        return in_array($report, ReportBuilder::REPORTS, true) ? $report : 'sla';
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        [$from, $to] = $this->reports->period([
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dimension' => $this->reports->dimension(['dimension' => $request->string('dimension')->toString()]),
        ];
    }

    /**
     * Only the keys a report is actually built from are stored.
     *
     * A saved report that kept whatever happened to be in the query string
     * would resurrect stale filters the screen no longer has a control for.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterable(array $filters): array
    {
        [$from, $to] = $this->reports->period($filters);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dimension' => $this->reports->dimension($filters),
        ];
    }
}
