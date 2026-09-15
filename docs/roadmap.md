# Roadmap

Ticktz is built in phases, each ending in a runnable, tested state. This page
tracks what is shipped. The phase definitions come from the original brief in
[`PROMPT.md`](PROMPT.md).

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | Foundation, Docker stack, i18n plumbing, CI | ✅ Shipped |
| 1 | Auth, users, roles & permissions, teams, organisations, LDAP, audit log | ✅ Shipped |
| 2 | Ticket core & agent console | ✅ Shipped |
| 3 | Customer portal & request types | ✅ Shipped |
| 4 | E-mail (SMTP out, IMAP in) | ✅ Shipped |
| 5 | SLA engine & escalations | ✅ Shipped |
| 6 | Automation rules | ✅ Shipped |
| 7 | Knowledge base | ⏳ Next |
| 8 | Approvals | ⏳ Planned |
| 9 | Assets / CMDB | ⏳ Planned |
| 10 | Reporting & dashboards | ⏳ Planned |
| 11 | Public REST API & webhooks (Sanctum) | ⏳ Planned |
| 12 | i18n completion, accessibility, docs & release | ⏳ Planned |

## Phase 0 — Foundation & Docker

- Laravel 11 + Inertia + React 18 + TypeScript, built with Vite.
- `docker-compose.yml` (development) and `docker-compose.prod.yml` (production)
  covering nginx, php-fpm, a worker running `queue:work` and `schedule:work`,
  MySQL 8, Redis and a mail sink. One multi-stage image serves both app roles.
- `GET /health` probes the database, cache and queue and answers 503 when a
  dependency is down.
- Translation files under `lang/{en,nl}` shared with React through Inertia;
  `useTranslations()` is the only way UI copy is resolved.
- A small component library (`resources/js/Components/UI`) and the three shells:
  auth, agent console and portal.
- GitHub Actions: Pint, migrations against MySQL 8, Pest on PHP 8.3 and 8.4,
  TypeScript, Vitest and a Docker image build.

## Phase 1 — Auth, users & RBAC

- **Accounts.** `users` gains directory provenance, organisation membership,
  agent metadata, activation and soft deletes. Accounts are archived, never
  erased, so tickets keep their author.
- **RBAC.** A code-defined permission catalogue
  (`App\Support\PermissionCatalog`) seeded into `permissions`; roles are data.
  Every permission becomes a Laravel gate, and policies handle per-record
  decisions. A role flagged `is_super` bypasses individual checks — except the
  guards that stop an administrator locking themselves out.
- **Authentication.** Session-based sign-in against local accounts *and* any
  number of LDAP/Active Directory connections, at the same time. Directory
  users are provisioned on first sign-in and their group membership is
  re-evaluated on every sign-in, so revoking a group in AD revokes access here.
- **Directories.** Managed from the admin UI (`ldap_configs`), bind password
  encrypted at rest, with a connection test. `.env` remains supported for
  configuration-as-code deployments.
- **Teams & organisations.** Agent teams with leads; customer organisations
  that claim new requesters by e-mail domain and can share ticket visibility.
- **Audit log.** Every mutating action writes a before/after diff through
  `App\Services\AuditLogger`, with secrets redacted and non-human actors
  (system, automation, e-mail, API) labelled. Rows are never updated.
- **Settings.** Runtime configuration in `settings`, cached in Redis. Portal
  self-registration is off by default.
- **Console.** `php artisan ticktz:admin` bootstraps the first administrator;
  `php artisan ticktz:demo` fills a demo instance.

## Phase 2 — Ticket core & agent console

- **Tickets** with a readable key (`SUP-1042`) handed out by a locked sequence
  row, so two simultaneous submissions can never claim the same number. The key
  is the route parameter, which keeps a pasted URL meaningful.
- **Vocabulary as data.** Statuses, priorities and labels are editable; every
  status carries a *category* (`new`, `open`, `pending`, `resolved`, `closed`)
  that the engine reasons about, so renaming "In progress" to "Onderhanden"
  changes nothing but the label.
- **Workflows** decide which status changes are legal, edited as a matrix. A
  transition can require a comment, require an assignee, or demand an extra
  permission. A ticket keeps the workflow it was created under, so editing one
  never strands an in-flight ticket.
- **Queues** are saved filters rather than containers: a ticket appears in every
  queue whose criteria it matches, which is how a service desk actually works.
  One `TicketFilter` implementation serves both queues and the filter bar, so
  the two can never drift.
- **Conversation.** Public replies and internal notes in one timeline,
  interleaved with system events from the audit log and ordered by the audit
  sequence — timestamps only have second precision, and a reply and the status
  change it triggered routinely share one.
- **Attachments** are stored outside the web root and streamed through a
  controller that runs the policy first; a file on an internal note is not
  downloadable by the requester even with the direct URL.
- **Watchers, labels and ticket links** (relates / duplicates / blocks / causes
  / parent), with the inverse relationship derived when rendering.
- **Visibility** is defined once, in `Ticket::scopeVisibleTo()`, and mirrored by
  `TicketPolicy::view()`. A test asserts the two agree for every ticket,
  because a disagreement between the list query and the record check is a data
  leak.
