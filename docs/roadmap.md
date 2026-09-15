# Roadmap

Ticktz is built in phases, each ending in a runnable, tested state. This page
tracks what is shipped. The phase definitions come from the original brief in
[`PROMPT.md`](PROMPT.md).

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | Foundation, Docker stack, i18n plumbing, CI | ✅ Shipped |
| 1 | Auth, users, roles & permissions, teams, organisations, LDAP, audit log | ✅ Shipped |
| 2 | Ticket core & agent console | ✅ Shipped |
| 3 | Customer portal & request types | ⏳ Next |
| 4 | E-mail (SMTP out, IMAP in) | ⏳ Planned |
| 5 | SLA engine & escalations | ⏳ Planned |
| 6 | Automation rules | ⏳ Planned |
| 7 | Knowledge base | ⏳ Planned |
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

### Not yet wired up

The sidebar only shows destinations that have routes today: Dashboard and
Administration. Tickets, queues, the knowledge base, assets, approvals and
reporting appear as their phases land — a menu item without a route is worse
than an absent one. The permission catalogue already covers them, so roles can
be configured ahead of the features arriving.
