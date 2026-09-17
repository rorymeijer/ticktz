<?php

declare(strict_types=1);

use App\Models\ApprovalDecision;
use App\Models\ApprovalWorkflow;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\RichText\RichTextBackfill;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * Every prose field in the application, held to the same contract: a script
 * cannot survive a save, whatever route the value took to get there.
 */
it('cleans every prose field', function (string $model, string $column, array $attributes): void {
    $record = $model::factory()->create($attributes + [
        $column => '<p>Kept</p><script>alert(document.cookie)</script>',
    ]);

    expect($record->fresh()->{$column})->toContain('Kept')
        ->and($record->fresh()->{$column})->not->toContain('script');
})->with([
    'asset notes' => [Asset::class, 'notes', []],
    'organisation description' => [Organization::class, 'description', []],
    'request type instructions' => [RequestType::class, 'instructions', []],
    'approval workflow instructions' => [ApprovalWorkflow::class, 'instructions', []],
    'user signature' => [User::class, 'signature', []],
]);

it('cleans an approval reason and a decision comment', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $ticket = Ticket::factory()->create();

    $approval = app(ApprovalService::class)->open($ticket, $workflow, [
        'reason' => 'Needs sign-off <script>alert(1)</script>',
    ]);

    app(ApprovalService::class)->decide(
        $approval->decisions->first(),
        ApprovalDecision::APPROVED,
        '<p>Fine by me</p><iframe src="https://evil.test"></iframe>',
        $approver,
    );

    expect($approval->fresh()->reason)->toContain('Needs sign-off')
        ->and($approval->fresh()->reason)->not->toContain('script')
        ->and($approval->fresh()->decisions->first()->comment)->toContain('Fine by me')
        ->and($approval->fresh()->decisions->first()->comment)->not->toContain('iframe');
});

/**
 * A translated field is the same field. Cleaning only the default language
 * would leave the Dutch version of an instruction as the way in.
 */
it('cleans every language of a translated field', function (): void {
    $type = RequestType::factory()->create([
        'instructions' => 'Tell us what broke.',
        'instructions_translations' => [
            'nl' => 'Vertel wat er stuk is <script>alert(1)</script>',
            'de' => '<p>Was ist kaputt?</p>',
        ],
    ]);

    $fresh = $type->fresh();

    expect($fresh->instructions_translations['nl'])->toContain('Vertel wat er stuk is')
        ->and($fresh->instructions_translations['nl'])->not->toContain('script')
        ->and($fresh->instructions_translations['de'])->toBe('<p>Was ist kaputt?</p>');
});

it('drops a language translated to nothing so the fallback still works', function (): void {
    $type = RequestType::factory()->create([
        'instructions' => 'Tell us what broke.',
        'instructions_translations' => ['nl' => '<p></p>'],
    ]);

    expect($type->fresh()->instructions_translations)->not->toHaveKey('nl')
        ->and($type->fresh()->translatedInstructions('nl'))->toBe('<p>Tell us what broke.</p>');
});

// -----------------------------------------------------------------
// Custom fields
// -----------------------------------------------------------------

/**
 * Every field type shares one `value` column, so only the one that holds
 * prose goes near an HTML allowlist. A date run through a paragraph wrapper
 * would be a data loss nobody notices until a report runs.
 */
it('cleans a multiline custom field and leaves the other types alone', function (): void {
    $prose = CustomField::factory()->create(['entity' => 'ticket', 'key' => 'symptom', 'type' => 'textarea']);
    $date = CustomField::factory()->create(['entity' => 'ticket', 'key' => 'seen_on', 'type' => 'date']);

    $ticket = Ticket::factory()->create();
    $ticket->setCustomFields([
        'symptom' => '<p>Clicks twice</p><script>alert(1)</script>',
        'seen_on' => '2026-02-01',
    ]);

    $fields = $ticket->fresh()->customFields();

    expect($fields['symptom'])->toContain('Clicks twice')
        ->and($fields['symptom'])->not->toContain('script')
        ->and($fields['seen_on'])->toBe('2026-02-01');
})->skip(fn () => ! class_exists(CustomField::class), 'Custom fields are not available.');

// -----------------------------------------------------------------
// Search
// -----------------------------------------------------------------

it('finds an asset by a word in its formatted notes, and not by a tag name', function (): void {
    $wanted = Asset::factory()->create(['notes' => '<p>Replaced the <strong>fan</strong> in March.</p>']);

    expect(Asset::query()->search('fan')->pluck('id'))->toContain($wanted->id);
    expect(Asset::query()->search('strong')->count())->toBe(0);
});

// -----------------------------------------------------------------
// The upgrade
// -----------------------------------------------------------------

it('converts only the custom field values that hold prose', function (): void {
    $prose = CustomField::factory()->create(['entity' => 'ticket', 'key' => 'symptom', 'type' => 'textarea']);
    $date = CustomField::factory()->create(['entity' => 'ticket', 'key' => 'seen_on', 'type' => 'date']);

    $ticket = Ticket::factory()->create();

    // What the table held before the upgrade, written past the model so it
    // does not go through the cleaning on the way in.
    DB::table('custom_field_values')->insert([
        ['entity_type' => Ticket::class, 'entity_id' => $ticket->id, 'custom_field_id' => $prose->id, 'value' => "Clicks twice.\n\nEvery time.", 'created_at' => now(), 'updated_at' => now()],
        ['entity_type' => Ticket::class, 'entity_id' => $ticket->id, 'custom_field_id' => $date->id, 'value' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(RichTextBackfill::class)->convert(
        'custom_field_values',
        'value',
        null,
        fn ($query) => $query->whereIn('custom_field_id', [$prose->id]),
    );

    expect(DB::table('custom_field_values')->where('custom_field_id', $prose->id)->value('value'))
        ->toBe('<p>Clicks twice.</p><p>Every time.</p>')
        ->and(DB::table('custom_field_values')->where('custom_field_id', $date->id)->value('value'))
        ->toBe('2026-02-01');
});

/**
 * `ApprovalService::decide()` claims the decision with a query-builder update
 * so two approvers clicking at once produce one answer. Eloquent fires no
 * model events for that, so the trait cannot see it and the service has to
 * clean the comment itself — which is exactly the kind of thing that gets
 * forgotten, hence the test.
 */
it('cleans a decision comment even though the claim bypasses the model', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();

    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    app(ApprovalService::class)->decide(
        $approval->decisions->first(),
        ApprovalDecision::APPROVED,
        '<p>Agreed</p><script>alert(document.cookie)</script>',
        $approver,
    );

    $comment = (string) $approval->fresh()->decisions->first()->comment;

    expect($comment)->toContain('Agreed')
        ->and($comment)->not->toContain('script');
});
