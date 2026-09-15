<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV out.
 *
 * Streamed rather than built in memory: an export of a year of tickets is the
 * one somebody will ask for, and a desk at the scale this is built for has
 * enough of them to exhaust a PHP process assembling the string first.
 *
 * Two exports, because people want two different things and giving them one is
 * how a report ends up being re-derived in Excel anyway:
 *
 * - the **summary**, which is the chart as numbers;
 * - the **tickets**, which is the underlying rows, so somebody can pivot it
 *   themselves and answer the question this screen did not anticipate.
 */
class ReportExporter
{
    public function __construct(private readonly ReportBuilder $reports) {}

    /**
     * The report's own figures, one row per day or per group.
     *
     * @param  array<string, mixed>  $filters
     */
    public function summary(string $report, array $filters): StreamedResponse
    {
        $data = $this->reports->build($report, $filters);

        [$header, $rows] = match ($report) {
            'volume' => [
                ['date', 'created', 'resolved', 'reopened'],
                array_map(
                    fn (array $row) => [$row['date'], $row['created'], $row['resolved'], $row['reopened']],
                    $data['series'],
                ),
            ],
            'workload' => [
                ['agent', 'resolved', 'average_resolution_seconds'],
                array_map(
                    fn (array $row) => [$row['label'], $row['resolved'], $row['average_seconds']],
                    $data['rows'],
                ),
            ],
            'cycle_time' => [
                ['date', 'avg_first_response_seconds', 'avg_resolution_seconds'],
                array_map(
                    fn (array $row) => [$row['date'], $row['first_response'], $row['resolution']],
                    $data['series'],
                ),
            ],
            default => [
                ['date', 'metric', 'met', 'breached', 'compliance_percent'],
                $this->slaRows($data),
            ],
        };

        return $this->stream("ticktz-{$report}-{$data['from']}-to-{$data['to']}.csv", $header, $rows);
    }

    /**
     * The tickets behind the figures.
     *
     * Chunked by id rather than paginated: an offset walk over a large table
     * re-scans everything it has already sent, and this is exactly the export
     * somebody runs across a year.
     *
     * @param  array<string, mixed>  $filters
     */
    public function tickets(array $filters): StreamedResponse
    {
        [$from, $to] = $this->reports->period($filters);

        $header = [
            'key', 'subject', 'status', 'priority', 'queue', 'team', 'assignee',
            'requester', 'organization', 'request_type', 'source',
            'created_at', 'first_response_at', 'resolved_at', 'closed_at',
            'reopen_count', 'sla_breached',
        ];

        $query = Ticket::query()
            ->with(['status', 'priority', 'queue', 'team', 'assignee', 'requester', 'organization', 'requestType'])
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()]);

        return response()->streamDownload(
            function () use ($header, $query): void {
                $handle = fopen('php://output', 'w');
                // Excel reads a CSV as the local codepage unless a BOM says
                // otherwise, which turns every Dutch name in the export into
                // mojibake. One three-byte prefix avoids a support ticket.
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $header);

                $query->chunkById(500, function ($tickets) use ($handle): void {
                    foreach ($tickets as $ticket) {
                        fputcsv($handle, [
                            $ticket->key,
                            $ticket->subject,
                            $ticket->status?->name,
                            $ticket->priority?->name,
                            $ticket->queue?->name,
                            $ticket->team?->name,
                            $ticket->assignee?->name,
                            $ticket->requester?->name,
                            $ticket->organization?->name,
                            $ticket->requestType?->name,
                            $ticket->source,
                            $ticket->created_at?->toDateTimeString(),
                            $ticket->first_response_at?->toDateTimeString(),
                            $ticket->resolved_at?->toDateTimeString(),
                            $ticket->closed_at?->toDateTimeString(),
                            $ticket->reopen_count,
                            $ticket->sla_breached ? 'yes' : 'no',
                        ]);
                    }

                    flush();
                });

                fclose($handle);
            },
            $this->filename('ticktz-tickets', $from, $to),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<int, mixed>>
     */
    private function slaRows(array $data): array
    {
        $rows = [];

        foreach (['first_response', 'resolution'] as $metric) {
            foreach ($data['slaSeries'][$metric] as $day) {
                $total = $day['met'] + $day['breached'];

                $rows[] = [
                    $day['date'],
                    $metric,
                    $day['met'],
                    $day['breached'],
                    $total > 0 ? round($day['met'] / $total * 100, 1) : '',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function stream(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($header, $rows): void {
                $handle = fopen('php://output', 'w');
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $header);

                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function filename(string $prefix, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return "{$prefix}-{$from->toDateString()}-to-{$to->toDateString()}.csv";
    }
}
