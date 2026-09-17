# Changelog

Notable changes per release. Dates are the tag date; the phase numbers refer to
[`docs/roadmap.md`](docs/roadmap.md), which explains what each one set out to
build and why.

This project follows [semantic versioning](https://semver.org). For a
self-hosted application that mostly means: a major version may require a manual
step during an upgrade, a minor version never does, and a patch never changes
the database.

## 1.0.3

**Inbound e-mail keeps its formatting.** A customer who sends a numbered list
now arrives with a numbered list rather than four lines that start with digits.
The quoted thread is still cut off the bottom — that is what makes a mail
conversation readable — and it is cut using the markers Gmail, Outlook, Apple
Mail, Thunderbird, Proton, Yahoo and Zoho each use, plus the bare "On … wrote:"
line for clients that use nothing else. A cut that would leave nothing behind
is refused, so a bare forward arrives whole.

Mail without an HTML part is unchanged, and so is mail whose HTML turns out to
hold nothing but a quote.

**Signatures stop carrying tracking pixels.** Inbound mail goes through the
same sanitiser as everything else, and a message may only show an image this
instance serves — so the remote `<img>` in a customer's signature no longer
reports every agent who opens the ticket to whoever put it there.

Images a customer attached inline with `cid:` are still listed as attachments
rather than shown in the body.

## 1.0.2

**Paste a screenshot.** Tickets, replies and knowledge base articles now take
images: paste, drop or pick one and it goes straight into the text. They are
stored outside the web root and read through a policy that follows whatever
they were pasted into, so a screenshot on an internal note stays unreadable to
the requester who can read everything around it. They travel with outgoing
e-mail as part of the message rather than as a link, and each one has a
description field, because a screenshot with no alt text is nothing to a screen
reader.

A message may only show an image this instance is serving; a remote one is a
tracking pixel. Articles keep the wider rule.

`ticktz:prune-images` runs nightly and deletes images that were pasted into a
draft nobody ever saved.

**Three fixes to a development stack that had never been started.** `docker
compose up` now works. The database container was passed a flag that only
exists in MySQL 8.4 while pinned to 8.0, and MySQL aborts rather than ignoring
a setting it does not recognise. Its healthcheck then reported the server
healthy even when the application's own user and database had never been
created, so the failure surfaced one layer later as an error about hosts. And
the page came up white in every browser but Chrome, because Vite was telling
the browser to fetch its JavaScript from `0.0.0.0`.

None of these affect a production install. All three are recorded, with the
symptom to search for, in the troubleshooting section.

## 1.0.1

**Rich text everywhere.** Every field somebody writes sentences into now has a
formatting toolbar: ticket descriptions and replies, knowledge base articles,
asset notes, request type and approval instructions, approval reasons and
decisions, e-mail signatures and multiline custom fields. Bold, italic, lists,
quotes, links and tables; headings, images and code blocks in articles.
Everything is sanitised against an allowlist on the way into the database, and
each field keeps a plain-text copy beside it for search, e-mail and the API.

Outgoing e-mail now really has two parts: formatted HTML, and a plain-text
version rendered from the same template rather than the markup with its tags
pulled out.

**Upgrading.** The migration converts everything already stored — blank lines
become paragraphs, and anything resembling a tag is escaped, so a ticket that
said `<3` still says `<3`. It also rebuilds two FULLTEXT indexes, which takes a
few minutes on a large desk. Take a backup first.

**The database moves to MySQL 8.4**, the current LTS; 8.0 left support in April
2026. The container upgrades its data directory on first boot and that is
one-way, so this is the other reason to take the backup. The development stack
did not start at all before this, on any version: it passed `mysql:8.0` a flag
that only exists in 8.4, and MySQL aborts rather than ignoring a setting it
does not recognise.

**For API clients.** `description` and `body` now return markup. The same
content without it is in `description_text` and `body_text` — switch the field
you read and nothing else changes. Writes accept either: plain text is wrapped
in paragraphs, so a client written against 1.0.0 keeps working untouched.

**Also fixed.** Help text under a form field was never announced to screen
readers — `aria-describedby` pointed at an id nothing carried. The button that
links one ticket to another had no accessible name at all. Directory sign-in
failed outright on servers whose LDAP returns the distinguished name as an
array, and a directory group removed in AD never removed the role it granted.

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

**Setup wizard** — a fresh instance sends every URL to `/install` and walks
through requirements, database, application, administrator and optional
e-mail. The one question with weight is where the data lives: the MySQL that
ships in the compose file, or a server you already run. Nothing is written
until the last step, and the wizard closes behind itself once the instance is
up. `php artisan ticktz:install` does the same thing without a browser.

**Multilingual** — Dutch and English throughout, per user, including outgoing
e-mail. A test fails the build when the two locales drift apart, and another
fails it when a `t()` key has nothing behind it.

**Accessible** — zero axe-core violations across every shell, checked in CI
rather than once by hand.

### Notes for operators

- PHP 8.4, MySQL 8, Redis.
- First boot opens the setup wizard. Upgrading an existing instance does not:
  a migrated database with users in it counts as installed.
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
