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
| 7 | Knowledge base | ✅ Shipped |
| 8 | Approvals | ✅ Shipped |
| 9 | Assets / CMDB | ✅ Shipped |
| 10 | Reporting & dashboards | ✅ Shipped |
| 11 | Public REST API & webhooks (Sanctum) | ⏳ Next |
| 12 | i18n completion, accessibility, docs & release | ⏳ Planned |

## Phase 0 — Foundation & Docker

- Laravel (11 at the time, 12 since — see [D50](decisions.md)) + Inertia +
  React 18 + TypeScript, built with Vite.
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

## Phase 7 — Knowledge base

The answers the desk gives most often, written down once. Full notes in
[`kb.md`](kb.md).

- **Two visibility scopes, not one scope with a flag.** An internal article on
  the portal is the failure this feature must not have, so `visibleOnPortal()`
  and `visibleToAgent()` are written to be read side by side. The agent scope
  *widens* by permission: an agent holding neither `kb.view.internal` nor
  `kb.manage` sees exactly what a requester sees ([D22](decisions.md)).
- **A public article inside an internal category stays hidden.** That is a
  mistake somebody will make, and the portal is the wrong place to find out.
- **A direct URL to an internal article answers 404, not 403** — the existence
  of an internal article is itself information.
- **Bodies are sanitised on write, once, in `ArticleService`** — allowlist only,
  built from an empty config rather than Symfony's default-allow shortcut.
  Nothing writes to `kb_articles` outside that service, so an import and a
  seeder get the same treatment as the editor, and the browser can be handed
  the stored HTML as markup ([D23](decisions.md)).
- **Restoring re-sanitises** rather than trusting what was stored: a body
  written before the allowlist last changed is not necessarily safe under the
  allowlist as it stands now.
- **Every edit snapshots the previous version first**, in the same transaction,
  so the history can never be half-written and restoring is "put back what was
  there" rather than a guess. Restoring is itself undoable. Changing only a
  label does not spend a version ([D24](decisions.md)).
- **Search reads `body_text`**, the flattened body, so a query for "href" finds
  articles that discuss links rather than every article that contains one.
  MySQL uses FULLTEXT; SQLite falls back to LIKE and tokenises the way the
  index does — a whole ticket subject matched verbatim finds nothing
  ([D25](decisions.md)).
- **Suggestions before anybody searches**: on the portal request form as the
  requester types, and on the ticket page seeded from the ticket's own subject.
  The cheapest ticket is the one nobody had to file.
- **Articles link to the tickets they answered**, which turns "what do we get
  asked most" from a guess into a query. The link is looked up through the
  agent's own scope, so guessing an id is not a way past the permission.
- **A read does not touch `updated_at`.** An article does not become newer
  because somebody read it, or the knowledge base reorders itself by who
  happened to click what.

## Phase 8 — Approvals

Somebody with the authority to say yes has to say it. Full notes in
[`approvals.md`](approvals.md).

- **Single, parallel and sequential are one mechanism, not three.** A workflow
  is an ordered list of steps; the approvers inside a step are asked at once
  and its `mode` says whether one of them is enough; more than one step makes
  it sequential ([D27](decisions.md)).
- **Who was asked is a fact, not a query.** Approvers are resolved when a step
  opens and written into decision rows, so a team gaining a member tomorrow
  does not change who was asked today, and an `all` step cannot grow a new
  blocker halfway through ([D28](decisions.md)).
- **One refusal ends the whole approval**, whatever the mode. An approval a
  majority can override is not an approval.
- **The requester is never asked to approve their own request**, and a step
  that resolves to nobody is skipped and recorded rather than left blocking
  forever. A ticket held by an approval addressed to nobody is worse than one
  that was never held.
- **The gate lives in `TicketService::transition()`**, so an agent's click, an
  automation rule and an inbound e-mail all meet the same wall — and it fails
  closed: a ticket with no approval has not been approved ([D29](decisions.md)).
- **Only the person who was asked may answer.** Not a permission, and not
  something a super-admin can do either: `Gate::before` explicitly stands down
  for this one check, because an approval an administrator could have given is
  worth nothing as evidence ([D30](decisions.md)).
