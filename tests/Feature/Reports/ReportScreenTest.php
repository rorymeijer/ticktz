<?php

declare(strict_types=1);

use App\Models\SavedReport;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Services\Reports\MetricsCollector;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * Read a streamed download into a string.
 */
function csvBody(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

it('shows the reporting screen to somebody who may read reports', function (): void {
    $user = makeUserWithPermissions('reports.view');

    $response = $this->actingAs($user)->get('/reports')->assertOk();

    expect($response->viewData('page')['props']['data']['report'])->toBe('sla');
});

it('refuses the reporting screen without the permission', function (): void {
    $user = makeUserWithPermissions('tickets.view');

    $this->actingAs($user)->get('/reports')->assertForbidden();
});

/**
 * Reporting reads the rollups rather than the ticket table, so somebody who
 * reports on the desk without working it needs `reports.view` and nothing
 * else.
 */
it('does not require ticket access to read reports', function (): void {
    $user = makeUserWithPermissions('reports.view');

    $this->actingAs($user)->get('/reports?report=volume')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Export — the second half of the acceptance criterion
|--------------------------------------------------------------------------
*/

it('exports the report figures as CSV', function (): void {
    $yesterday = CarbonImmutable::yesterday()->setTime(10, 0);

    SlaTimer::query()->create([
        'ticket_id' => Ticket::factory()->create()->getKey(),
        'metric' => 'resolution',
        'target_minutes' => 60,
        'started_at' => $yesterday->subHours(2),
        'due_at' => $yesterday->subHour(),
        'status' => SlaTimer::STATUS_MET,
        'completed_at' => $yesterday,
    ]);

    app(MetricsCollector::class)->rebuild(CarbonImmutable::today()->subDays(3), CarbonImmutable::today());

    $user = makeUserWithPermissions('reports.view', 'tickets.export');

    $response = $this->actingAs($user)
        ->get('/reports/export?report=sla&from='.CarbonImmutable::today()->subDays(3)->toDateString())
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $body = csvBody($response);

    expect($body)->toContain('date,metric,met,breached,compliance_percent')
        ->and($body)->toContain(CarbonImmutable::yesterday()->toDateString().',resolution,1,0,100')
        // Excel reads a CSV as the local codepage unless a BOM says otherwise,
        // which turns every Dutch name in the export into mojibake.
        ->and($body)->toStartWith("\xEF\xBB\xBF");
});

it('exports the tickets behind the figures', function (): void {
    $ticket = Ticket::factory()->create([
        'subject' => 'Kan niet inloggen',
        'created_at' => CarbonImmutable::yesterday()->setTime(9, 0),
    ]);

    $user = makeUserWithPermissions('reports.view', 'tickets.export');

    $response = $this->actingAs($user)
        ->get('/reports/export?subject=tickets&from='.CarbonImmutable::today()->subDays(3)->toDateString())
        ->assertOk();

    $body = csvBody($response);

    expect($body)->toContain('key,subject,status')
        ->and($body)->toContain($ticket->key)
        ->and($body)->toContain('Kan niet inloggen');
});

/**
 * Exporting a year of tickets is exporting the desk's whole record of who
 * asked for what, so it is held to the same permission the ticket list's own
 * export is.
 */
it('refuses the export without the ticket export permission', function (): void {
    $user = makeUserWithPermissions('reports.view');

    $this->actingAs($user)->get('/reports/export?report=sla')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Saved reports
|--------------------------------------------------------------------------
*/

it('saves a report as a set of filters, not as figures', function (): void {
    $user = makeUserWithPermissions('reports.view');

    $this->actingAs($user)->post('/reports/saved', [
        'name' => 'Servicedesk, this month',
        'report' => 'sla',
        'filters' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'dimension' => 'queue'],
    ])->assertRedirect();

    $saved = SavedReport::query()->sole();

    expect($saved->report)->toBe('sla')
        ->and($saved->filters['dimension'])->toBe('queue')
        ->and($saved->filters['from'])->toBe('2026-09-01');
});

/**
 * Sharing a report puts it on everybody's reporting screen, which is a change
 * to other people's workspace rather than to your own.
 */
it('refuses to share a report without the manage permission', function (): void {
    $user = makeUserWithPermissions('reports.view');

    $this->actingAs($user)->post('/reports/saved', [
        'name' => 'Mine',
        'report' => 'volume',
        'is_shared' => true,
    ])->assertRedirect();

    expect(SavedReport::query()->sole()->is_shared)->toBeFalse();
});

it('shares a report for somebody who may manage them', function (): void {
    $user = makeUserWithPermissions('reports.view', 'reports.manage');

    $this->actingAs($user)->post('/reports/saved', [
        'name' => 'Everybody’s',
        'report' => 'volume',
        'is_shared' => true,
    ])->assertRedirect();

    expect(SavedReport::query()->sole()->is_shared)->toBeTrue();
});

it('shows somebody their own reports and the shared ones, and nobody else’s', function (): void {
    $mine = makeUserWithPermissions('reports.view');
    $theirs = makeUserWithPermissions('reports.view');

    $own = SavedReport::query()->create(['name' => 'Mine', 'report' => 'sla', 'created_by' => $mine->getKey()]);
    $shared = SavedReport::query()->create(['name' => 'Shared', 'report' => 'sla', 'is_shared' => true]);
    $other = SavedReport::query()->create(['name' => 'Theirs', 'report' => 'sla', 'created_by' => $theirs->getKey()]);

    $response = $this->actingAs($mine)->get('/reports')->assertOk();
    $ids = collect($response->viewData('page')['props']['saved'])->pluck('id');

    expect($ids)->toContain($own->id)->toContain($shared->id)->not->toContain($other->id);
});

it('refuses to delete somebody else’s saved report', function (): void {
    $mine = makeUserWithPermissions('reports.view');
    $theirs = makeUserWithPermissions('reports.view');

    $report = SavedReport::query()->create(['name' => 'Theirs', 'report' => 'sla', 'created_by' => $theirs->getKey()]);

    $this->actingAs($mine)->delete("/reports/saved/{$report->id}")->assertForbidden();

    expect(SavedReport::query()->count())->toBe(1);
});

it('lets somebody delete their own saved report', function (): void {
    $user = makeUserWithPermissions('reports.view');

    $report = SavedReport::query()->create(['name' => 'Mine', 'report' => 'sla', 'created_by' => $user->getKey()]);

    $this->actingAs($user)->delete("/reports/saved/{$report->id}")->assertRedirect();

    expect(SavedReport::query()->count())->toBe(0);
});
