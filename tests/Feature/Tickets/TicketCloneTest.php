<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Label;
use App\Models\Queue;
use App\Models\RequestType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| Filing the same request again
|--------------------------------------------------------------------------
|
| The whole feature is one decision — what carries over — so that is what these
| pin. What describes the request comes with it; what records the original
| being handled does not.
*/

it('carries over what describes the request', function (): void {
    $agent = makeAdmin();
    $requester = makeRequester();
    $label = Label::factory()->create();

    $original = Ticket::factory()->create([
        'subject' => 'Laptop for the new starter',
        'description' => '<p>Sixteen gigabytes, please.</p>',
        'requester_id' => $requester->getKey(),
    ]);
    $original->labels()->attach($label);

    $this->actingAs($agent)->post("/agent/tickets/{$original->key}/clone")->assertRedirect();

    $clone = Ticket::query()->where('id', '!=', $original->id)->sole();

    expect($clone->subject)->toBe('Laptop for the new starter')
        ->and($clone->description)->toBe('<p>Sixteen gigabytes, please.</p>')
        ->and($clone->requester_id)->toBe($requester->getKey())
        ->and($clone->priority_id)->toBe($original->priority_id)
        ->and($clone->labels->pluck('id')->all())->toBe([$label->getKey()]);
});

it('does not carry over the record of the original being handled', function (): void {
    // A clone that opened where the original finished, holding somebody else's
    // conversation, would not be a new request.
    $agent = makeAdmin();
    $holder = User::factory()->agent()->create();

    $original = Ticket::factory()->create(['assignee_id' => $holder->getKey()]);
    app(TicketService::class)
        ->comment($original, '<p>Ordered.</p>', $agent);

    $this->actingAs($agent)->post("/agent/tickets/{$original->key}/clone")->assertRedirect();

    $clone = Ticket::query()->where('id', '!=', $original->id)->sole();

    expect($clone->assignee_id)->toBeNull()
        ->and($clone->comments()->count())->toBe(0)
        ->and($clone->status->category)->toBe('new');
});

it('links the copy to what it was copied from', function (): void {
    $agent = makeAdmin();
    $original = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$original->key}/clone")->assertRedirect();

    $clone = Ticket::query()->where('id', '!=', $original->id)->sole();

    expect($clone->links()->where('related_ticket_id', $original->getKey())->where('type', 'relates')->exists())
        ->toBeTrue();
});

it('takes a subject that says what makes this one different', function (): void {
    $agent = makeAdmin();
    $original = Ticket::factory()->create(['subject' => 'Laptop for the new starter']);

    $this->actingAs($agent)
        ->post("/agent/tickets/{$original->key}/clone", ['subject' => 'Laptop for the second new starter'])
        ->assertRedirect();

    expect(Ticket::query()->where('id', '!=', $original->id)->sole()->subject)
        ->toBe('Laptop for the second new starter');
});

it('files the copy under another request type, and follows it', function (): void {
    // A request type owns the queue, team and workflow its tickets belong in,
    // so filing the copy elsewhere has to move it there — otherwise the copy
    // claims a type whose queue it is not in.
    $agent = makeAdmin();
    $team = Team::factory()->create();
    $queue = Queue::factory()->create(['team_id' => $team->getKey()]);
    $type = RequestType::factory()->create([
        'queue_id' => $queue->getKey(),
        'team_id' => $team->getKey(),
    ]);

    $original = Ticket::factory()->create(['queue_id' => null, 'team_id' => null]);

    $this->actingAs($agent)
        ->post("/agent/tickets/{$original->key}/clone", ['request_type_id' => $type->getKey()])
        ->assertRedirect();

    $clone = Ticket::query()->where('id', '!=', $original->id)->sole();

    expect($clone->request_type_id)->toBe($type->getKey())
        ->and($clone->queue_id)->toBe($queue->getKey())
        ->and($clone->team_id)->toBe($team->getKey());
});

it('keeps the answers the new form still asks for, and drops the rest', function (): void {
    // The case this exists for. Carrying an answer into a type that has no
    // field for it leaves a value nobody can see or change, still in the
    // export.
    $agent = makeAdmin();

    $kept = CustomField::factory()->create(['key' => 'cost_centre', 'type' => 'text']);
    $dropped = CustomField::factory()->create(['key' => 'laptop_model', 'type' => 'text']);

    $from = RequestType::factory()->create();
    $from->fields()->attach([$kept->getKey(), $dropped->getKey()]);

    $to = RequestType::factory()->create();
    $to->fields()->attach($kept->getKey());

    $original = Ticket::factory()->create(['request_type_id' => $from->getKey()]);
    $original->setCustomFields(['cost_centre' => '4100', 'laptop_model' => 'X1 Carbon']);

    $this->actingAs($agent)
        ->post("/agent/tickets/{$original->key}/clone", ['request_type_id' => $to->getKey()])
        ->assertRedirect();

    $answers = Ticket::query()->where('id', '!=', $original->id)->sole()->customFields();

    expect($answers)->toHaveKey('cost_centre')
        ->and($answers['cost_centre'])->toBe('4100')
        ->and($answers)->not->toHaveKey('laptop_model');
});

it('needs the permission to raise a ticket, not merely to read one', function (): void {
    $reader = makeUserWithPermissions('tickets.view');
    $original = Ticket::factory()->create();

    $this->actingAs($reader)->post("/agent/tickets/{$original->key}/clone")->assertForbidden();

    expect(Ticket::query()->count())->toBe(1);
});
