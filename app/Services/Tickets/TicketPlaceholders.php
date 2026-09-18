<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The `{{ token }}` vocabulary a canned reply may use, and how it is filled.
 *
 * Substitution, never evaluation. Templates are written by service desk
 * managers, stored in the database and rendered into other people's browsers
 * and inboxes; anything that could execute would be a hole with an editor
 * attached. The same rule the e-mail templates follow.
 *
 * One class rather than one per caller, because the whole point of the
 * feature is that the agent who inserts a template and the rule that sends it
 * unattended produce the same words. Two renderers would eventually disagree,
 * and the first anyone would hear of it is a customer quoting a half-filled
 * sentence back at the desk.
 *
 * An unknown token renders as an empty string rather than being echoed. A
 * customer reading `{{ requester.naem }}` learns that the desk types their
 * apologies into a machine; a customer reading nothing learns only that a
 * sentence is oddly worded.
 */
final class TicketPlaceholders
{
    /**
     * Every token, grouped for the admin screen's cheat sheet.
     *
     * The list is here rather than in the Blade/React that documents it, so
     * that a token the renderer does not know cannot be advertised.
     *
     * @var array<string, array<int, string>>
     */
    public const TOKENS = [
        'ticket' => [
            'ticket.key', 'ticket.subject', 'ticket.status', 'ticket.priority',
            'ticket.queue', 'ticket.team', 'ticket.portal_url',
        ],
        'people' => [
            'requester.name', 'requester.first_name', 'requester.email', 'requester.organization',
            'assignee.name', 'assignee.first_name',
            'agent.name', 'agent.first_name',
        ],
        'desk' => ['app.name'],
    ];

    /**
     * Fill the tokens in `$text` against `$ticket`.
     *
     * `$actor` is whoever is sending — the agent who picked the template, or
     * null when a rule is sending it. `agent.*` is empty in that case, which
     * is correct and is why a template that signs off with an agent's name
     * should not be the one an unattended rule sends.
     *
     * `$escape` decides whether the values are HTML-escaped. A reply template
     * holds HTML, so a requester called `<b>` must not become markup; the
     * older plain-text automation body is filled raw, because it is converted
     * from text to HTML afterwards and escaping first would show a customer
     * the string `&amp;`.
     */
    public function render(string $text, Ticket $ticket, ?User $actor = null, bool $escape = false): string
    {
        $values = $this->values($ticket, $actor);

        return preg_replace_callback(
            '/\{\{(.*?)\}\}/s',
            static function (array $matches) use ($values, $escape): string {
                $value = $values[trim($matches[1])] ?? '';

                return $escape ? e($value) : $value;
            },
            $text,
        ) ?? $text;
    }

    /**
     * @return array<string, string>
     */
    public function values(Ticket $ticket, ?User $actor = null): array
    {
        $ticket->loadMissing(['status', 'priority', 'requester.organization', 'assignee', 'queue', 'team']);

        return [
            'ticket.key' => (string) $ticket->key,
            'ticket.subject' => (string) $ticket->subject,
            'ticket.status' => (string) $ticket->status?->name,
            'ticket.priority' => (string) $ticket->priority?->name,
            'ticket.queue' => (string) $ticket->queue?->name,
            'ticket.team' => (string) $ticket->team?->name,
            // The portal URL, not the agent one: a canned reply is read by the
            // person who raised the request, and a link into the agent console
            // is a link they cannot open.
            'ticket.portal_url' => url("/portal/requests/{$ticket->key}"),
            'requester.name' => (string) $ticket->requester?->name,
            'requester.first_name' => self::firstName($ticket->requester?->name),
            'requester.email' => (string) $ticket->requester?->email,
            'requester.organization' => (string) $ticket->requester?->organization?->name,
            'assignee.name' => (string) $ticket->assignee?->name,
            'assignee.first_name' => self::firstName($ticket->assignee?->name),
            'agent.name' => (string) $actor?->name,
            'agent.first_name' => self::firstName($actor?->name),
            'app.name' => (string) config('app.name'),
        ];
    }

    /**
     * A greeting says "Hi Jan", not "Hi Jan de Vries".
     */
    private static function firstName(?string $name): string
    {
        return Str::of((string) $name)->trim()->before(' ')->toString();
    }
}
