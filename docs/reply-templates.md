# Reply templates

The answers a desk sends often enough to write down once, in one list with two
readers: the agent's reply box, and the `reply_template` automation action.

## Why one list

Canned text has a habit of existing twice — a shared document the agents copy
from, and a message body typed into an automation rule. The two drift, and the
stale one is always the rule's, because nobody opens it when the desk changes
how it apologises.

So a template is one row with one body, read at the moment of use by both
consumers. There is also one renderer
(`App\Services\Tickets\TicketPlaceholders`): the words an agent inserts by hand
and the words a rule sends come out of the same code, which is the property
the feature exists for.

## The model

`reply_templates` holds name, slug, description, the body as sanitised HTML
plus its flattened `body_text`, and four fields that decide where it turns up:

| Field | Meaning |
| --- | --- |
| `is_internal` | A note, never a reply. See below. |
| `team_id` | `null` is every team. Otherwise only that team's agents, and only rules acting on that team's tickets. |
| `locale` | `null` is every language. Otherwise offered when *the requester* reads it. |
| `is_active` | Off keeps it out of both dropdowns without breaking the rules that reference it. |

The body goes through the usual `HasRichText` write path with the `Basic`
profile, so it is sanitised wherever it is written from. Images are off: an
image in a template would be one file referenced by every ticket that ever
used it, which is the one case where clearing one ticket's attachments could
blank out somebody else's reply.

## Reply or note

`is_internal` lives on the template rather than at send time, and that is the
whole design of it. *"Escalated to the supplier, do not tell them yet"* is
exactly the sentence that must not reach a customer, and the way it reaches
one is a tickbox in the wrong state. An internal template is offered only on
the note tab, and an automation rule that sends it posts a note regardless of
what the rule says.

## Placeholders

`{{ token }}`, substituted against the ticket at the moment of use. Never
evaluated — templates are written by service desk managers, stored in the
database and rendered into other people's browsers and inboxes.

```
{{ ticket.key }}  {{ ticket.subject }}  {{ ticket.status }}  {{ ticket.priority }}
{{ ticket.queue }}  {{ ticket.team }}  {{ ticket.portal_url }}
{{ requester.name }}  {{ requester.first_name }}  {{ requester.email }}
{{ requester.organization }}  {{ assignee.name }}  {{ assignee.first_name }}
{{ agent.name }}  {{ agent.first_name }}  {{ app.name }}
```

An unknown token renders as an empty string rather than being echoed. A
customer reading `{{ requester.naem }}` learns that the desk types its
apologies into a machine; a customer reading nothing learns only that a
sentence is oddly worded. The cost is that a misspelled token fails quietly.

`agent.*` is whoever is sending, so it is empty when a rule sends the text.
Values are HTML-escaped when rendered into a template body, because a
requester called `<b>` must not become markup.

The admin screen's cheat sheet is generated from `TicketPlaceholders::TOKENS`,
so a token it offers is a token the renderer knows.

## The agent half

`GET /agent/tickets/{ticket}/reply-templates` returns the templates this agent
may use on this ticket, with the bodies already rendered. Throttled at 120/min
and authorised as a comment rather than as a read: a template is the words you
are about to send, so somebody who may not send anything has no business
seeing the list.

The reply box fetches once on mount and hides the control entirely when the
list comes back empty — a button that exists only to say "nothing here" is
worse than no button. Inserting appends rather than replaces: an agent who has
typed two sentences and then reaches for the standard closing paragraph means
"and this".

Nothing special happens on the way back in. An inserted template is the agent's
own words by the time they press send, and it goes through the same endpoint,
policy and sanitiser as anything else they type. There is no second write path
to secure.

## The automation half

`{"type": "reply_template", "template_id": 12}`. The runner loads the template
through `sendableOn($ticket)` — active, and either unscoped or on the ticket's
own team — and posts it through `TicketService::comment()` with no author, so
it is audited, e-mailed and visible exactly as an agent's reply would be, but
reads as the desk rather than as whichever agent was named in the rule.

Anything that stops it is reported in the execution log rather than failing
silently: a template that was deleted, switched off, or has since moved to
another team all come back as `no such template for this ticket`.

Language is deliberately not substituted here. A rule names one template, and
an administrator who picked the Dutch one meant the Dutch one. A desk that
wants a rule to answer in the requester's language writes one rule per
language with a matching condition.

## Permissions

`templates.manage`, its own permission rather than `settings.manage`:
curating the words a desk sends is editorial work, and the person who does it
well is often a senior agent rather than whoever administers the instance.

Using a template needs no permission of its own — the comment policy already
decides whether this person may reply to this ticket at all, and the picker
only shows what the reply box would have accepted.

## Seeded set

`ReplyTemplateSeeder` writes four templates in each language: an
acknowledgement, a request for more detail, a closing reply, and one internal
note. They are the worked examples as much as the starting point — somebody
who reads them learns what a placeholder is and why the note flag exists,
without opening the manual.

Idempotent on the slug, and it never updates an existing row: a seeded
template that the desk has edited stays edited.