- **Approvers can answer from their inbox** without signing in. The link is a
  GET that decides nothing and only renders a page; the decision is a POST from
  it. The token is hashed at rest, single-use, expiring, minted inside the mail
  job so it never sits in a queue payload — and switchable off entirely
  ([D31](decisions.md)).
- **The inbox is at `/approvals`**, outside both `/agent` and `/portal`,
  because an approver is very often a budget holder who is not an agent.
- **A deadline reports; it never decides.** Overdue approvals are chased
  hourly and left open ([D32](decisions.md)).

Also in this phase: `users.manager_id`, so the commonest approval of all —
"my manager has to sign this off" — has somewhere to read the answer from; and
a per-transition conditions list in the workflow editor, which is where the
gate is configured and which incidentally gave `requires_comment` and
`requires_assignee` a UI for the first time.

## Phase 9 — Assets / CMDB

The things the desk is asked about. Full notes in [`assets.md`](assets.md).

- **The tag is the human key.** `asset_tag` is the sticker on the box: unique,
  indexed, searchable, and what an import matches on. The next number is
  derived from the highest existing tag rather than a counter, because assets
  arrive by import carrying tags somebody else allocated ([D33](decisions.md)).
- **Custom attributes reuse `custom_fields`.** An asset type declares which
  fields it wants, exactly as a request type declares its form — one field
  editor, one validation path.
- **Relations are stored once, in one direction**, and the inverse is derived
  when rendering. A row per direction would let the two halves of "installed
  on" disagree, and in a CMDB that is the bug that makes people stop trusting
  the whole thing ([D34](decisions.md)).
- **Assets link to tickets**, which is the half that earns its keep: a register
  nothing points at answers "what do we own"; one attached to tickets answers
  "what keeps breaking". The picker is seeded from the requester's own
  equipment.
- **Search matches tags and serials with LIKE on both drivers.** A FULLTEXT
  index tokenises on word boundaries, so `LAP-0042` is two words and a search
  for `0042` — what somebody types off a worn sticker — finds nothing
  ([D35](decisions.md)).
- **Importing is two steps, always.** The upload reports what would happen;
  only an explicit second request writes. It matches on `asset_tag` so a
  re-import updates rather than doubles the estate, leaves columns the file
  does not carry alone, and reports per-row errors rather than rejecting the
  file ([D36](decisions.md)).
- **It reads what spreadsheets actually produce**: semicolons as well as
  commas, a UTF-8 BOM, `31-12-2026` as well as `2026-12-31`, `€ 1.299,00` as
  well as `1299.00`.
- **The portal payload is built by naming what may be shown**, not by removing
  keys from the agent one — a payload built by subtraction leaks the next field
  somebody adds ([D37](decisions.md)).
- **The register sits outside the `tickets.view` gate.** In plenty of
  organisations the CMDB is kept by a procurement team who never touch a
  ticket.

## Phase 10 — Reporting & dashboards

Four questions, one period, and a CSV of whatever is behind them. Full notes in
[`reporting.md`](reporting.md).

- **A daily rollup, not a live aggregate.** Everything the dashboards draw
  comes out of `report_daily_metrics`, because a grouped scan of the ticket
  table behind a screen people leave open all day does not survive contact with
  a real desk ([D38](decisions.md)).
- **A day is recomputed, never incremented**, and the rebuild replaces the day
  rather than upserting into it — so a figure that no longer has anything
  behind it goes down.
- **SLA outcomes bucket on the day the clock finished**, so a month's
  compliance figure stops moving once the month has ([D39](decisions.md)).
- **A breach is `breached_at`, not `status = 'breached'`.** The engine stamps
  that column the moment a target passes and leaves the clock running, so
  counting only the finished ones reported a desk with visibly late tickets at
  100% ([D40](decisions.md)).
- **Averages are `sum / count` over the range**, never the mean of the daily
  averages — which weights a quiet Sunday like a busy Monday.
- **Two exports**: the figures, and the tickets behind them. Streamed, chunked
  by id, and BOM-prefixed so Excel does not mangle every Dutch name
  ([D41](decisions.md)).
- **A saved report stores its filters, not its figures.** One that cached its
  numbers would be a screenshot with a date on it.
- **Hand-drawn SVG charts** against a validated palette: never two y-axes,
  status colours only where the colour means status, a legend whenever there
  are two series, and a stat tile wherever the answer is one number
  ([D42](decisions.md)).

