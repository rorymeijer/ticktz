<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use App\Services\Tickets\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Ticket|null $ticket */
        $ticket = $this->route('ticket');

        if ($ticket === null) {
            return false;
        }

        // Writing an internal note is a different permission from replying.
        return $this->boolean('is_internal')
            ? ($this->user()?->can('commentInternally', $ticket) ?? false)
            : ($this->user()?->can('comment', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65000'],
            'is_internal' => ['boolean'],
            'attachments' => ['array', 'max:10'],
            'attachments.*' => AttachmentService::rules(),
        ];
    }
}
