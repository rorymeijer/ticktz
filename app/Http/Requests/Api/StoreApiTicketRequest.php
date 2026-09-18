<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\ResolvesAssignability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filing a ticket over the API.
 *
 * Authorisation is not done here. The route's scope middleware decides whether
 * the token may reach the endpoint at all, and the controller runs the policy
 * against the record — putting a third check in the request would be a rule to
 * keep in sync with two others.
 *
 * `status_id` is absent on purpose: a new ticket starts where its workflow
 * says it starts. Letting a caller pick the opening status is how a ticket
 * gets created already resolved, with no SLA clock and no trace of why.
 */
class StoreApiTicketRequest extends FormRequest
{
    use ResolvesAssignability;

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
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:100000'],
            'requester_id' => ['nullable', 'integer', 'exists:users,id'],
            'requester_email' => ['nullable', 'email', 'max:255'],
            'priority_id' => ['nullable', 'integer', 'exists:priorities,id'],
            'queue_id' => ['nullable', 'integer', 'exists:queues,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'request_type_id' => ['nullable', 'integer', 'exists:request_types,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id', ...$this->assigneeRules()],
            'label_ids' => ['nullable', 'array', 'max:20'],
            'label_ids.*' => ['integer', 'exists:labels,id'],
        ];
    }
}
