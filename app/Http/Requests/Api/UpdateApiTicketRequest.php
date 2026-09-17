<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a ticket over the API.
 *
 * Every rule is `sometimes`, so a PATCH carrying one field touches one field.
 * A caller that reads a ticket, changes the subject and PUTs the whole thing
 * back would otherwise overwrite every other field with whatever it had read
 * a minute ago — the classic API race that quietly unassigns tickets.
 *
 * Status is not here; it belongs to the transition endpoint, which respects
 * the workflow.
 */
class UpdateApiTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'priority_id' => ['sometimes', 'integer', 'exists:priorities,id'],
            'queue_id' => ['sometimes', 'nullable', 'integer', 'exists:queues,id'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'label_ids' => ['sometimes', 'array', 'max:20'],
            'label_ids.*' => ['integer', 'exists:labels,id'],
        ];
    }
}
