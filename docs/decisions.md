# Design decisions

Every entry records a choice that was *not* fully specified by the build brief
(`docs/PROMPT.md`), the alternatives considered, and why the default was picked.
The brief asks for sensible defaults to be chosen and written down rather than
for questions to be asked — this file is that record.

## D1 — Headless UI instead of Radix

**Brief:** "Tailwind + a light headless component set (e.g. Radix)."

Ticktz uses `@headlessui/react`. It is what Laravel Breeze's React scaffold
already installs, it covers the four primitives the app actually needs (dialog,
menu, transition, combobox), and it ships as a single package rather than one
package per primitive. Radix remains a drop-in alternative; no component in
`resources/js/Components/UI` exposes Headless UI types in its public props, so
swapping the implementation stays a local change.

## D2 — Hand-rolled i18n runtime on the client

**Brief:** "Full i18n via translation files (NL + EN out of the box)."

Translations live in Laravel's `lang/{locale}/*.php` files — one source of truth
for both PHP and the browser. `App\Support\UiTranslations` flattens the UI
groups into a `group.key => string` dictionary that Inertia shares with every
page; `resources/js/lib/i18n.ts` implements the two features Laravel's format
actually uses in this app (`:placeholder` replacement and `singular|plural`).
Adding `i18next` would have meant a second dictionary format, a second loading
mechanism, and ~40 kB of bundle for no extra capability.

A test (`tests/Feature/LocalizationTest.php`) fails the build when the locales
drift apart, so a new string cannot land in English only.

## D3 — No webfonts, no CDN, no telemetry

The Laravel/Breeze scaffold links Figtree from `fonts.bunny.net`. That is an
outbound request per page load from every user's browser to a third party,
which conflicts with the privacy requirement and breaks air-gapped installs.
Ticktz uses a system font stack instead. Nothing in the built page references an
external origin.

## D4 — PHP 8.3 as the floor, 8.4 supported

The brief specifies PHP 8.3. The Docker image is built on `php:8.3-fpm-alpine`
and `composer.json` requires `^8.2`; CI runs the suite on both 8.3 and 8.4 so
the codebase keeps working on distributions that ship the newer runtime.

## D5 — MIT license

The brief says the project is published on GitHub and also used inside a Dutch
government/legal organisation. MIT imposes the fewest obligations on internal
forks and is the most widely understood license for a self-hosted tool. EUPL-1.2
would be the alternative if copyleft is required; changing it is a one-file
change.

## D6 — Separate queues per workload

`high`, `default`, `mail`, `webhooks`, `low`. A mailbox poll that stalls on a
slow IMAP server must never delay SLA breach detection, and a customer's broken
webhook endpoint must never block notification e-mail. The worker container
consumes them in priority order; `TICKTZ_QUEUES` makes the split configurable
for operators who would rather run dedicated worker containers per queue.

## D7 — The app container migrates, the worker does not

Both containers run the same image. On boot the `app` role waits for MySQL and
runs `php artisan migrate --force` (switch off with `TICKTZ_AUTO_MIGRATE=false`);
the `worker` role only waits. Running migrations from two containers at once is
a race, and the app container is the one that must not serve traffic against an
out-of-date schema.

## D8 — SQLite for the test suite, MySQL in CI

`phpunit.xml` points at an in-memory SQLite database so the suite runs in
seconds on a laptop with no services. CI additionally runs migrations and the
full suite against MySQL 8, because that is what production uses and because
index/collation mistakes only surface there. Migrations therefore avoid
MySQL-only DDL unless it is guarded by a driver check.

## D9 — Custom field choices are single-language

A custom field's *label* is translatable per locale; the individual choices of
a select are not. Translating them would mean either a second nested
translation editor in the field dialog or a separate translation table, and in
practice choice values ("Laptop", "GIS") are either proper nouns or already
shared between both languages.

The storage shape (`options` as `[{value, label}]`) leaves room for a
`label_translations` key if that turns out to be wrong; nothing needs a
migration to add it.

## D10 — Custom field values are stored polymorphically

