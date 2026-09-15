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
