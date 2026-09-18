<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing the same request again.
 *
 * Both fields are optional, and that is the useful shape: cloning with nothing
 * filled in copies the ticket as it stands, which is the common case. A subject
 * is offered because the first thing anybody does to a copy is say what makes
 * it different, and a request type because the second thing they do is realise
 * it belongs somewhere else.
 *
 * Authorisation is the controller's: it is a creation, checked against the
 * policy for a new ticket rather than against the one being copied.
 */
class CloneTicketRequest extends FormRequest
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
            'subject' => ['nullable', 'string', 'max:500'],
            'request_type_id' => ['nullable', 'integer', Rule::exists('request_types', 'id')],
        ];
    }
}
