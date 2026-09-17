<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\CustomField;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns a portal submission into a ticket.
 *
 * The form is defined by data, so validation is built from that same data
 * rather than from a static Form Request: whatever fields an administrator
 * attached are exactly the fields that are required, validated and stored.
 */
class RequestSubmissionService
{
    public function __construct(private readonly TicketService $tickets) {}

    /**
     * Validation rules for a request type's dynamic form.
     *
     * @return array{rules: array<string, mixed>, attributes: array<string, string>}
     */
    public function rulesFor(RequestType $requestType): array
    {
        $rules = [
            'subject' => [$requestType->subject_template ? 'nullable' : 'required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:65000'],
            'attachments' => ['array', 'max:10'],
            'attachments.*' => AttachmentService::rules(),
        ];

        $attributes = [];

        if ($requestType->allow_priority_choice) {
            $rules['priority_id'] = ['nullable', 'integer', 'exists:priorities,id'];
        }

        foreach ($requestType->fields as $field) {
            if (! $field->is_active) {
                continue;
            }

            $required = $field->pivot->is_required === null
                ? $field->is_required
                : (bool) $field->pivot->is_required;

            $key = "fields.{$field->key}";
            $rules[$key] = $field->validationRules($required);
            $attributes[$key] = $field->translatedLabel();

            if ($field->isMultiValue()) {
                $rules["{$key}.*"] = ['string', 'max:255'];
            }
        }

        return ['rules' => $rules, 'attributes' => $attributes];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function submit(RequestType $requestType, array $payload, User $requester, ?User $actor = null): Ticket
    {
        $requestType->loadMissing('fields');

        ['rules' => $rules, 'attributes' => $attributes] = $this->rulesFor($requestType);

        $validated = Validator::make($payload, $rules, [], $attributes)->validate();
        $answers = $this->normaliseAnswers($requestType, $validated['fields'] ?? []);

        // With a template the subject is generated; without one the requester
        // wrote it themselves and the template step is a no-op.
        $subject = $requestType->renderSubject(
            $answers,
            (string) ($validated['subject'] ?? '') ?: $requestType->translatedName(),
        );

        return DB::transaction(function () use ($requestType, $validated, $answers, $subject, $requester, $actor): Ticket {
            $ticket = $this->tickets->create([
                'subject' => $subject,
                'description' => $validated['description'] ?? null,
                'requester_id' => $requester->getKey(),
                'request_type_id' => $requestType->getKey(),
                'queue_id' => $requestType->queue_id,
                'team_id' => $requestType->team_id,
                'workflow_id' => $requestType->workflow_id,
                'priority_id' => $requestType->allow_priority_choice
                    ? (($validated['priority_id'] ?? null) ?: $requestType->priority_id)
                    : $requestType->priority_id,
                'source' => 'portal',
            ], $actor ?? $requester);

            $ticket->setCustomFields($answers);

            return $ticket;
        });
    }

    /**
     * Drop answers for fields this request type does not ask for. A crafted
     * payload must not be able to write a field that is not on the form.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function normaliseAnswers(RequestType $requestType, array $answers): array
    {
        $allowed = $requestType->fields
            ->filter(fn (CustomField $field) => $field->is_active)
            ->pluck('key')
            ->all();

        return array_intersect_key($answers, array_flip($allowed));
    }
}
