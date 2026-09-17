# Automation

A rule says: **when** this happens, **and** these things are true, **then** do
that. Nothing more elaborate — but that covers most of what a service desk
does by hand a hundred times a week.

Configured in **Administration → Automation** (`/admin/automation`), which
requires the `automation.manage` permission.

## When — the triggers

| Trigger | Fires on |
| --- | --- |
| A ticket is created | Every new ticket, whatever the channel |
| A ticket is changed | Any field change. Narrow it with a "field that changed" condition |
| Someone comments | Any comment, public or internal |
| The status changes | Any transition |
| A ticket is assigned | An assignment or a hand-over |
| An SLA target is missed | A breach the SLA sweep detected |
| An SLA escalation fires | A threshold on an SLA goal |
| On a schedule | A cron expression, over the open tickets |

One trigger per rule. A rule that fires on three different things is three
rules wearing a trenchcoat, and its execution log is unreadable.

### The scheduled trigger

For the things that happen *because nothing happened*: "close resolved tickets
nobody has come back to in a week", "nudge anything untouched for three days".
It walks the open tickets — resolved and closed ones are left out — and
evaluates the rule against each.

The cron expression is read in the instance's timezone, the same as every other
schedule here, so `0 3 * * *` means three in the morning where the desk is. An
expression that does not parse is refused at the form, and never runs.

Each ticket in a sweep gets its own cascade, so a rule matching two hundred
tickets is two hundred independent runs.

## And — the conditions

Conditions are joined by **all** (every one must hold) or **any** (at least
one). There is no nesting and no parentheses; see [D19](decisions.md). A rule
with no conditions applies to everything its trigger fires on, which is how you
write "on every new ticket, do X".

| Kind | Fields |
| --- | --- |
| The ticket | Status, status category, priority, queue, team, assignee, requester, organisation, request type, mailbox, source, subject, description, label |
| Time | Age in minutes, minutes since any activity, minutes since the requester wrote |
| State | Assigned, answered, SLA missed |
| The comment | Internal note, the comment text — only on the comment trigger |
| The change | The field that changed — only on the change trigger |

Operators: is, is not, is one of, is none of, contains, does not contain,
starts with, is empty, is not empty, is more than, is less than, is yes, is no.

An unknown field or operator **fails closed** — it matches nothing. A malformed
rule does nothing; it never reassigns every ticket on the desk.

## Then — the actions

| Action | Notes |
| --- | --- |
| Assign to | A person |
| Hand to team | |
| Set the priority to | |
| Move to queue | |
| Change the status to | Refused if the workflow does not allow it |
| Add / remove the label | |
| Post a comment | Internal by default; supports placeholders |
| Add a watcher | Which sends them the usual notification |
| Call a webhook | https only, queued, optionally signed |

Everything goes through the same service an agent's action does, so an
automated change is audited, emits its events and respects the workflow. A
transition the workflow forbids is reported in the log, not forced.

Automated changes are attributed in the audit trail to the **rule by name**, so
a ticket that reassigned itself at three in the morning says which rule did it.

### Comment placeholders

```
{{ ticket.key }}  {{ ticket.subject }}  {{ ticket.status }}
{{ ticket.priority }}  {{ requester.name }}  {{ assignee.name }}
```

Anything else renders as nothing. Substitution, never evaluation.

### Webhooks

The URL must be `https`. The payload is the rule, the trigger and the ticket,
posted as JSON on the webhook queue with a backoff of roughly one, five and
twenty minutes.

Give the rule a secret and the request carries:

```
X-Ticktz-Signature: sha256=<hmac of the exact body>
X-Ticktz-Event: ticket.created
```

Verify it against the raw body, not the parsed fields:

```php
hash_equals(
    'sha256='.hash_hmac('sha256', $rawBody, $secret),
    $request->header('X-Ticktz-Signature'),
);
```

## Order, and stopping

Rules run in `position` order within their trigger; lower numbers first. A rule
that matches **and** has "stop after this rule" set keeps the rules below it
from running on that ticket. A rule that has it set but does *not* match stops
nothing.

## Loops

This is the failure mode an automation engine has, and it does not announce
itself: a rule that sets a field emits a change, which is a trigger, which can
run the rule again. Ticktz enforces two limits:

1. **A rule runs at most once per ticket per cascade.** That kills direct
   self-triggering and any A→B→A ring, however long.
2. **A cascade is at most five deep**, configurable with
   `TICKTZ_AUTOMATION_MAX_DEPTH`.

The execution log records the depth of every run, so a chain of rules reacting
to rules shows up as `↳2` rather than only as a guard refusal. See
[D20](decisions.md).

## The log

**What automation has done** lists every evaluation — including the ones that
decided to do nothing, and which condition decided it. That is the point: "why
did my rule not fire?" cannot be answered from a log of successes.

| Result | Means |
| --- | --- |
| Ran | The conditions held and the actions did what they say |
| Skipped | A condition did not hold. The row names it |
| Failed | An action could not do its job — the row says why |

Filter by rule and by result. Rows are pruned after
`TICKTZ_AUTOMATION_LOG_DAYS` (30 by default); the log grows by rules × ticket
events per day.

## Trying a rule out

**Try it** on a rule asks for a ticket key and reports whether the rule would
match it — and if not, which condition stopped it. Nothing is changed and
nothing is logged. Writing a rule you cannot try out means testing it on real
tickets.

## Running it

Rules are evaluated on a worker, so a slow webhook never delays a customer.
That needs the worker container:

```bash
docker compose ps worker
docker compose logs -f worker
```

Scheduled rules also need `schedule:work`, which the worker container runs.

## Configuration reference

| Variable | Default | Meaning |
| --- | --- | --- |
| `TICKTZ_AUTOMATION_MAX_DEPTH` | `5` | How deep a chain of rules reacting to rules may run |
| `TICKTZ_AUTOMATION_LOG_DAYS` | `30` | How long execution rows are kept |
| `TICKTZ_QUEUES` | `high,default,mail,webhooks,low` | `default` runs rules, `webhooks` delivers them |

## Worked example

The one from the brief: *a new ticket in a queue goes to a team, at a higher
priority.*

- **When** a ticket is created
- **And** Queue is Service desk
- **Then** Hand to team Infrastructure · Set the priority to High

Save it, then **Try it** against an existing ticket to confirm it matches, and
watch the next real ticket appear in the log.

## Troubleshooting

**My rule never fires.** Open the log and filter by that rule. A *Skipped* row
names the condition that stopped it. No rows at all means the trigger never
fired — check you picked the right one, and that the rule is on.

**It fired but nothing changed.** The row's detail says what each action did.
"already on this team" means the ticket was already in that state; "transition
not allowed by the workflow" means the workflow refused.

**It fires twice.** It should not — a rule runs once per ticket per cascade.
Two rules with the same effect will both run; check the log for two rule names.

**A chain of rules ran away.** Look for `↳` markers in the log: those are runs
caused by another rule. The depth cap stops it at five, and reducing the cap is
a blunt but effective way to find the culprit.

**The webhook never arrives.** Check the worker, and the `webhooks` queue in
`TICKTZ_QUEUES`. A failed delivery is retried three times; after that it is a
failed job.
