# Changelog

Notable changes per release. Dates are the tag date; the phase numbers refer to
[`docs/roadmap.md`](docs/roadmap.md), which explains what each one set out to
build and why.

This project follows [semantic versioning](https://semver.org). For a
self-hosted application that mostly means: a major version may require a manual
step during an upgrade, a minor version never does, and a patch never changes
the database.

## 1.0.0

The first release. A complete service desk: tickets, a customer portal, e-mail
in and out, service levels, automation, a knowledge base, approvals, an asset
register, reporting and a public API.

**Tickets and the desk** — workflows with configurable statuses, priorities and
transitions; queues as saved views; teams, organisations and per-team
visibility; internal notes that never reach the customer; attachments streamed
through a policy rather than served off a public disk; a full audit trail on
every mutating action.

**Customer portal** — request types with custom fields, the requester's own
request history, and a help centre that suggests articles while a request is
being written.

**E-mail** — SMTP out with templates per locale, IMAP in with idempotent
processing so a re-polled mailbox cannot create the same ticket twice.

**Service levels** — business calendars with holidays, policies matched in
order, first-response and resolution targets, pause-on-pending, and escalation.

**Automation** — event- and schedule-driven rules with a loop guard, an
execution log, and a preview that shows what a rule would have done.

**Knowledge base** — versioned articles, public and internal visibility,
full-text search on MySQL with a LIKE fallback, and suggestions on the portal
form and in the agent console.

**Approvals** — sequential and parallel steps, approver resolution by user,
team, role, manager or field, a transition gate enforced in the service so the
API cannot walk around it, and decision-by-e-mail through single-use expiring
tokens.

**Assets** — a register with types and custom fields, relations stored once and
read in both directions, linking to tickets, and a CSV import that tolerates
real spreadsheets.

**Reporting** — a daily rollup rather than a live aggregate, four reports, CSV
export of both the figures and the tickets behind them, and hand-drawn charts
against a validated palette.

**Public API** — Sanctum tokens scoped so a token is the intersection of what
it was issued for and what its owner may do today, outgoing webhooks with
HMAC-signed payloads and a delivery log, and an OpenAPI spec tested against the
real route table.

**Multilingual** — Dutch and English throughout, per user, including outgoing
e-mail. A test fails the build when the two locales drift apart, and another
fails it when a `t()` key has nothing behind it.

**Accessible** — zero axe-core violations across every shell, checked in CI
rather than once by hand.

### Notes for operators

- PHP 8.3 or 8.4, MySQL 8, Redis.
- `docker compose up` on a fresh clone gives a working, populated demo. Do not
  point that compose file at real data: see
  [docs/self-hosting.md](docs/self-hosting.md) for the production one.
- Take a backup with `scripts/backup.sh` before every upgrade. The archive
  holds the database, storage and `.env` together on purpose — `APP_KEY`
  decrypts the mailbox and directory passwords stored in the database, and
  restoring one without the other gives an instance that looks fine and cannot
  read a mailbox.

### Deviation from the original brief

The brief specified Laravel 11. It ships on Laravel 12, because three
advisories against `laravel/framework` 11.x — including a high-severity CRLF
injection in the default `email` validation rule — are fixed only in 12.61.1
and later. The reasoning is recorded as D50 in
[`docs/decisions.md`](docs/decisions.md).