`custom_field_values` keys on `(field, entity_type, entity_id)` rather than
adding a column per field. Reads cost a join; writes cost nothing; and an
administrator can add a field on a Friday afternoon without a migration and
without an `ALTER TABLE` on a table with a million rows. The alternative — a
JSON column on `tickets` — would have made "every ticket where cost_centre =
9999" unindexable, which is exactly the query reporting needs.

## D11 — The portal builds its own payload

`Portal\RequestController` does not reuse the agent payload with the sensitive
parts removed; it constructs its own from scratch, loading only public
comments and only public custom fields. A view that starts with everything and
subtracts is one careless edit away from leaking; a view that starts with
nothing and adds is not.

## D12 — E-mail templates are text with tokens, not Blade

Notification templates are edited by service desk managers in the admin UI and
stored in the database. Rendering them as Blade would mean user-supplied input
compiled and executed on the server: one `{{ system('...') }}` away from remote
code execution, and only an administrator-level permission between an attacker
and that. So a template is plain text and `{{ token }}` is substituted by a
lookup in a fixed map. Anything in double braces that is not in the map renders
as nothing — never echoed back into a customer's inbox, never evaluated.

The cost is that templates cannot loop or branch. Six notifications with a flat
placeholder list have not needed to.

## D13 — Inbound mail is claimed before it is processed

The `inbound_messages` row is inserted first, inside a transaction, against a
unique `(email_channel_id, message_hash)` index — before a ticket, a comment or
a user account exists. A redelivery loses that race and stops; it does not
create a second ticket.

This ordering also decides what happens when processing dies halfway. The
message is only marked read (or moved, or deleted) on the IMAP server *after*
the database says it is handled, so a crash leaves the message on the server to
be re-fetched — where the claim will refuse it if the work actually completed.
The failure mode is a message that is never processed twice, at the cost of one
that may be fetched twice. For a ticketing system that is the right way round.

A message with no Message-ID gets a synthetic one hashed from the channel,
sender, subject and body, so the same mail arriving twice still deduplicates.

## D14 — GreenMail, not MailHog, in the development stack

The brief allowed either. MailHog is the more familiar SMTP sink, but it has no
IMAP server, so half of Phase 4 — reading mail back in and turning it into a
ticket — could not be exercised in the dev stack at all. GreenMail speaks both
(SMTP on 3025, IMAP on 3143, web interface on 8025) with authentication
disabled and mailboxes created on first use, so `docker compose up` gives a
working round trip: reply to a ticket, watch it arrive, reply to that, watch it
land back on the ticket as a comment.

## D15 — A timer copies its target rather than reading it from the goal

`sla_timers` stores `target_minutes`, `due_at` and the calendar it was computed
against, and only keeps the policy and goal as provenance. The alternative —
resolving the target through the goal on every read — means an administrator
who shortens a target on Tuesday has retroactively breached every ticket opened
on Monday, and deleting a policy erases the promises it made.

The cost is that correcting a genuinely wrong target does not fix the tickets
already running under it. That is the right way round: an SLA report has to be
reproducible, and a number that changes when someone edits a form is not.

Changing what a ticket *is* — its priority, its queue — does recalculate, but
explicitly, from the original start, and it writes a `recalculated` event
saying what moved and why.

## D16 — Breaches are swept, and everything that acts is claimed first

A breach is the absence of an event: nothing happens, and that is the problem.
So something has to go and look, and a job runs every minute.

That job must be safe to run twice — a worker dies mid-sweep, the scheduler
fires while the last run is still going, an operator runs it by hand. Both
guarantees come from the database rather than from being careful:

- A breach sets `breached_at` before dispatching anything, and the sweep only
  selects timers where it is null.
- An escalation writes its `escalated` event, inside a transaction, before it
  acts; a threshold that already has one is skipped.

So the sweep can run as often as it likes and each thing happens once. The
failure mode is a breach noticed a minute late, never one announced twice.

## D17 — Escalations notify by adding watchers

An escalation that needs to tell someone adds them as a watcher rather than
sending its own mail. The notification machinery from phase 4 then does what it
already does, in the recipient's language, with the right threading — and there
is one path to somebody's inbox rather than two that drift apart.