- **Domain events** (`TicketCreated`, `TicketUpdated`, `TicketAssigned`,
  `TicketTransitioned`, `TicketCommented`) are the seam the notification
  pipeline, the SLA engine and the automation engine hook into later. Nothing
  writes to `tickets` outside `TicketService`.

## Phase 3 — Customer portal & request types

- **Custom fields** are defined once and attached wherever they are needed.
  Values are stored polymorphically, so adding a field is a form submission
  rather than a migration — see [D10](decisions.md).
- **Request types** carry both the form and the routing: which queue, team,
  workflow and priority a submission lands on. That is what turns "I need a
  laptop" into a ticket on the right desk without an agent triaging it first.
  A subject template (`Laptop for :employee_name`) builds the ticket subject
  from the answers.
- **Validation is built from the same data as the form**, so whatever an
  administrator attached is exactly what is required, validated and stored.
  Answers for fields a request type does not ask for are discarded rather than
  written — a crafted payload cannot reach an agents-only field.
- **Visibility** per request type: everyone, chosen organisations, or agents
  only. A type a requester may not use returns 404, not 403 — the existence of
  an internal request type is itself information.
- **The portal builds its own payload** rather than filtering the agent one
  down; see [D11](decisions.md). It loads only public comments and only public
  custom fields, and a requester cannot post an internal note whatever the
  payload says.
- **Portal categories** group request types; deleting one leaves its types
  reachable rather than hiding them.
- **Taxonomy names are translatable.** Statuses, priorities, labels, categories
  and request types are configured by an administrator but read by requesters,
  so their names are data with per-locale overrides and the base name as
  fallback.

## Phase 4 — E-mail

A service desk that only exists in a browser is a service desk half its users
will not use. This phase makes e-mail a first-class channel in both directions.

- **Mailboxes are data.** An `email_channels` row is one address: what replies
  are sent from (SMTP), where inbound mail is read (IMAP), and where those
  messages land (queue, team, request type, priority). Several mailboxes can
  run side by side — `servicedesk@`, `facilities@` — each routing differently.
  Passwords are encrypted at rest and reduced to a boolean on the way to the
  browser; an empty password field on save means "keep the stored one".
- **Notifications are templates, not code.** Six notifications ship with
  packaged NL and EN text. An administrator overrides any of them per mailbox
  and per language. Templates are plain text with `{{ placeholder }}` tokens
  rather than Blade: they are edited in the UI and stored in the database, so
  they must never be able to execute — see [D12](decisions.md). An unknown
  token renders as nothing rather than appearing in a customer's inbox.
- **Everyone is written to in their own language**, decided by the recipient's
  locale rather than by whoever triggered the mail.
- **Threading survives the customer.** Outgoing mail carries a generated
  Message-ID that embeds the ticket key (`ticktz.SUP-1042.17.9f2a@host`). A
  reply quotes it in `In-Reply-To`, so the answer lands on the right ticket
  even when the subject line has been rewritten entirely. Failing that: a
  `[KEY]` in the subject, then any earlier message in the same thread.
- **Inbound processing is idempotent.** A mail server *will* hand you the same
  message twice — a poll that timed out after processing but before flagging, a
  worker that died mid-job, a mailbox restored from backup. So the
  `inbound_messages` row is claimed first, inside a transaction, on a unique
  `(channel, message hash)` index; a second delivery loses the race and stops.
  The message is only flagged as read on the server *after* the database says
  it is handled. No ticket is ever created twice — see [D13](decisions.md).
- **Loops are cut at the door.** Bounce handlers, `no-reply` addresses,
  `Auto-Submitted` headers, out-of-office replies and bulk precedence never
  create a ticket. Everything Ticktz sends carries `Auto-Submitted:
  auto-generated` and `X-Auto-Response-Suppress`, so other systems extend the
  same courtesy.
- **Unknown senders become requesters**, with the audit entry attributed to the
  e-mail channel rather than to a person. Switch auto-provisioning off and mail
  from an address Ticktz does not know is dropped instead.
- **A reply reopens a resolved ticket**, bounded by `tickets.reopen_window_days`
  so a "thanks!" three months later does not revive a closed case.
- **There is no way to write an internal note by e-mail.** An inbound reply is
  always a public comment, and an internal note is never mailed to a requester.
- **Polling** runs every minute from the scheduler as a `ShouldBeUnique` job per
  mailbox, or on demand from the admin UI. The inbound log shows every message
  the poller has seen, including the ones it deliberately ignored and why.

