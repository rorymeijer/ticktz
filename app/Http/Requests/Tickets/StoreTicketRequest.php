<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\ResolvesAssignability;
use App\Models\Ticket;
use App\Services\Tickets\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    use ResolvesAssignability;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:100000'],
            'requester_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'priority_id' => ['nullable', 'integer', Rule::exists('priorities', 'id')],
            'status_id' => ['nullable', 'integer', Rule::exists('ticket_statuses', 'id')],
            'workflow_id' => ['nullable', 'integer', Rule::exists('workflows', 'id')],
            'queue_id' => ['nullable', 'integer', Rule::exists('queues', 'id')],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id'), $this->assignableRule()],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'label_ids' => ['array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')],
            'attachments' => ['array', 'max:10'],
            'attachments.*' => AttachmentService::rules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        return parent::validated() + ['source' => 'agent'];
    }
}