It also leaves a visible trace: an agent looking at an escalated ticket can see
who was pulled in, which a silent mail would not show.

## D18 — Escalation actions are a closed set, not a rule language

An SLA escalation can notify, raise the priority one step, or hand the ticket to
a team. That is all, and it is on purpose: a breach also emits `SlaBreached`,
which the automation engine in phase 6 can act on with arbitrary conditions and
actions. Building a second rule engine inside the SLA feature would mean two
places to look when a ticket does something unexpected.

The escalations live as JSON on the goal rather than in their own table, because
an escalation is meaningless without the target it is a fraction of, the list is
short, and it is always read whole.

## D19 — Conditions are a flat AND/OR list, not a nested expression editor

A rule's conditions are joined by "all" or "any", with no parentheses and no
nesting. That is a limit, chosen on purpose.

A nested boolean editor is hard to build, harder to read back six months later,
and the rules people actually write are flat. A desk that genuinely needs
`(A and B) or (C and D)` is better served by two rules than by one nobody can
explain — and two rules each have their own line in the execution log, which is
the thing you will be reading when it misbehaves.

The fields, operators and actions are constants on the model rather than an
open string. The admin form is generated from them, so a rule the UI can
express is a rule the engine can run. An unknown field fails *closed*: it
matches nothing. The worst case of a malformed rule is a rule that does
nothing, never one that reassigns every ticket on the desk.

## D20 — The loop guard's state travels in the job

A rule that sets a field emits `ticket.updated`, which is a trigger, which can
run the rule again. Two rules that each undo the other do the same thing more
slowly. This is the failure mode every automation engine has, and it does not
announce itself — it looks like a busy worker and an audit log filling up.

Two limits: a rule runs at most once per ticket per cascade, which kills any
ring however long it is; and a cascade is depth-capped as a backstop.

The part that matters is *where that state lives*. A queued job runs in a fresh
process, so anything held in a static would reset on every hop and the guard
would never fire — precisely when it is needed. So the depth and the set of
pairs already run are constructor arguments on the job, read from the
request-scoped guard at the moment the rule's own change is made. The listener
that dispatches is therefore deliberately *not* queued: it only enqueues a job,
and doing that inline is what keeps the cascade intact.

The depth is also written to each execution row, so a runaway chain is visible
in the log rather than only in the guard's refusals.

## D21 — Skipped evaluations are logged, with the condition that caused them

`automation_executions` records every evaluation, not just the ones that did
something. A log of successes cannot answer "why did my rule not fire?", which
is the only question anybody asks of an automation feature.

The failing condition is stored structurally as well as in words. The sentence
alone reads "priority is 1", which is not an answer; the structured form lets
the admin screen print "Priority is Urgent", in the reader's language, using
the same code that prints the rule itself.

The log is pruned nightly — it grows by rules × ticket events per day, and a
table nobody trims is a table that eventually makes the page it feeds slow.

## D22 — Two visibility scopes, not one scope with a flag

An internal article reaching the portal is the one failure this feature must
not have. A single scope taking `bool $includeInternal` would put that decision
at every call site, and the call site that forgets is the one that leaks.

So there are two scopes on `KbArticle`, written to be read side by side.
`visibleOnPortal()` is the narrow one and every portal query goes through it —
nothing in the portal controller narrows or widens it by hand.
`visibleToAgent($user)` starts from the same set and only ever *widens*:
`kb.view.internal` adds internal articles, `kb.manage` adds drafts. An agent
holding neither sees exactly what a requester sees, which is the property worth
having, because it means the portal's guarantee is not a second implementation
of the agent's.

The category's visibility is checked as well as the article's. A public article
inside an internal category is a mistake somebody will make, and the portal is
the wrong place to find out.

A direct URL to an article the reader may not see answers 404 rather than 403.
The existence of an internal article — "Runbook: decommissioning the Zandvliet
domain controllers" — is itself information.

Linking is held to the same rule. An agent supplies an article id, so the id is
resolved *through their own scope*; guessing one is not a way around the
permission. The panel on a ticket also filters on every render rather than
trusting what was linked, so an article that has since been made internal stops
appearing to an agent who may not read internal articles. The row stays — the
link is part of that ticket's history.