The development stack runs [GreenMail](https://greenmail-mail-test.github.io/greenmail/)
rather than MailHog, because this phase needs both halves: SMTP to catch what
goes out, and IMAP to read it back in. Its web interface is on
<http://localhost:8025>; the demo seeder wires a mailbox to it.

## Phase 5 — SLA engine & escalations

A promise nobody measures is not a promise. This phase measures them.

- **The clock runs on a business calendar**, and that is the whole point. A
  four-hour target on a Friday afternoon expires on Monday morning, not on
  Saturday at eight. `BusinessCalendar` is the single place that knows what a
  working minute is: it walks the week day by day rather than doing arithmetic
  on a weekly total, because holidays, split shifts and daylight saving all
  break the arithmetic and none of them break the walk.
- **Two metrics**: first response (how long until a human answers) and
  resolution (how long until it is fixed). A policy claims a set of tickets;
  its goals set the targets, narrowed by priority and request type with the
  most specific match winning.
- **A target is copied onto the timer, not read through the goal.** An
  administrator who shortens a target on Tuesday has not thereby breached every
  ticket opened on Monday, and deleting a policy does not erase the promises it
  made — see [D15](decisions.md).
- **Waiting statuses stop the clock and pay the time back.** Five working hours
  waiting on the customer push the deadline five working hours out; time spent
  waiting on someone else is not time the desk owes.
- **A reopened ticket gets a fresh resolution clock**, because a promise to fix
  it again is a new promise. The original is kept exactly as it finished.
- **Breaches are found by sweeping**, because a breach is the absence of an
  event and nothing else notices it. A `ShouldBeUnique` job runs every minute,
  marks overdue clocks, and fires escalation thresholds. It is safe to run
  twice: a breach writes `breached_at` before announcing anything, and an
  escalation claims its threshold in `sla_events` first — so an escalation
  fires once however often the job runs ([D16](decisions.md)).
- **Escalations are deliberately few** — notify, raise the priority, hand the
  ticket to another team. A breach also emits a domain event, which is where
  the automation engine (phase 6) hangs anything more elaborate. This is not a
  rule engine and should not grow into one.
- **Agents see a countdown**, coloured by how much trouble the ticket is in.
  The remaining time is computed server-side against the calendar, because a
  browser has no idea when the desk is shut and a countdown that ticks through
  the night would be a lie.
- **Lists sort and filter on SLA without a join per row**: the tightest live
  clock is mirrored onto `tickets.sla_due_at` and `tickets.sla_breached`. The
  filter bar offers breached, due within the hour, on track, paused and not
  measured.
- **Every clock keeps a history.** `sla_events` records when it started, every
  pause and resume, every escalation and the moment it ran out — which is what
  a dispute about a breach is settled with.

A fresh instance ships a working policy: an office-hours calendar with Dutch
public holidays, targets per priority, and escalations on the two urgent tiers.
An SLA feature that starts empty is one nobody turns on.

## Phase 6 — Automation rules

When this happens, and these things are true, do that.

- **One trigger per rule**: a ticket created, changed, commented on,
  transitioned or assigned; an SLA target missed or escalated; or a schedule.
  A rule that fires on three different things is three rules wearing a
  trenchcoat, and its execution log is unreadable.
- **Conditions are a closed vocabulary.** The fields, operators and actions are
  constants in `AutomationRule`, and the admin form is built from them — so a
  rule the UI can express is a rule the engine can run, and a rule that
  silently does nothing is not a state this feature has. An unknown field fails
  closed: the worst case is a rule that does nothing, not one that reassigns
  every ticket on the desk.
- **Flat AND/OR, not a nested expression editor** ([D19](decisions.md)). A desk
  that genuinely needs parentheses is better served by two rules than by one
  nobody can explain.
- **Loops are the failure mode this feature has**, and it does not announce
  itself — it looks like a busy worker and an audit log filling up. A rule that
  sets a field emits `ticket.updated`, which is a trigger, which can run the
  rule again. So a rule runs at most once per ticket per cascade, which kills
  any ring however long, with a depth cap as a backstop. The cascade state
  travels *in the job*, because a queued job runs in a fresh process and
  anything held in memory would reset ([D20](decisions.md)).
- **Everything goes through `TicketService`**, so an automated assignment is
  audited, emits its events and respects the workflow exactly as a human's
  would. A transition the workflow forbids is reported, not forced — a rule
  that could override the workflow would make the workflow a suggestion.
- **Automation's changes are attributed to the rule by name**, not to "system".
  An operator reading the trail of a ticket that reassigned itself at three in
  the morning can see which rule did it.
- **Every evaluation is logged, including the skips**, with the condition that
  decided it — rendered with names and in the reader's language, not as
  "priority is 1". "Why did my rule not fire?" is unanswerable from a log of
  successes, and it is the question the log exists to answer
  ([D21](decisions.md)).
- **Rules can be tried out** against a real ticket from the admin screen, which
  evaluates the conditions and changes nothing.
- **Webhooks** are queued on their own queue with a backoff, https only, and
  signed with an HMAC of the exact body when the rule carries a secret.

Actions run on a worker, as the brief asks: a rule can call an endpoint on
somebody else's server, and nothing a customer does in the portal should wait
on that.

### Not yet wired up

The sidebar only shows destinations that have routes today: Dashboard, Tickets,
Queues and Administration. The knowledge base, assets, approvals and reporting
appear as their phases land — a menu item without a route is worse than an
absent one. The permission catalogue already covers them, so roles can be
configured ahead of the features arriving.
