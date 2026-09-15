<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Organization;
use App\Models\PortalCategory;
use App\Models\Priority;
use App\Models\RequestType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;

beforeEach(function () {
    seedServiceDesk();
});

/**
 * A request type with a two-field form: a required select and an optional
 * free-text field.
 */
function laptopRequestType(): RequestType
{
    $system = CustomField::factory()->required()->select([
        ['value' => 'zaaksysteem', 'label' => 'Zaaksysteem'],
        ['value' => 'dms', 'label' => 'DMS'],
    ])->create(['key' => 'system_name', 'label' => 'Which system?']);

    $note = CustomField::factory()->create(['key' => 'extra_note', 'label' => 'Anything else?']);

    $type = RequestType::factory()->create([
        'slug' => 'application-access',
        'name' => 'Request access',
        'portal_category_id' => PortalCategory::factory()->create()->id,
        'team_id' => Team::factory()->create()->id,
        'priority_id' => Priority::query()->where('slug', 'high')->value('id'),
        'subject_template' => 'Access request: :system_name',
    ]);

    $type->fields()->sync([
        $system->id => ['position' => 10],
        $note->id => ['position' => 20],
    ]);

    return $type->fresh(['fields']);
}

test('the portal lists request types grouped by category', function () {
    $requester = User::factory()->requester()->create();
    laptopRequestType();

    $this->actingAs($requester)
        ->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Index')
            ->has('categories', 1)
            ->has('categories.0.request_types', 1));
});

test('a requester submits a request and gets a ticket', function () {
    $requester = User::factory()->requester()->create();
    $type = laptopRequestType();

    $this->actingAs($requester)
        ->post("/portal/new/{$type->slug}", [
            'description' => 'I need access for the new permit process.',
            'fields' => ['system_name' => 'zaaksysteem', 'extra_note' => 'Read-only is fine.'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $ticket = Ticket::query()->sole();

    expect($ticket->source)->toBe('portal')
        ->and($ticket->requester_id)->toBe($requester->id)
        ->and($ticket->request_type_id)->toBe($type->id)
        ->and($ticket->team_id)->toBe($type->team_id)
        ->and($ticket->priority->slug)->toBe('high')
        // The subject template renders from the answers.
        ->and($ticket->subject)->toBe('Access request: zaaksysteem')
        ->and($ticket->customFields())->toBe([
            'system_name' => 'zaaksysteem',
            'extra_note' => 'Read-only is fine.',
        ]);
});

test('a required custom field is enforced', function () {
    $requester = User::factory()->requester()->create();
    $type = laptopRequestType();

    $this->actingAs($requester)
        ->post("/portal/new/{$type->slug}", ['fields' => ['extra_note' => 'Nothing else']])
        ->assertSessionHasErrors('fields.system_name');

    expect(Ticket::query()->count())->toBe(0);
});

test('a value outside the configured choices is rejected', function () {
    $requester = User::factory()->requester()->create();
    $type = laptopRequestType();

    $this->actingAs($requester)
        ->post("/portal/new/{$type->slug}", ['fields' => ['system_name' => 'something-else']])
        ->assertSessionHasErrors('fields.system_name');
});

test('an answer for a field that is not on the form is discarded', function () {
    // A crafted payload must not be able to write a field the request type
    // does not ask for.
    $requester = User::factory()->requester()->create();
    $type = laptopRequestType();
    CustomField::factory()->internal()->create(['key' => 'cost_centre', 'label' => 'Cost centre']);

    $this->actingAs($requester)
        ->post("/portal/new/{$type->slug}", [
            'fields' => ['system_name' => 'dms', 'cost_centre' => '9999'],
        ])
        ->assertRedirect();

    expect(Ticket::query()->sole()->customFields())->not->toHaveKey('cost_centre');
});

test('a request type restricted to agents is invisible to a requester', function () {
    $requester = User::factory()->requester()->create();
    $type = RequestType::factory()->agentsOnly()->create();

    $this->actingAs($requester)->get("/portal/new/{$type->slug}")->assertNotFound();
    $this->actingAs($requester)->post("/portal/new/{$type->slug}", [])->assertNotFound();

    $agent = User::factory()->agent()->create();
    $this->actingAs($agent)->get("/portal/new/{$type->slug}")->assertOk();
});

test('an organisation-scoped request type is only offered to its members', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->requester()->create(['organization_id' => $organization->id]);
    $outsider = User::factory()->requester()->create();

    $type = RequestType::factory()->forOrganizations([$organization->id])->create();

    $this->actingAs($member->fresh())->get("/portal/new/{$type->slug}")->assertOk();
    $this->actingAs($outsider->fresh())->get("/portal/new/{$type->slug}")->assertNotFound();
});

test('an inactive request type is not reachable', function () {
    $requester = User::factory()->requester()->create();
    $type = RequestType::factory()->create(['is_active' => false]);

    $this->actingAs($requester)->get("/portal/new/{$type->slug}")->assertNotFound();
});

test('a request type without a subject template asks for one', function () {
    $requester = User::factory()->requester()->create();
    $type = RequestType::factory()->create(['subject_template' => null]);

    $this->actingAs($requester)
        ->post("/portal/new/{$type->slug}", ['description' => 'Details'])
        ->assertSessionHasErrors('subject');

    $this->actingAs($requester)
        ->post("/portal/new/{$type->slug}", ['subject' => 'My own summary'])
        ->assertRedirect();

    expect(Ticket::query()->sole()->subject)->toBe('My own summary');
});

test('the requester can only choose a priority when the request type allows it', function () {
    $requester = User::factory()->requester()->create();
    $normal = Priority::query()->where('slug', 'normal')->sole();
    $urgent = Priority::query()->where('slug', 'urgent')->sole();

    $fixed = RequestType::factory()->create([
        'priority_id' => $normal->id,
        'allow_priority_choice' => false,
        'subject_template' => 'Fixed',
    ]);

    $this->actingAs($requester)
        ->post("/portal/new/{$fixed->slug}", ['priority_id' => $urgent->id])
        ->assertRedirect();

    expect(Ticket::query()->sole()->priority_id)->toBe($normal->id);

    $flexible = RequestType::factory()->create([
        'priority_id' => $normal->id,
        'allow_priority_choice' => true,
        'subject_template' => 'Flexible',
    ]);

    $this->actingAs($requester)
        ->post("/portal/new/{$flexible->slug}", ['priority_id' => $urgent->id])
        ->assertRedirect();

    expect(Ticket::query()->latest('id')->first()->priority_id)->toBe($urgent->id);
});