## D23 — The body is sanitised on write, in one place

`kb_articles.body` holds HTML that a requester's browser renders. Sanitising on
*read* is the arrangement that fails: it has to happen in the portal page, the
agent page, the admin preview, the search excerpt and the next surface somebody
adds, and one of those will be missed.

So it happens once, on the way in, inside `ArticleService` — and nothing writes
to `kb_articles` outside that service. A controller cannot forget, and an
import or a seeder gets the same treatment as the editor. That is what makes it
defensible for the React components to hand `body` straight to
`dangerouslySetInnerHTML`, and the guarantee is written down at both ends.

The config starts from an empty `HtmlSanitizerConfig`. Symfony's
`allowStaticElements()` looks like the obvious shortcut and is the wrong one:
it installs a broad default set — `<marquee>` among them — so the explicit list
would be *adding to* a default-allow config instead of being the allowlist.
Allowlist means allowlist.

Worth writing down because the names invite the opposite reading:
`dropElement()` removes an element **and its content**, while `blockElement()`
removes only the tag and keeps the text. So `<script>` is dropped and `<font>`
is blocked — if those two were swapped, pasted text would vanish and script
bodies would survive as plain text.

Restoring an old version re-sanitises it rather than trusting it. A body stored
before the allowlist last changed is not necessarily safe under the allowlist
as it stands now.

`body_text` — the same content flattened — is stored alongside and is what
search reads. A query for "href" should find articles that discuss links, not
every article that contains one.

## D24 — A version records what was there before the edit

The snapshot is written *before* the new text lands, in the same transaction as
the update, and the counter increments after. Two consequences, both wanted:
the history can never be half-written, and restoring a version is "put back
what was there" rather than a reconstruction.

Restoring snapshots the current text first, so restoring the wrong version has
cost nobody anything.

Only a change to the title or the body spends a version. Re-categorising an
article, moving it up the list or publishing it does not — a history where half
the entries say nothing is a history nobody reads.

## D25 — LIKE fallback tokenises the way the index does

Search uses MySQL's FULLTEXT index in production and falls back to LIKE on
SQLite, which is what the tests run on. A fallback that behaved differently
would mean the tests pass on something no user has.

The instructive case: the ticket page seeds its search from the ticket's own
subject, so the term is often a whole sentence. "Printer keeps jamming" matched
verbatim against an article called "Printer paper jam" finds nothing at all,
and the suggestions panel would look broken while the query was working
perfectly. So the fallback splits the term, drops words under three characters,
and keeps the whole phrase as well so an exact match still scores.

## D26 — Reading an article does not touch `updated_at`

`recordView()` increments the counter with a query-builder update — `toBase()`,
deliberately out of Eloquent — because an Eloquent save stamps `updated_at`.

An article does not become newer because somebody read it. Without this, the
admin list sorted by "recently updated" would reorder itself by whoever
happened to click what, and "Updated just now" would appear under an article
nobody has touched in a year.

## D27 — One approval mechanism, not three

The brief asks for single, parallel and sequential approval. Building those as
three code paths would mean three sets of settling rules, three sets of bugs,
and an admin screen that asks the operator to pick a shape before it can ask
them anything useful.

They are one thing. A workflow is an ordered list of **steps**; the approvers
inside a step are asked at the same time and its `mode` says whether one of
them is enough or all of them are; more than one step makes it sequential,
because step two does not open until step one is settled. Single approval is
one step, one person, `any`.

The label the admin screen prints is *derived* from the steps rather than
stored alongside them. A workflow whose label and steps could disagree is a
workflow whose label is a lie, and it would disagree the first time somebody
added a second step.

## D28 — Approvers are resolved once, when the step opens

The names go into `approval_decisions` at that moment, and everything
afterwards reads those rows rather than re-running the query.

A team gaining a member tomorrow must not quietly change who was asked today.
An `all` step must not grow a new blocker halfway through. And a decision row
that exists from the moment somebody is asked is what makes "who was asked,
and when" answerable for the approvals nobody ever answers — the half of the
audit trail a decisions-only table loses.

