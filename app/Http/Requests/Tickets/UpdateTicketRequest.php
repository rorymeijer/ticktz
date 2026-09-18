<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\ResolvesAssignability;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    use ResolvesAssignability;

    public function authorize(): bool
    {
        /** @var Ticket|null $ticket */
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('update', $ticket) ?? false);
    }

    /**
     * Only the fields the detail page can change. Status is absent on purpose:
     * it moves through the workflow, not through a form post.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'priority_id' => ['sometimes', 'required', 'integer', Rule::exists('priorities', 'id')],
            'queue_id' => ['sometimes', 'nullable', 'integer', Rule::exists('queues', 'id')],
            'team_id' => ['sometimes', 'nullable', 'integer', Rule::exists('teams', 'id')],
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id'), ...$this->assigneeRules($this->route('ticket'))],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')],
        ];
    }
}
