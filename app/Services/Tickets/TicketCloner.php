<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Filing the same request again.
 *
 * The whole feature is one decision — what carries over — so it lives in one
 * place rather than being spread across a controller and a form. Two things
 * people want from a clone, and they pull in opposite directions: "the same
 * request again next month", which wants everything, and "this ticket is
 * actually two pieces of work", which wants the description and nothing that
 * implies the new one has already been worked on.
 *
 * What carries over is what describes the request: its subject, its
 * description, who asked, how urgent, which queue and team it belongs to, its
 * labels, and the answers to its custom fields.
 *
 * What does not is everything that is a record of the original being handled:
 * its status, its conversation, its attachments, its approvals, its SLA
 * clocks, its audit trail. A clone that opened at "Resolved" with somebody
 * else's comments under it would not be a new request.
 *
 * The two that are worth defending individually:
 *
 * - **The assignee is not copied.** A clone is new work, and arriving already
 *   assigned means somebody is holding a ticket they were never handed. It is
 *   also the only field that could arrive invalid: cloning into another
 *   request type can move the ticket to another queue and so to another team,
 *   and a ticket may only be held by somebody on its team.
 * - **Attachments are not copied.** They belong to the conversation that
 *   produced them, and duplicating files to make a second ticket look complete
 *   is a storage bill nobody asked for. Link the two tickets and the originals
 *   are one click away — which the clone does automatically.
 */
class TicketCloner
{
    public function __construct(private readonly TicketService $tickets) {}

    /**
     * @param  RequestType|null  $as  file the copy under a different request type
     */
    public function clone(Ticket $original, ?User $actor = null, ?RequestType $as = null, ?string $subject = null): Ticket
    {
        $original->loadMissing(['labels', 'customFieldValues.field']);

        $type = $as ?? $original->requestType;

        return DB::transaction(function () use ($original, $actor, $type, $subject): Ticket {
            $clone = $this->tickets->create([
                'subject' => $subject !== null && trim($subject) !== ''
                    ? trim($subject)
                    : $original->subject,
                'description' => $original->description,
                'requester_id' => $original->requester_id,
                'priority_id' => $original->priority_id,
                'organization_id' => $original->organization_id,
                'request_type_id' => $type?->getKey(),
                // A request type owns the queue, team and workflow a ticket
                // filed under it belongs in, so when the copy is filed under a
                // different one those come from the type rather than from the
                // ticket being copied. Cloning as the same type changes
                // nothing, which is the point.
                'queue_id' => $type?->queue_id ?? $original->queue_id,
                'team_id' => $type?->team_id ?? $original->team_id,
                'workflow_id' => $type?->workflow_id ?? $original->workflow_id,
                'label_ids' => $original->labels->pluck('id')->all(),
                'source' => 'agent',
            ], $actor);

            $clone->setCustomFields($this->answersFor($original, $type));

            // So the trail exists from both ends without anybody having to
            // remember the other key.
            $clone->links()->create([
                'related_ticket_id' => $original->getKey(),
                'type' => 'relates',
                'created_by' => $actor?->getKey(),
            ]);

            return $clone;
        });
    }

    /**
     * The original's answers, kept only where the new request type asks the
     * same questions.
     *
     * Cloning into another type is the case this exists for. A "new laptop"
     * form asks which model; an "access request" form does not, and carrying
     * the answer across would leave a value on a ticket whose form has nowhere
     * to show it and no way to change it — invisible, and still in the export.
     *
     * @return array<string, mixed>
     */
    private function answersFor(Ticket $original, ?RequestType $type): array
    {
        $answers = $original->customFields();

        if ($answers === [] || $type === null) {
            return $answers;
        }

        $asked = $type->fields->pluck('key')->all();

        return array_intersect_key($answers, array_flip($asked));
    }
}