The cost is that fixing a wrongly-configured step means cancelling the run and
starting again. That is the right way round: an approval that silently changed
its mind about who it was asking would be worth nothing as evidence.

Three people are always dropped — anybody inactive, anybody without an e-mail
address, and the requester themselves, because approving your own request is
not an approval. When that empties a step it is skipped and the skip is
recorded; when it empties the whole approval nothing is opened and the caller
is told, because a ticket held forever by an approval addressed to nobody is
worse than one that was never held.

## D29 — The gate is in the service, and it fails closed

`requires_approval` is checked in `TicketService::transition()`, not in a
controller. An agent's click, an automation rule and a reply arriving by
e-mail all go through that method, and a gate the API can walk around is not a
gate.

It fails closed: a ticket with **no** approval at all has not been approved, so
a gated transition refuses it. The alternative reading — only block when an
approval exists and is pending — is the one that feels friendlier and is
useless, because any ticket that reached the workflow by another route (an
e-mail, an agent filing it by hand) would walk straight past the control. The
way forward for such a ticket is to raise an approval on it, which the panel on
the ticket page does in two clicks.

`tickets.approval_state` caches the newest run's status so a ticket list does
not become a subquery per row — the same bargain `sla_breached` makes. It is
written *before* `ApprovalCompleted` is dispatched, because a rule listening for
"approved" will try to transition the ticket, and the gate it runs into reads
that column. A listener catching up afterwards would be a race the desk would
experience as "sometimes the rule works".

## D30 — Answering is not a permission, and a super-admin cannot do it either

`approvals.decide` says somebody may take part in approvals at all. The only
person who may answer a given decision is the person that decision names,
while it is still open — enforced on `ApprovalDecision` by its own policy,
which ignores every permission in the system.

Including super-admin. `Gate::before` short-circuits every other check in
Ticktz and is made to stand down for this one. An approval an administrator
could have given on somebody's behalf is worth nothing as evidence, and the
audit entry recording it would be a record of a permission rather than of a
decision. "The budget holder approved this" has to mean the budget holder.

There is a legitimate need to unstick an approval whose named approver has
left or was never the right person. That is a cancel and a new run, which is
recorded as exactly that rather than as somebody's approval.

## D31 — The e-mail link opens a page; the page decides

An approver answering from their inbox is the difference between an approval
that takes an hour and one that takes a week. It is also the only
unauthenticated route in Ticktz that changes anything.

So the link is a `GET` that renders a page and decides nothing; the buttons on
that page `POST`. A link that decided on GET would be answered by the first
mail scanner, corporate link-rewriter or browser prefetcher that touched the
message — silently, and indistinguishable from a real approval. That is the
worst failure this feature could have, and it is the default behaviour if you
put the decision in the URL.

The token is a bearer credential and is treated as one: 48 random characters,
stored only as a SHA-256 hash (indexed equality lookup, exactly like a password
reset), cleared the moment the decision lands so a forwarded mail cannot be
replayed, and expiring. It is minted **inside the mail job** rather than at the
call site, because minting it earlier would put a working credential into a
queue payload, where it sits in Redis in the clear and is written to
`failed_jobs` if delivery goes wrong.

A dead link gets one page for "expired", "already answered" and "never
existed": which of the three it was is information about somebody else's
approval, and the holder of a dead link cannot act on any of them anyway.

`TICKTZ_APPROVAL_EMAIL_LINKS=false` turns the whole thing off — no link, no
token minted at all — for a desk approving things where signing in first is the
right trade.

## D32 — A deadline reports; it never decides

A step can carry `due_hours`, and passing it marks the approval overdue,
chases the people who have not answered, and nothing else.

Auto-approving on expiry would make every approval in the system a statement
about how long somebody waited rather than about what they agreed to — and the
ones that mattered most would be exactly the ones nobody read. Auto-rejecting
would refuse things nobody refused. Both turn an audit trail into noise.

So overdue approvals are reported and left open. The reminder cadence is read
from `notified_at` on each decision row rather than from a "last reminded"
column on the run, so a reminder that failed to send is retried on the next
sweep instead of being skipped forever.