The dashboard became a real screen in this phase too — every figure on it links
to a list somebody can act on.

## Phase 11 — Public REST API & webhooks

A documented way in for everything that surrounds a desk, and a way out for the
things that happen in it. Full notes in [`api.md`](api.md); the contract is
[`openapi.yaml`](openapi.yaml).

- **A token is the intersection of its scopes and its owner's permissions.**
  Every scope names the permissions behind it and both are checked on every
  request, so a token can never be an escalation — and removing somebody's role
  narrows every token they ever minted, including the forgotten ones
  ([D43](decisions.md)).
- **One error shape for the whole API**, with stable string codes we own rather
  than exception class names. A record you may not see answers 404, never 403:
  telling them apart makes the ticket key space enumerable
  ([D44](decisions.md)).
- **The API writes through the services**, so a ticket filed by a script gets
  the same numbering, watchers, SLA clocks, audit trail and approval gate as one
  filed by hand. `source` is stamped `api` and the opening status comes from the
  workflow — neither is the caller's to choose ([D45](decisions.md)).
- **Rate limiting is per token, not per account.** Two integrations owned by one
  service account are two callers, and an instance-wide limit cannot tell them
  apart ([D46](decisions.md)).
- **A delivery row is written before the request, not after**, so an event whose
  queue never ran it still leaves a trace. One job per subscription, a growing
  backoff, and a subscription that switches itself off after twenty consecutive
  failures rather than burning queue slots on a host that is gone
  ([D47](decisions.md)).
- **The webhook payload names what may leave the building.** Internal note
  bodies never go out, `ticket.updated` carries field names rather than values,
  and statuses are sent as slugs so a rename does not break a receiver. The
  HMAC covers the exact bytes sent ([D48](decisions.md)).
- **Two things are now checked mechanically**: the OpenAPI spec against the real
  route table, and every literal `t('…')` key against the translation files.
  Both catch failures that are otherwise silent — the second turned up two
  labels that had been rendering raw keys since phases 5 and 6
  ([D49](decisions.md)).

## Phase 12 — i18n, polish, docs & release

The round that turns a working application into something somebody else can
run.

- **The accessibility pass was run, not asserted.** axe-core over eighteen
  pages, signed in as the role that works in each, with a budget of zero. It
  found three rules failing — a critical unnamed account menu on sixteen pages,
  contrast, and invalid `<dl>` markup — none of which were visible to anyone
  looking at the screens ([D51](decisions.md)).
- **Colour is now derived where it is not ours to choose.** Label chips painted
  an administrator's colour on a 10% tint of itself, as little as 2.85:1. No
  palette fixes that, because the palette belongs to the operator — so the ink
  is computed to clear 4.5:1 against the tint it will actually sit on
  ([D52](decisions.md)).
- **Two silent failure modes now fail the build**: a translation key with
  nothing behind it, and an OpenAPI spec that has drifted from the route table.
  The first found two labels that had been rendering raw keys since phases 5
  and 6 ([D49](decisions.md)).
- **Backup and restore are scripts, not instructions.** The archive carries the
  database, storage and `.env` together, because `APP_KEY` decrypts the mailbox
  passwords inside the database — and restoring one without the other gives an
  instance that looks healthy and cannot read a mailbox ([D53](decisions.md)).
- **CI gained the gates that catch what review does not**: `composer audit`,
  `npm audit`, and the accessibility run against a booted demo instance. The
  advisories that forced the Laravel 12 upgrade sat in the lock file for weeks
  because nothing was watching ([D50](decisions.md)).
- **Releases are a tag.** The workflow verifies, refuses to publish when the
  tag and the version in the code disagree, builds for amd64 and arm64, and
  drafts the notes rather than publishing a commit list.
- **The documentation a self-hoster actually needs**: installing, TLS, first
  run, backups, upgrading, scaling, and a troubleshooting section organised by
  symptom rather than by subsystem.

### Not yet wired up

The sidebar only shows destinations that have routes today: Dashboard, Tickets,
Queues, Approvals, Assets, the knowledge base, Reports and Administration. API
tokens live in the user menu and webhooks under Administration, because both
belong to the person or the instance rather than to the daily work. The
permission catalogue already covers what is still to come, so roles can be
configured ahead of the features arriving.
