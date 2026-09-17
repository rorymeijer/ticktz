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

## D4 — The PHP floor tracks what is actually built and tested

The brief specifies PHP 8.3, and that was the floor through phase 12.

It has moved twice since, both times for the same reason: a supported runtime
should be one the project actually builds and tests on, not one it merely
declares. `composer.json` allowed `^8.2` originally, was tightened to `^8.3`
with the Laravel 12 upgrade ([D50](#d50--laravel-12-because-11-carries-unpatched-advisories)),
and is now `^8.4` ([D55](#d55--laravel-13-and-a-php-84-floor)).

The Docker image, the CI matrix and this constraint are kept in step. Claiming
support for a runtime nothing verifies is a promise with nothing behind it.

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

## D33 — The next asset tag is derived from the data, not from a counter

`asset_tag` is the human key: the sticker on the box, what somebody types into
a search when they can read half of it, what an import matches on. It is unique
and indexed for those reasons rather than because an id was not enough.

Generating the next one asks the table for the highest tag with this prefix and
adds one, inside a locking transaction. A `ticket_sequences`-style counter
would be cheaper and would be wrong here: assets arrive in bulk by CSV import
carrying tags somebody else allocated, so the counter would be behind reality
from the first import and start handing out tags that already exist.

The lookup includes soft-deleted rows. A tag belonging to a laptop that was
written off last year must never be reused for a different machine, or every
historical ticket that mentions it becomes ambiguous.

## D34 — A relation is stored once and read in both directions

`asset_relations` holds one row per relationship. Reading it from the other
asset's side inverts the label: `installed_on` becomes `hosts`, `depends_on`
becomes `required_by`, and `connected_to` is its own inverse because a cable
has no direction.

The alternative — a row per direction — is the obvious implementation and it is
how a CMDB rots. The two halves are written at different times by different
people, they drift, and eventually a laptop is both installed on and hosting
the same dock. Nobody can tell which row is wrong, so they stop trusting the
data, and a CMDB nobody trusts is worse than no CMDB at all because it is still
being maintained.

Writing the mirror of a relation that already exists is therefore a no-op
rather than a second row, and an asset cannot be related to itself.

## D35 — Tags and serials are matched with LIKE, even on MySQL

Everything else about asset search goes through the FULLTEXT index. Tags and
serial numbers deliberately do not.

A FULLTEXT index tokenises on word boundaries, so `LAP-0042` is stored as `LAP`
and `0042`, and `DL5440-8827512` as two tokens that neither match a search for
`8827512` as a prefix nor for `5440-88`. The search somebody actually performs
is the half of a sticker they can still read, typed into the box — and that
search returning nothing while the asset is plainly in the register is how a
register stops being used.

So two indexed-ish LIKE clauses run alongside the index. On a register of tens
of thousands of rows that is a scan; it is also the difference between a search
box that works and one that does not, and a CMDB is not the table you paginate
a million rows out of.

## D36 — An import previews before it writes, and never fails a whole file

Nobody starts a CMDB empty; they start it from a spreadsheet, and that
spreadsheet is always slightly wrong. Two consequences shaped the importer.

**The upload reports, and a second explicit request writes.** The operator sees
how many rows create, how many update, and exactly which ones are malformed,
before anything touches the table. An import that acts on the first click is
one you have to undo by hand, and nobody has ever undone four hundred rows by
hand.

**Rows are matched on `asset_tag` and each one is its own transaction.**
Matching makes the import idempotent — the second run is usually somebody
re-running it after fixing three rows, and that must update rather than double
the estate. Per-row transactions mean one bad date on row 400 does not roll
back the 399 good ones above it. Columns the file does not carry are left
alone, so a CSV without `location` does not blank the locations somebody typed
in by hand.

It also reads what spreadsheets actually produce rather than what a
specification would prefer: semicolons as well as commas, a UTF-8 BOM, headers
in any capitalisation, `31-12-2026` as well as `2026-12-31`, `€ 1.299,00` as
well as `1299.00`. A desk that has to reformat its own export before importing
it will not import it.

The parsed rows sit in the session between the two steps rather than the file
being written to disk. It is somebody's complete asset register, it is needed
for about thirty seconds, and a copy on disk is one more thing to protect.

## D37 — The portal payload names what may be shown

`Asset::toPortalArray()` lists the fields a requester may see. It is not
`toSummaryArray()` with `purchase_cost` and `notes` removed.

The difference matters the next time somebody adds a column. A payload built by
subtraction leaks every field added after it was written — the supplier
discount, the internal note about which cupboard the spare key is in — because
nobody remembers to go back and remove them. A payload built by naming shows
nothing new until somebody decides it should.

The same reasoning puts the scope for *which* assets a requester sees on the
model (`visibleToRequester`) rather than in the controller: their own kit, plus
colleagues' when the organisation shares tickets, and never the desk's own
stock.

## D38 — Dashboards read a rollup, not the ticket table

Every figure on the reporting screen comes out of `report_daily_metrics`: one
row per day, per metric, per dimension value.

Aggregating the ticket table on each page load is fine at a thousand tickets
and unusable at a million, and it puts a full-table scan behind a screen people
leave open all day and refresh out of habit. The rollup turns that into an
indexed range read, and the write side is one grouped query per metric per
dimension per day rather than a walk over rows.

**A day is recomputed, never incremented.** A counter bumped as things happen
drifts the first time a queued job is retried, a ticket is edited straight in
the database, or a deploy lands mid-request — and a wrong counter announces
nothing. A day rebuilt from its source rows is either right or reproducibly
wrong, and re-running it is always safe, which is what lets the schedule rebuild
today every quarter of an hour and the last week every night.

The rebuild *replaces* the day rather than upserting into it. A metric that
produced rows yesterday and none today has to end up with none; an upsert alone
leaves the old figures standing and the number never goes down.

`dimension_id` is `0` rather than NULL for "no dimension". MySQL treats NULLs as
distinct in a unique index, so a nullable column would let the same
`(date, metric, dimension)` be inserted any number of times and the
replace-then-insert would quietly become an append.

## D39 — An SLA outcome counts on the day its clock finished

Not the day the ticket was raised.

Bucketing by creation date is the intuitive choice and it makes a month's
compliance figure keep changing for weeks after the month ends — a ticket
raised on 30 September and breached on 3 October retroactively worsens
September, over and over, as the tail comes in. Nobody can report a number that
behaves like that.

An outcome recorded is an outcome. Once a clock has finished, the day it landed
on is fixed, and a figure that has been reported stays reported.

## D40 — A breach is `breached_at`, not `status = 'breached'`

The SLA engine stamps `breached_at` the moment a target passes and leaves the
clock **running** until the work is actually done. `status = 'breached'` is
only reached when a timer finishes late.

So a promise already broken on a ticket somebody is still working has a breach
date and a `running` status, and the first version of the collector — which
counted statuses — reported the demo desk at 100% compliance while its ticket
list was visibly full of late work. That is the precise failure a dashboard
must never have: not an error, a plausible wrong number.

A timer that breached and was then finished late carries both columns. It is
one outcome, and it is a breach, counted on the day it broke.

The general lesson is the one worth keeping: when a model has both a status
enum and a timestamp that means the same thing, the timestamp is usually the
fact and the status is usually a summary of where the row is in a lifecycle.
Report on the fact.

## D41 — Two exports, streamed, with a BOM

People want two different things from "export", and giving them one is how a
report ends up being re-derived in a spreadsheet anyway: the **figures**, which
is the chart as numbers, and the **tickets behind them**, which is what
somebody pivots to answer the question the screen did not anticipate.

Both stream rather than being built in memory — a year of tickets is the export
somebody will ask for, and a desk at this scale has enough of them to exhaust a
PHP process assembling the string first. The ticket export chunks by id rather
than by offset, because an offset walk re-scans everything it has already sent.

Both start with a UTF-8 BOM. Excel reads a CSV as the local codepage unless one
says otherwise, which turns every Dutch name in the file into mojibake; three
bytes prevent a support ticket about the tool that exists to prevent support
tickets.

The ticket-level export additionally requires `tickets.export`. Exporting a
year of tickets is exporting the desk's whole record of who asked for what, and
that is a different act from reading a compliance percentage.

## D42 — The charts are hand-drawn, and the palette is validated

Inline SVG rather than a charting library. The whole of it is a path and some
text; a dependency that rendered it would be larger than the rest of the front
end put together, and the brief asks for a small footprint.

What the charts do follow is a set of rules that are not aesthetic:

- **Never two y-axes.** A first response is minutes and a resolution is days,
  so they are two charts. Putting them on one plot means choosing an arbitrary
  alignment between two scales, which invents a correlation that is not in the
  data — the single most common way a dashboard misleads.
- **Status colour means status.** Met and missed are a judgement and use the
  good/critical pair; created and resolved are identities and use categorical
  slots in fixed order, never cycled, never reassigned by rank.
- **The palette was run through a validator**, not chosen by eye: colour-vision
  separation, lightness band, chroma floor and contrast against the white card.
  The passing figures are recorded in the tokens file so a future change is
  re-validated rather than nudged.
- **A legend whenever there are two series**, so identity never rests on colour
  alone.
- **An axis whose labels repeat is a broken axis.** Five ticks evenly spaced
  across a maximum of two round to `2 / 2 / 1 / 1 / 0`, and the reader is left
  working out which label their value sits against; small integer ranges get
  one tick per unit instead.
- **One number is a stat tile.** Compliance, clearance and the averages are
  figures, not plots; only what varies over time is drawn.

## D43 — A token is the intersection of its scopes and its owner's permissions

Every API scope names the permissions its holder must already have, and the
middleware requires both: the scope was issued *and* the owner still holds
something behind it.

The alternative — a token that simply carries its own abilities — makes a token
a snapshot of yesterday's access that keeps working after the account it
belongs to has been demoted. Checking both means a token can never be an
escalation (ask for every scope in the catalogue and a requester's token still
reaches only what a requester reaches), and that removing somebody's role
narrows every token they ever minted, including the ones everybody has
forgotten about. Nobody has to go hunting.

The same check runs at issue time, so the UI offers only the scopes the person
could actually exercise. A checkbox that mints a token which then 403s is a bug
report waiting to happen.

`comments.write` accepts `portal.submit` as well as `tickets.comment`, which
looks like a hole and is not: a requester replying to their own ticket holds no
`tickets.comment` permission — the policy lets them through as a participant.
Leaving it out would refuse the most ordinary integration there is, a portal or
chat bot posting a customer's reply, and the per-record policy still runs.

## D44 — One error shape, with codes we own

Laravel's defaults are sensible per exception type and inconsistent taken
together: `{errors:…}` for a 422, `{message:…}` for a 404, something else for a
403. A caller then writes three handlers, or, more often, one that works until
it meets the second shape. Everything under `/api` comes back as
`{"error": {"code", "message"}}`, with `fields` added for validation.

`code` is a stable string chosen by us, never an exception class name —
renaming a class must not break somebody's integration.

Two consequences worth naming:

- **A record you may not see answers 404, never 403.** Telling them apart is a
  way to enumerate the key space: `SUP-1` through `SUP-9999` is a short loop and
  the answer is a map of the desk's volume. So visibility is checked first and
  answers 404; the policy then decides whether the *action* is allowed, which is
  a genuine 403.
- **A 500 says nothing.** The message goes to the log, where the operator can
  see it and the caller cannot — except in debug mode, where no external caller
  is watching.

`/api/*` answers JSON even without an `Accept` header. An integration that
forgets the header should not get an HTML error page it cannot parse.

## D45 — The API writes through the services, and stamps its own source

No API controller writes to a ticket. Everything goes through `TicketService`,
the same path the console and the mail poller use, so numbering, watchers,
lifecycle timestamps, the audit trail, the SLA clocks and the approval gate are
identical whichever door the change came in through. An API that reimplements
any of that is an API that drifts from the UI, and then people stop trusting
whichever one they are not looking at.

Two things a caller does not get to decide. `source` is always `api` — where
work comes from is a number the desk reports on, and a caller that can claim to
be the portal makes it meaningless. And the opening status comes from the
workflow, not the request, or a ticket can be created already resolved with no
clock and no trace of why.

Status changes are their own endpoint rather than a field on `PATCH`, because
they are not a field edit: the workflow decides which moves are legal and an
approval can stand in the way. Both refusals are `422` with the reason — the
move is not allowed *yet*, which is a different thing from a malformed request.

## D46 — Rate limiting is per token, not per account

Two integrations owned by the same service account are two callers. An
instance-wide limit cannot tell them apart, so one of them polling in a loop
locks the other out and the only fix is to lower the limit for everyone. Each
token carries an optional ceiling and gets its own bucket, keyed by token id —
which also means revoking a token frees its counter rather than leaving an
exhausted one behind for its replacement to inherit.

## D47 — A delivery row is written before the request, not after

An event that was accepted but whose queue never ran it still leaves a
`pending` row, so "we never got it" has an answer. Writing the row inside the
job would mean the deliveries that vanish are exactly the ones with no trace.

One job per subscription, not per event: a slow endpoint never holds up a fast
one, and a retry against a dead host never re-delivers to the three that
already succeeded. After twenty consecutive failures a subscription switches
itself off — an endpoint gone all week is not coming back inside a backoff, and
every attempt is a queue slot somebody else could have used. It stays in the
list, disabled and with its count, because deleting it would lose the reason.

On the `sync` driver a failing delivery is reported and dropped rather than
thrown. It would otherwise travel back up through the listener into whatever
raised the event, and a ticket that cannot be filed because somebody else's
server is down is a far worse failure than a webhook nobody received. (The same
trap as the approval mailer in D31, met again in a different place.)

## D48 — The webhook payload names what may leave the building

Built field by field, never by handing over a model — the same rule as the API
resources, and for the same reason: a column added next year must not start
appearing on an external endpoint because nobody remembered to exclude it.

Two deliberate omissions. **Internal notes** carry `is_internal` and no body: a
receiver has no policy to run and no user to check, so the only safe assumption
is that whoever operates the endpoint is not entitled to the agent's side of the
conversation. And **`ticket.updated` names the fields that changed, not their
new values** — anything more is a ticket export over a webhook.

Slugs are sent, never display names. A receiver keying off "In behandeling"
breaks the moment somebody renames a status; the point of a slug is that it
does not move.

The signature covers the exact bytes of the body rather than the fields, so the
receiver's check is one line and cannot drift from what was actually sent. The
event id is in the body and is reused across retries and across endpoints,
which is what lets a receiver be idempotent — at-least-once is what a retrying
sender gives you.

## D49 — Documentation and secrets are checked mechanically, not by eye

Two guards added this phase, both because the failure mode is silence.

**`docs/openapi.yaml` is tested against the route table**, in both directions.
A hand-written spec drifts the moment somebody adds a route and forgets the
docs, and a spec that is wrong is worse than none: an integrator trusts it,
writes against an endpoint that does not exist, and blames their own code.

**Every literal `t('...')` key in the frontend is checked to resolve.** A
missing key does not throw — it renders its own name, so a button reads
`common.close` and survives a typecheck, a build and a full test run until a
person notices. Adding the test immediately turned up two labels that had been
rendering raw keys on the SLA and automation screens since phases 5 and 6.

Both are cheap, and both catch a class of bug that no amount of care catches
reliably.

## D50 — Laravel 12, because 11 carries unpatched advisories

The brief specifies Laravel 11, and the project was built on it. Late in the
work `composer audit` began reporting three advisories against
`laravel/framework` 11.56.1, the most serious being a **CRLF injection in the
default `email` validation rule** (GHSA-5vg9-5847-vvmq / CVE-2026-48019, rated
high) alongside a signed-URL path confusion.

None of them are fixed on the 11.x line. The fix exists only in 12.61.1 and
above, so "stay on the version the brief named" and "ship without known
vulnerabilities" cannot both hold.

Shipping them was not defensible for this product in particular. Ticktz
validates e-mail addresses at the boundary everywhere — the portal, user
administration, LDAP sync, inbound mail, the API's `requester_email` — and then
puts those addresses into outgoing mail headers. A CRLF injection in the rule
that is supposed to make an address safe is precisely the shape of bug this
codebase is most exposed to, and the brief is explicit that all data stays on
the operator's own infrastructure with a full audit trail: an instance
compromised through its own notification mail undermines the whole premise.

So the framework moved to 12.x and the brief's version number did not survive
contact with a security advisory. Recorded here rather than quietly done,
because it is a deviation from the specification.

**What it cost:** nothing, as it turned out. `laravel/framework` was the only
package that had to move (plus a `ramsey/uuid` patch and a polyfill); every
other dependency already declared Laravel 12 support. No application code
changed. The 669-test suite passed unaltered, all 70 documentation screenshots
re-rendered without a failure, and migrations, seeders, the scheduler and the
API all came up clean.

The Laravel 12 changes that usually bite do not apply here: Carbon was already
on 3.x, and the codebase uses no `image` validation rule (12 stops treating SVG
as an image), no `Concurrency` facade, no `Number` helpers and no schema
introspection.

`composer.json` now requires `php: ^8.3` rather than `^8.2`. Laravel 12 would
accept 8.2, but Docker builds on 8.3 and CI tests 8.3 and 8.4 — claiming
support for a runtime nothing verifies is a promise with nothing behind it.

## D51 — The accessibility budget is zero, and a machine enforces it

`scripts/accessibility.mjs` runs axe-core over eighteen pages — every shell,
signed in as the role that works in it — and fails on any violation. It runs in
CI against a booted demo instance.

Zero rather than a threshold, because a tolerated violation is one nobody ever
fixes. The number only ever goes up from there, and each addition is
individually reasonable.

The first run justified the whole exercise. It found a **critical** unnamed
account menu on sixteen pages: its trigger is an avatar and a chevron, both
decorative, so to a screen reader the control had no name at all. Nobody
looking at those screens for six phases had seen it, because there is nothing
to see.

What it cannot do is also worth stating. axe checks the rendered DOM, so it
says nothing about keyboard order, focus visibility, or whether a label makes
sense to a person. It does not check SVG contrast either — the destructive icon
buttons were measured by hand and turned out to be at 1.9:1, under the 3:1 WCAG
asks of meaningful graphics. The automated gate is a floor, not the ceiling.

## D52 — Colour is derived where the colour is not ours

Statuses, priorities, labels and asset types all carry a colour an
administrator picked. A tinted chip that uses that colour as its text produces
whatever contrast the colour happens to give: a mid-tone amber on its own 10%
tint measures 2.85:1.

There is no palette fix, because the palette belongs to the operator. So
`resources/js/lib/contrast.ts` derives the ink instead — keep the hue they
chose, darken it until it clears 4.5:1 against the background it will actually
sit on. A colour that already passes is returned untouched, which is most of
them.

Where the palette *is* ours, it is measured rather than eyeballed. The avatar
colours were the Tailwind 600 shades; half of them sat between 3.19 and 4.10
against the white initials they carry. Every hue moved one shade darker, worst
case now 5.02, and the figures are recorded in the code so a future change gets
re-measured rather than nudged. (The same discipline as the chart palette in
[D42](#d42--the-charts-are-hand-drawn-and-the-palette-is-validated).)

Writing the tests for the helper found the bug worth having: an unparseable
colour was returned unchanged, so a chip could paint white on white —
invisible, rather than merely low-contrast. It now falls back to slate-700.

## D53 — A backup is three things, or it is not a backup

`scripts/backup.sh` archives the database, the storage directory **and**
`.env`, together, and says so loudly.

`APP_KEY` lives in `.env` and decrypts the mailbox and directory passwords held
in the database. Restore a database onto an instance with a different key and
you get something that starts, shows every ticket, lets everyone sign in — and
cannot read a single mailbox, with nothing in the log that explains why. That
is a far worse failure than a restore that refuses, so `restore.sh` compares
the keys and warns before it overwrites anything.

Two consequences follow. The archive holds working credentials, so it is as
sensitive as the instance and the script sets `0600`/`0700` accordingly. And
`restore.sh` deliberately does **not** overwrite `.env`: a new host usually
needs a different `APP_URL`, different mail settings and different TLS, so the
archived copy is placed alongside as `.env.restored` to diff. The one line that
must be carried across is named explicitly.

Both scripts were exercised against a stub standing in for `docker compose`,
because the environment they were written in has no Docker daemon — the guards,
the confirmation, the key-mismatch warning and the artefact layout are
verified; a run against a live MySQL container is not.

## D54 — Version 1.0.0, and what that promises

Semantic versioning, read for a self-hosted application: a **major** may
require a manual step during an upgrade, a **minor** never does, and a
**patch** never changes the database. That is the promise an operator actually
needs, and it is the one the release notes are written against.

The release workflow refuses to publish when the tag and `TICKTZ_VERSION`
disagree. A container that reports a different version from the tag it was
built from is the kind of thing nobody notices until they are trying to work
out what is in production.

Images are built for `linux/amd64` and `linux/arm64`, and the release is
**drafted** rather than published: generated notes are a commit list, and a
release worth tagging is worth a paragraph written by a person.

## D55 — Laravel 13, and a PHP 8.4 floor

Laravel 13.32 is the current line, and the project moved to it. `laravel/tinker`
had to go to 3.x with it; nothing else in the tree needed a constraint change,
and no application code changed.

The interesting part is the runtime. Laravel 13 itself accepts PHP 8.3, and it
accepts **either** Symfony 7.4 or Symfony 8. Symfony 8 requires PHP 8.4.1, so
there was a real choice: pin Symfony back to 7.4 and keep 8.3 working, or take
Symfony 8 and raise the floor.

The floor moved to 8.4. Pinning a major dependency backwards to preserve a
runtime nothing builds or tests on is the kind of arrangement that quietly
rots — and PHP 8.3 leaves active support in December 2026, two months from
this release. Ticktz ships as a container that controls its own PHP, so the
only people affected are those running it outside Docker, who can install 8.4.

`composer.json`, the Dockerfile and the CI matrix all moved together, because
the whole point of the constraint is that it matches reality.

Verified the same way as [D50](#d50--laravel-12-because-11-carries-unpatched-advisories):
669 tests unchanged, zero axe violations across eighteen pages, the API and the
webhook pipeline exercised against a running instance, migrations clean from
scratch, and `composer audit` empty.

## D56 — The installer asks one real question, and closes behind itself

A setup wizard at `/install`: requirements, database, application,
administrator, optional e-mail. The only question with weight is where the data
lives, and it has two answers — the MySQL that ships in the compose file, or a
server the operator already runs. The built-in option is offered only when the
environment visibly has one to point at, because recommending a database that
is not there is worse than asking for four fields.

Three properties it is built around.

**Nothing is written until the last step.** The whole wizard is one page and
one request. A fresh instance has no configured session store to keep
half-finished answers in, and database credentials are the last thing to park
in a session on a half-configured server, so they stay in the browser until the
request that uses them. Closing the tab costs the typing and nothing else.

**`.env` is written last, after the migrations.** This was learned by running
it: the first version wrote `.env` before migrating, and `artisan serve` —
which watches that file — restarted the process mid-migration, leaving 67
tables' worth of work as 16 and no marker. The same thing happens in the wild
with a config reloader or a supervisor watch. With the durable write at the
end, the worst case is a prepared database and a configuration to write by
hand. The other way round is a half-built database and a wizard that will try
again against it.

**It closes behind itself.** The wizard is unauthenticated by necessity — there
is nobody to authenticate as before the first account exists — and it accepts
database credentials, writes `.env` and creates an administrator. Left
reachable on a running instance it is a takeover in three screens. So its
acting endpoints answer 404 afterwards, with no setting, parameter or header
that re-opens them; re-running it is `ticktz:install --force`, which requires
shell access. Only the GET redirects to login, because a bookmarked `/install`
landing on a 404 reads like a broken deployment and leaks nothing.

An instance that predates this feature — migrated, with users — counts as
installed and adopts the marker. Dropping somebody's working desk into a setup
wizard on upgrade would be unforgivable.

The console path (`ticktz:install`) runs the same service, so the two cannot
drift.

## D57 — Running the installer found four bugs that reading it did not

Worth recording, because each was invisible until the thing actually ran
against a real MySQL server.

**The password rule called an external API.** `Password::uncompromised()` asks
Have I Been Pwned whether the administrator's password has been breached. It is
k-anonymous and well intentioned, and it made installation depend on reaching a
third party over the internet: an air-gapped or firewalled deployment could not
create its own administrator, and every install made an outbound call the
operator never asked for. In a product whose premise is that nothing leaves
your infrastructure, that is the wrong trade. Removed; the length and
composition rules stay.

**`bcmath` was listed as required.** Copied from the list everybody copies.
Nothing in the dependency tree asks for it, no application code calls it, and
the full suite passes without it — it would have blocked installs on servers
that work perfectly. The required list is now derived from what
`composer.lock` actually declares for production, plus `pdo_mysql`, which no
package declares because none of them knows which driver we chose.

**Seeding reached for Redis.** The settings repository caches, the default
cache is Redis, and at install time that is a hostname nobody has verified —
on a first run outside Docker it is usually not there at all. The install now
runs against an in-memory store and leaves the real cache to `.env`. An
installer must not depend on a service it is not configuring.

**`email_verified_at` is not mass-assignable.** Correctly so — verification
state is not something a form should set. Under the strict-model guard it threw
loudly; in production it would have silently dropped the field and left the
first administrator unverified. It is set with `forceFill` now, because the
person who just proved they control the server is verified by construction.

The general point: every one of these passes code review. None of them survives
one honest run.

## D58 — Full-text ranks, LIKE guarantees

Ticket, asset and knowledge base search all used MySQL's FULLTEXT index with a
LIKE fallback *for other drivers*. Running the suite against MySQL rather than
SQLite showed three tests failing that had passed for thirteen phases, and the
cause was worth more than the fix.

InnoDB full-text does not see what a person searching a service desk expects:

- **Words below `innodb_ft_min_token_size`** — three by default, so "AD" never
  matches anything and "VPN" sits on the edge.
- **Stopwords**, which InnoDB has its own opinions about.
- **Partial words.** "verbind" finds nothing in "verbinding"; "Latitude" was
  found, but only because the term happened to be a whole token.
- **Rows in an uncommitted transaction.** Which is what every test runs
  inside — and how this surfaced at all.

So the LIKE pass now runs *beside* the index rather than instead of it: the
index ranks, and LIKE guarantees that what is on the screen can be found by
typing part of it.

It costs nothing. Every one of these queries already OR-ed a
leading-wildcard LIKE next to the `MATCH … AGAINST` — for the ticket key, for
the asset tag — so none of them could use the full-text index alone in the
first place. The plan was already a scan; it is now a scan that returns the
right rows.

The wider lesson is about the test database. SQLite in memory is fast and it is
not MySQL: it has no FULLTEXT, different NULL semantics in unique indexes, and
different date arithmetic — all three have now bitten this project. CI runs the
suite against MySQL 8 for exactly that reason, which is the check that caught
this.

## D59 — Thirteen phases of green CI that was not green

The first pull request revealed that CI had been failing since phase 0, and
nobody — me — had looked.

Two failures, both real, both invisible from a working directory:

**The Docker image had never built.** `directorytree/ldaprecord` declares
`ext-ldap` as a hard requirement, and no stage of the Dockerfile installed it,
so `composer install` refused to resolve the lock file and the build died
before writing a package. I had been reporting "both compose files valid" as
evidence that Docker was fine. `docker compose config` parses YAML. It does not
build anything, and I knew that.

**The test suite could not run from a clone.** `phpunit.xml` declares a `Unit`
suite pointing at `tests/Unit`, git does not track empty directories, and the
directory only existed on my machine. Pest exits 2 on a missing suite
directory, before running a single test.

Both are the same mistake in different clothes: verifying against the
environment I was standing in rather than the one the code ships into. The
local suite passing says nothing about a clone; a compose file parsing says
nothing about an image building.

What makes it worth recording rather than quietly fixing: the checks that
caught these were already written and already running. They were not being
read.

## D60 — Four LDAP tests that had never run

The same pull request turned up four failing tests in
`LdapAuthenticationTest`, all reporting nothing more useful than "These
credentials do not match our records". They had been red since phase 1 and
invisible locally, because the file skips itself when `ext-ldap` is missing and
the extension was not installed in the development container. A skipped test
looks exactly like a passing one at the bottom of the run.

Building the extension and running them turned one symptom into two bugs.

**The distinguished name is an attribute like any other.** A raw LDAP search
result is a bag of multi-valued attributes, and `dn` is not exempt: some
servers return the plain string, others return `['count' => 1, 0 => '…']`.
Casting the array form to string is a PHP warning, Laravel promotes warnings to
`ErrorException`, and `LdapAuthenticator::attempt()` catches `Throwable` so one
directory being unreachable cannot block local sign-in. The result was a
sign-in that failed with a generic message and a log line reading "Array to
string conversion". Both shapes are now normalised at the single point where
the entry enters the application.

**A revoked group has to revoke the role.** Group mapping recomputed roles when
somebody matched a mapped group, but fell back to "assign the default role
unless they already have one" when nobody matched — so taking a person out of
the service desk group in AD left their agent role in place forever. With a
group map configured, the directory is authoritative and the account is reset
to the default role.

The one carve-out: when `sync_groups` is on but the map is still empty, nothing
is recomputed. That is the state an administrator is in halfway through
configuring the screen, and flattening every account to the default role at
that moment would lock them out of it.

## D61 — A database flag written for a version we do not run

The development stack pinned `mysql:8.0` and passed it
`--mysql-native-password=OFF`. That option was added in MySQL **8.4**. MySQL
does not ignore a setting it does not recognise — it aborts:

```
[ERROR] [MY-000067] [Server] unknown variable 'mysql-native-password=OFF'.
[ERROR] [MY-010119] [Server] Aborting
```

So `docker compose up` never started a database, and every service that waits
on one failed behind it. It is the same lesson as D59, one layer down: this was
listed in the 1.0.0 pull request under "not verified" precisely because no
daemon existed to run it, and it broke the first time somebody did.

The fix is not to delete the flag. The flag was right and the image was wrong:
MySQL 8.0 left support in April 2026, so the stacks now pin **8.4**, the
current LTS, and CI runs the suite against the same version rather than against
one nobody deploys. With 8.4 the flag is unnecessary anyway — it already
defaults to `caching_sha2_password` — so the compose files carry no
version-specific database flags at all, which is the property that keeps this
from happening again on the next bump.

`docker/mysql/ticktz.cnf` lost `default-authentication-plugin` in the same
change, and for the same reason pointing the other way: that one was **removed**
in 8.4 and would have aborted the server exactly as the command-line flag did.
One version-specific setting was hiding a second one.

A note for whoever hits this in their own stack, because the error compose
prints is not the error that matters: a failed first boot leaves a partly
written data directory, and the official image only initialises an empty one.
Fixing the setting alone leaves `Table 'mysql.plugin' doesn't exist` behind it.

## D62 — Rich text everywhere, and where it stops

Every field in Ticktz somebody writes sentences into is rich text: ticket
descriptions and comments, knowledge base articles, asset notes, request type
and approval instructions, approval reasons and decisions, e-mail signatures,
and multiline custom fields.

**Two profiles, not one.** An article is a document — headings, tables, images,
code blocks. A ticket reply is a message: emphasis, a list, a quote, a link,
and no heading, because somebody who can set a heading in a comment can make
their sentence twice the size of everybody else's. The editor toolbar is built
from the same profile name the server sanitises with, so what somebody can
click and what survives the save cannot drift apart.

The difference between the profiles is *unwrapped*, never dropped. A profile
says what a field offers, not what is dangerous, so a reply pasted out of a
Word document loses its heading and keeps its sentence; a heading becomes a
bold lead line rather than being left loose at block level. Only the removed
list — scripts, forms, iframes, media — takes content with it.

**Sanitising is declared on the model.** A ticket description is written by an
agent form, a portal submission, an API client, an inbound e-mail and an
automation rule; a comment by five more. Putting the sanitiser in each service
method means the one added next year is the hole, and the hole is stored XSS.
`HasRichText` fills the plain-text companion column in the same pass from the
same cleaned value, so the search index cannot disagree with the screen.

It cannot cover a write that never touches the model. `Builder::update()` fires
no model events, and `ApprovalService::decide()` uses one deliberately — its
claim has to be atomic so two approvers clicking at once produce one answer.
That one sanitises its own comment, and a test exists precisely because it is
the kind of thing that gets forgotten.

**Every rich column has a plain-text twin**, or the FULLTEXT index moves onto
one. Indexing the markup would make every tag name a term: a search for "code"
would find every ticket containing a code block, "strong" would find half the
desk. The same applies to automation conditions — a rule saying "description
contains password" means the word.

**E-mail has two real parts now.** The HTML part fills the template's
placeholders with markup under one rule: *a placeholder alone in a paragraph is
replaced by the block it stands for; a placeholder inside a sentence by its
text.* That is exactly how the shipped templates read — `{{ ticket.description }}`
on its own line, `{{ requester.first_name }}` inside "Hello …" — and it is what
stops a formatted reply landing inside a paragraph as `<p><p>…</p></p>`, which
is invalid and which every mail client recovers from differently. Everything an
administrator wrote around the placeholders is escaped.

### Where it stops, and why

Names, keys, slugs, e-mail addresses, CSV mappings and automation conditions
stay plain. Markup in a name is not formatting, it is a way to make one row
look like another.

**E-mail templates stay plain text.** They are written with placeholders and
rendered into both parts of a message; asking somebody editing a notification
to think about markup would be a worse editor, not a better one.

**Inbound e-mail still arrives as text.** ~~The HTML part of a customer's reply
is not kept.~~ **Superseded by D65.** The reasoning below was sound and the
conclusion was wrong: cutting the quoted history *is* harder on HTML than on
text, which is an argument for doing that work rather than for throwing the
customer's formatting away. It is done now.

> What makes a mail thread readable is cutting the quoted history off the
> bottom, and that is far more reliable on text than on the nested
> `<blockquote>` and vendor-specific wrapper divs every client emits
> differently. Keeping the customer's formatting would be nice; keeping their
> last four replies quoted underneath it would not.

**Inline images are not in yet.** *(Done in D63.)* Pasting a screenshot into a reply is the
obvious next thing to want, and it needs an upload path, storage, and a policy
deciding who may read the file — the attachment system already has all three,
so it is a feature to add rather than a limitation to design around. The
message profile refuses `<img>` until then rather than allowing an element with
nowhere to point.

### The cost

TipTap is 129KB gzipped, in its own chunk, loaded only on the pages that have
an editor. That is a real number and worth stating plainly. The alternative to
a library here is not "no library" — keeping selection, undo and paste
normalisation correct across browsers is a multi-year problem — it is a worse
one.

## D63 — Pasting a screenshot, and who may see it

Tickets, replies and knowledge base articles take images. Paste, drop or pick
one and it uploads to `/rich-text/images`, lands outside the web root, and
comes back as a relative URL the editor puts in the text.

**Not the attachments table**, which was the obvious place to look first. An
attachment belongs to a ticket — the column is not nullable and the whole
attachment policy stands on it — while an image pasted into an article belongs
to no ticket, and one pasted into a draft nobody has filed yet belongs to
nothing at all. Covering both would have meant making `ticket_id` nullable and
teaching the policy to cope, which is a change to the thing that decides who
may download somebody's payslip. A second table was cheaper and safer.

**An image has the audience of whatever it was pasted into.** It is bound to
its owner when the text referencing it is saved, and read through that owner's
own rules: everyone who may read the ticket sees the screenshot in it, and one
on an internal note stays unreadable to the requester who may read everything
around it. Between the paste and the save it belongs to nothing and only its
uploader may see it — treating an unclaimed upload as public for convenience
would make paste-and-abandon the easiest way to host a file on somebody else's
server.

**Only our own images, in a message.** A remote `<img>` is a tracking pixel:
whoever controls the URL learns the IP address, the time and the user agent of
every person who opens the ticket — which on a service desk is an agent, their
team lead, and whoever it is escalated to. A requester needs no account and no
cleverness to plant one; they need a mail client that pastes a signature. The
brief this was built to says no third-party trackers, and honouring that for
the trackers we ship but not for the ones our own input field accepts would be
honouring it in the wrong direction.

The first version of that check read only the URL's path, so
`https://tracker.test/rich-text/images/<uuid>` — our path, somebody else's host
— walked straight through it. A test caught it. A scheme or a host of any kind
is now a refusal, protocol-relative `//host/…` included.

Articles keep the wider rule and may still embed a remote image. They are
written by staff, on purpose, and an administrator pointing at a diagram on
their own intranet is a different act from a stranger's signature arriving in a
ticket. An operator who disagrees has one line to change.

**Images are a property of the field, not of the profile.** The two are
orthogonal: an asset note has a vocabulary but no readership of its own, so an
image pasted into one would be bound to a record nothing can authorise — a
broken image at best and a question about who may read it at worst. Three
fields declare `images: true`, and the editor offers the button in exactly
those three.

**They travel with the mail.** A relative path behind a policy renders as a
broken image in an inbox, which is worse than no image because it looks like
the desk sent something and lost it. Each one is attached by `cid:` and
filtered per recipient through the same policy, so an internal note's
screenshot reaches the agents it was mailed to and is removed from anybody
else's copy.

**An alt text box**, because a screenshot with no description is nothing to a
screen reader and an accessibility gate we pass by not shipping images is not a
gate. It starts as the file name — a poor description, and a far better
starting point than an empty string.

**A prune command**, because every abandoned draft leaves its screenshots
behind. Most are a login screen; some are a payslip. Unowned rows older than
the configured window are deleted daily, file and all.

## D64 — Three ways to serve a page that nobody could have found by reading it

Within a day of the first release the development stack failed three times, in
three different places, all for the same underlying reason: it had never been
run. The test suite says nothing about any of them, because the suite runs
against `artisan serve` with a built bundle and a SQLite file — a shape that
shares almost nothing with the stack an operator starts.

**The database never started.** A flag written for MySQL 8.4 passed to an 8.0
image; MySQL aborts on an unknown variable. (D61)

**The healthcheck said it had.** `mysqladmin ping` as root reports a running
server just as cheerfully when the data directory was initialised without the
application's user and database, which is what a volume left over from the
first failure gives you. The dependency gate opened, the app started, and the
first query failed with "Host … is not allowed to connect to this MySQL
server" — error 1130, which sounds like a network or a grant problem and is
neither. The healthcheck now connects as the user the app connects as, to the
database the app uses, so a half-initialised volume is reported wrong where it
is wrong.

**The page was white.** Vite has to listen on `0.0.0.0` to be reachable from
outside its container, and laravel-vite-plugin writes whatever it is listening
on into `public/hot` — which is the URL the *browser* is then told to load the
application from. `http://0.0.0.0:5173` is not an address a browser can fetch.
Chrome quietly treats it as localhost and the page works; Safari and Firefox do
not, and the page is blank with nothing in any log, because the server did its
job and the browser was handed an address that does not exist. `server.hmr.host`
is what the plugin writes instead.

The third one is the one worth sitting with, because unlike the other two it
needed no Docker to find. Running the dev server and reading the file it writes
would have shown `http://0.0.0.0:5173` on any machine. It was not found because
nobody looked at the development path at all — the built bundle was what every
check exercised, and the hot file only exists on the path no check took.

## D65 — Finding where the person stopped writing

Inbound mail keeps its formatting now. A customer who sends a numbered list
arrives with a numbered list, rather than four lines that happen to begin with
digits.

D62 said this would not be done, and gave a real reason: the hard part of
inbound mail is not the markup, it is knowing where the reply ends and the
quoted thread begins, and that is genuinely harder on HTML than on text. What
that reason actually argues for is doing the work. Throwing away what the
customer wrote because the boundary is hard to find is solving the wrong
problem — the boundary has to be found either way, since a reply with its last
four exchanges quoted underneath is unreadable in any format.

**Every client marks the boundary differently and none of them agree.** Gmail
wraps the history in `div.gmail_quote`; Outlook puts a `divRplyFwdMsg` header
above it; Apple Mail uses `blockquote type="cite"`; Thunderbird writes a
`moz-cite-prefix` line; Proton, Yahoo and Zoho each have their own class. And
plenty of clients mark it with nothing at all beyond the sentence "On … wrote:".

So the rule is: find the earliest point in the document that is unambiguously
the start of the quoted thread, drop it and everything after it, and touch
nothing else. Each rule is one a client actually emits — none of it is a guess
about what a quote might look like.

**Conservative where it matters.** A cut that takes too much loses what the
customer wrote, which is far worse than a reply carrying one quoted paragraph
too many: the second is obviously wrong to whoever reads it, and the first is
invisible. So a cut that would leave nothing behind is refused outright, which
keeps a bare forward whole; an attribution line is only read as one when it is
short, because an element that begins "From:" and runs for three paragraphs is
somebody quoting a header inside their own sentence.

The first version of that guard walked the tree by hand and had it exactly
backwards — it cut the one case the guard exists to protect. A test caught it,
and `preceding::text()` now asks the question in one expression instead of ten
lines of traversal. That is the second time in this work that hand-rolled tree
walking was the bug and XPath was the fix.

**The text part is not a fallback for rare cases.** Plenty of mail is sent as
text, and some HTML mail turns out to be nothing but a quoted thread once the
reply has been taken out of it. Both land in the same shape as anything typed
into the editor.

**Signatures stop carrying pixels.** Inbound mail goes through the same
sanitiser as everything else, and the message profile only allows an image this
instance is serving — so the remote `<img>` in a mail signature is dropped.
That was not the goal of this change and it is the part with the most security
in it: a tracking pixel in a customer's signature reports on whichever agent
opens the ticket, and every agent it is escalated to afterwards.

What is still not kept: images the customer attached inline with `cid:`. The
files are stored as attachments and listed on the ticket as they always were,
but the picture does not appear in the body. Mapping a `cid:` reference onto
the attachment it belongs to is the obvious next step and a smaller one than
this was.

## D66 — Upgrading from the browser, without letting the browser write code

**Decision.** Administration → Updates can check GitHub for a new release and,
on a source install, install one. Both are off by default and switched on
separately. The web request never writes a file: it creates an `upgrades` row
naming a published version, and the queue worker — running as whoever owns the
code — does the work.

**The constraint that shaped everything else.** A web-facing PHP process must
never be able to write the application's own code. A service desk accepts
uploads from anybody with an e-mail address, so if the web process could write
code, every file-write bug anywhere in the application would become a way to
run code — a path from "the attachment validator has a hole" to "somebody is
executing PHP of their choosing". That is not a risk worth accepting for the
convenience of a button.

So the split is not a queue used for its own sake. It is the security boundary,
and the readiness checks on the screen are how an operator finds out whether
their deployment actually keeps the two apart, or only looks like it does. One
of those checks reports that the web server *can* write the code — as a warning
rather than a block, because that exposure predates this feature and refusing
to upgrade does not remove it.

**The request carries no input at all.** `POST /admin/updates` reads nothing
from the body. What gets installed is what the checker independently reports is
on offer, and the worker confirms the same answer again before it touches
anything. There is no endpoint here that takes a URL, a tag, a path or a
version — so the worst a forged request can achieve is asking for the upgrade
the screen was already offering, and a stale page cannot install an old version
over a new one.

**The swap is a standalone script, run detached.** `vendor` is being replaced,
and the process doing the replacing is running out of it. PHP loads files as it
needs them, so a job that moves `vendor` aside and then calls anything it has
not already loaded dies halfway with the old tree gone and the new one not yet
in place. The script has no framework, no autoloader and no dependency beyond
what PHP ships with; everything it needs is baked in as a literal. It reports
into a plain file, because for the duration of it there is no database
connection to write to and no application to write it with. The next request,
served by the new code, turns that file into a row. This is how Nextcloud's
updater works, and for the same reason.

**What the release owns is named, not inferred.** The swap replaces a fixed
list of paths rather than "everything except". An installation holds things
nobody here knows about — an operator's own script, a certificate, a directory
some earlier version created — and an upgrade that deletes what it does not
recognise is an upgrade that loses somebody's work. `.env`, `storage` and
`public/storage` are deliberately absent from the list, which is also what lets
the backup of the old tree live under `storage` and survive the swap that
creates it.

**A failed move rolls back; a failed migration does not.** If the tree cannot
be taken apart, everything already moved goes back, because a tree half
dismantled is worse than one never touched. If the migrations fail afterwards,
the new code stays. Migrations that failed halfway have already touched the
schema, and putting the old code in front of a half-migrated database is a
second broken state rather than a recovery — so the failure is loud and names
the path to the previous version, and a person decides.

**No "is a worker running" check.** The honest version needs a heartbeat this
application does not have, and the dishonest version — reading the jobs table —
answers yes precisely when work is piling up unconsumed. The screen answers it
by observation instead: an upgrade nothing has picked up in five minutes is
reported as stuck, with the reason.

**Docker installs are shown the command.** Recreating a running container needs
the Docker daemon, and a web process must never be able to reach it. That is a
deliberate limit rather than a missing feature, and the screen says so in those
words instead of offering a button that would have to lie.
