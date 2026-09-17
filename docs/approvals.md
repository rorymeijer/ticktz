# Approvals

Somebody with the authority to say yes has to say it, and the ticket does not
move until they do.

## Single, parallel, sequential — one mechanism

The brief asks for three shapes of approval. They are not three mechanisms.

A **workflow** is an ordered list of **steps**. The approvers inside a step are
asked at the same time, and the step's `mode` says whether one of them is
enough (`any`) or all of them are needed (`all`). More than one step makes it
sequential, because step two does not open until step one is settled.

| Shape | How it is built |
| --- | --- |
| Single | One step, one approver, `any` |
| Parallel | One step, several approvers, `any` or `all` |
| Sequential | Several steps |

The admin screen derives the label from the steps rather than storing it — a
workflow whose label and steps could disagree is a workflow whose label is a
lie.

## The shape of it

```
 approval_workflows        who has to say yes
        │
        ▼
   approval_steps          mode: any / all
        │                  approver_type: users · team · role · manager · field
        │                  due_hours
        ▼
 approval_requests         one run, on one ticket
        │                  current_position: which step is open
        ▼
 approval_decisions        one row per approver per step
                           written when the step opens, pending
                           who was asked, when, what they said, from where
                           ▼
                    tickets.approval_state   ← the transition gate reads this
```

The running instance is separate from the definition on purpose. Editing a
workflow never rewrites an approval somebody has already answered, and an
approval an agent raised by hand has no workflow behind it at all.

## Who gets asked

Resolution happens **once, when the step opens**, and the names it produces are
written into decision rows. A team gaining a member tomorrow does not quietly
change who was asked today, and an `all` step cannot grow a new blocker
halfway through.

| `approver_type` | Resolves to |
| --- | --- |
| `users` | The people named on the step |
| `team` | Everybody in the chosen teams |
| `role` | Everybody holding the chosen roles |
| `manager` | `users.manager_id` of the ticket's requester |
| `field` | The user or e-mail address answered on the request form |

Three people are always dropped: anybody inactive, anybody without an e-mail
address, and **the requester themselves** — approving your own request is not
an approval.

If that empties a step, the step is skipped and the skip is recorded, so the
trail says the step was reached and had nobody in it. If it empties the whole
approval, nothing is opened and the caller is told. A ticket held forever by an
approval addressed to nobody is worse than one that was never held.

## Settling

- **One refusal ends the whole approval**, whatever the mode. An approval a
  majority can override is not an approval.
- On an `any` step, the first yes settles it and the others are marked
  `skipped` rather than left pending — the history reads as asked, overtaken,
  no longer needed.
- A decision is claimed with a conditional update on its own `pending` state,
  so two clicks, a click racing an e-mail link, or a retried job all land one
  answer. The loser is told the approval has already been settled rather than
  silently overwriting the first.

## The gate

`workflow_transitions.requires_approval` is the gate, and it is enforced in
`TicketService::transition()` rather than in a controller — so an agent's
click, an automation rule and an inbound e-mail all meet the same wall. A gate
the API can walk around is not a gate.

**It fails closed.** A ticket with no approval at all has not been approved, so
a gated transition refuses it. The alternative — only blocking when an approval
exists and is pending — would let any ticket that reached the workflow by
another route walk straight past the control. The way forward for such a ticket
is to raise an approval on it, which the panel on the ticket page does.

`tickets.approval_state` caches the newest run's status so a list of five
hundred tickets is not five hundred subqueries. It is written **before**
`ApprovalCompleted` is dispatched, because a rule listening for "approved" will
try to transition the ticket and the gate it runs into reads that column.

## Deciding from an e-mail

An approver can answer from their inbox without signing in. That is the
difference between an approval that takes an hour and one that takes a week,
and it is also the only unauthenticated route in Ticktz that changes anything,
so:

- **The link is a `GET` that decides nothing.** It opens a page showing what is
  being asked, with two buttons; the buttons `POST`. A link that decided on GET
  would be answered by the first mail scanner, corporate link-rewriter or
  browser prefetcher that touched the message — silently, and
  indistinguishably from a real approval.
- **The token is a bearer credential**, so it is 48 random characters, stored
  only as a SHA-256 hash, cleared the moment the decision lands, and expired
  after `TICKTZ_APPROVAL_TOKEN_DAYS`. A forwarded mail cannot be replayed and a
  leaked database backup is not a pile of working approve links.
- **It is minted inside the mail job**, not at the call site, so a working
  credential never sits in a queue payload or a `failed_jobs` row.
- **The route is throttled**, and a dead link answers one page for "expired",
  "already answered" and "never existed" — which of the three it was is
  information about somebody else's approval.

Set `TICKTZ_APPROVAL_EMAIL_LINKS=false` to turn the links off entirely. The
notification then says what is waiting and links to the inbox, where the
approver signs in. No token is minted at all.

## Only the person who was asked may answer

This is the property the whole feature rests on, and it is deliberately **not**
a permission. `approvals.decide` says somebody may take part in approvals;
it never lets them answer in another approver's name.

Neither does being a super-admin. `Gate::before` short-circuits every other
check in Ticktz and explicitly stands down for this one, because an approval an
administrator could have given on somebody's behalf is worth nothing as
evidence — and the audit entry recording it would be a record of a permission
rather than of a decision.

Somebody genuinely needs to unstick an approval the named approver cannot
answer — they have left, or were never the right person. The way to do that is
to cancel the run and raise a new one, which is recorded as exactly that.

## Where approvals live

The inbox is at **`/approvals`**, outside both `/agent` and `/portal`. The
person who has to approve a hardware order is very often a budget holder who is
not an agent and has never seen the console — a requester, as far as the
permission system is concerned. One URL works in every notification, whoever
opens it, and the page picks the shell that matches its reader.

The portal navigation shows the link only when something is actually waiting;
most requesters are never asked to approve anything.

## Deadlines

A step's `due_hours` sets a deadline, and the deadline **reports**. It never
decides. Auto-approving on expiry would make every approval in the system a
statement about how long somebody waited rather than about what they agreed to,
and auto-rejecting would refuse things nobody refused.

An hourly job nudges the approvals nobody has answered, at the cadence in
`TICKTZ_APPROVAL_REMINDER_HOURS` (`0` switches it off). The cadence is read
from `notified_at` on each decision row, so a reminder that failed to send is
retried on the next sweep rather than skipped forever.

## Permissions

| Permission | Grants |
| --- | --- |
| `approvals.view` | See every open approval, not just your own |
| `approvals.decide` | Raise an approval on a ticket |
| `approvals.manage` | Configure approval workflows; withdraw anybody's approval |

Answering is not on this list, and that is the point. Being asked is what
entitles somebody to answer, and it also entitles them to read the approval —
so an approver with none of these permissions can still open their inbox and
decide.

## Configuration

| Setting | Default | What it does |
| --- | --- | --- |
| `TICKTZ_APPROVAL_EMAIL_LINKS` | `true` | One-time decision links in notifications |
| `TICKTZ_APPROVAL_TOKEN_DAYS` | `30` | How long such a link works |
| `TICKTZ_APPROVAL_REMINDER_HOURS` | `24` | Reminder cadence; `0` switches reminders off |

Request types carry an approval workflow (Administration → Request types), and
transitions carry the gate (Administration → Workflows). The `manager` approver
type reads `users.manager_id`, set on the user form.

## Audit trail

Raising, approving, refusing, withdrawing and skipping an empty step are all
recorded against the **ticket**, because that is where somebody will be reading
the trail. A decision that arrived through a one-time link records `source:
email`, so it is distinguishable from one somebody clicked while signed in.
