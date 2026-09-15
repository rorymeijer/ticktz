<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Organization;
use App\Models\PortalCategory;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;

beforeEach(function () {
    seedServiceDesk();
});

test('managing request types requires settings.manage', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->get('/admin/request-types')->assertForbidden();
    $this->actingAs($agent)->get('/admin/custom-fields')->assertForbidden();
});

test('an administrator creates a custom field', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/admin/custom-fields', [
            'key' => 'cost_centre',
            'label' => 'Cost centre',
            'label_translations' => ['nl' => 'Kostenplaats'],
            'type' => 'text',
            'is_required' => false,
            'is_public' => false,
            'entity' => 'ticket',
            'is_active' => true,
            'position' => 10,
        ])
        ->assertRedirect();

    $field = CustomField::query()->where('key', 'cost_centre')->sole();

    expect($field->is_public)->toBeFalse()
        ->and($field->translatedLabel('nl'))->toBe('Kostenplaats')
        ->and($field->translatedLabel('en'))->toBe('Cost centre');
});

test('options are dropped for field types that have no choices', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/custom-fields', [
        'key' => 'free_text',
        'label' => 'Free text',
        'type' => 'text',
        'options' => [['value' => 'a', 'label' => 'A']],
        'entity' => 'ticket',
    ])->assertRedirect();

    expect(CustomField::query()->where('key', 'free_text')->sole()->options)->toBeNull();
});

test('a field key must be a lower-case identifier', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/admin/custom-fields', ['key' => 'Cost Centre', 'label' => 'x', 'type' => 'text', 'entity' => 'ticket'])
        ->assertSessionHasErrors('key');
});

test('a field with answers keeps its key and type', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['key' => 'system_name', 'type' => 'text']);

    Ticket::factory()->create()->setCustomFields(['system_name' => 'zaaksysteem']);

    $this->actingAs($admin)
        ->put("/admin/custom-fields/{$field->id}", [
            'key' => 'renamed',
            'label' => 'Renamed label',
            'type' => 'number',
            'entity' => 'ticket',
        ])
        ->assertRedirect();

    $field->refresh();

    expect($field->key)->toBe('system_name')
        ->and($field->type)->toBe('text')
        ->and($field->label)->toBe('Renamed label');
});

test('a field with answers cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $field = CustomField::factory()->create(['key' => 'system_name']);
    Ticket::factory()->create()->setCustomFields(['system_name' => 'dms']);

    $this->actingAs($admin)
        ->delete("/admin/custom-fields/{$field->id}")
        ->assertSessionHas('error');

    expect(CustomField::query()->whereKey($field->id)->exists())->toBeTrue();
});

test('an administrator creates a request type with a form', function () {
    $admin = User::factory()->admin()->create();
    $category = PortalCategory::factory()->create();
    $first = CustomField::factory()->create(['key' => 'system_name']);
    $second = CustomField::factory()->create(['key' => 'extra_note']);

    $this->actingAs($admin)
        ->post('/admin/request-types', [
            'portal_category_id' => $category->id,
            'name' => 'Request access',
            'name_translations' => ['nl' => 'Toegang aanvragen'],
            'slug' => 'request-access',
            'description' => 'Ask for an account.',
            'subject_template' => 'Access: :system_name',
            'visibility' => 'everyone',
            'is_active' => true,
            'fields' => [
                ['custom_field_id' => $second->id, 'is_required' => true],
                ['custom_field_id' => $first->id, 'is_required' => null],
            ],
        ])
        ->assertRedirect('/admin/request-types');

    $type = RequestType::query()->where('slug', 'request-access')->sole();

    // The order the administrator chose is the order the requester sees.
    expect($type->fields->pluck('key')->all())->toBe(['extra_note', 'system_name'])
        ->and($type->fields->firstWhere('key', 'extra_note')->pivot->is_required)->toBe(1)
        ->and($type->translatedName('nl'))->toBe('Toegang aanvragen');
});

test('organisation restrictions are cleared when the visibility changes', function () {
    $admin = User::factory()->admin()->create();
    $organization = Organization::factory()->create();

    $type = RequestType::factory()->forOrganizations([$organization->id])->create();

    $this->actingAs($admin)
        ->put("/admin/request-types/{$type->id}", [
            'name' => $type->name,
            'slug' => $type->slug,
            'visibility' => 'everyone',
            'organization_ids' => [$organization->id],
        ])
        ->assertRedirect();

    expect($type->fresh()->organization_ids)->toBeNull();
});

test('a request type with tickets cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $type = RequestType::factory()->create();
    Ticket::factory()->create(['request_type_id' => $type->id]);

    $this->actingAs($admin)
        ->delete("/admin/request-types/{$type->id}")
        ->assertSessionHas('error');

    expect(RequestType::query()->whereKey($type->id)->exists())->toBeTrue();
});

test('deleting a category leaves its request types reachable', function () {
    $admin = User::factory()->admin()->create();
    $category = PortalCategory::factory()->create();
    $type = RequestType::factory()->create(['portal_category_id' => $category->id]);

    $this->actingAs($admin)->delete("/admin/portal-categories/{$category->id}")->assertRedirect();

    expect($type->fresh()->portal_category_id)->toBeNull();

    $requester = User::factory()->requester()->create();
    $this->actingAs($requester)->get("/portal/new/{$type->slug}")->assertOk();
});
