<div align="center">

# Ticktz

**A self-hosted service desk.** An open-source alternative to Jira Service
Management for teams that would rather keep their data — and their
configuration — on their own infrastructure.

</div>

---

Ticktz is a Laravel + Inertia/React application that ships as a single Docker
Compose stack: tickets and queues, a customer portal, SLAs with real business
calendars, an automation engine, a knowledge base, approvals, an asset
register, reporting, and a scoped REST API. Everything runs against your own
MySQL. There is no telemetry, no analytics SDK and no third-party request from
the browser.

## Status

Ticktz is being built in phases. See [`docs/roadmap.md`](docs/roadmap.md) for
what is shipped and what is next, and [`docs/PROMPT.md`](docs/PROMPT.md) for the
original build brief.

## Quick start

```bash
git clone https://github.com/rorymeijer/ticktz.git
cd ticktz
cp .env.example .env
docker compose up -d --build --wait
```

The stack comes up on <http://localhost:8080>. The first boot waits for MySQL,
generates an application key, runs the migrations and seeds a demo desk — roles,
queues, SLAs and sample tickets — so there is something to sign in to. It only
ever seeds an instance with no users in it, so a restart never writes over a
desk you have started using. A development mail server (GreenMail: SMTP, IMAP
and a web interface) is available at <http://localhost:8025> — see
[docs/email.md](docs/email.md).

`--wait` is worth the few seconds: without it `up` returns as soon as the
containers have *started*, while the first boot is still migrating, and a
command run against the database at that moment finds tables that are not there
yet.

### Running it for real

The development stack above is for looking at. A production instance is one
command, and no editor:

```bash
./scripts/install.sh
```

It writes the two passwords the bundled MySQL needs — nothing else — starts the
production stack, and leaves the rest to the setup wizard in the browser. See
[Self-hosting](docs/self-hosting.md#running-it-for-real).

Health check:

```bash
curl -s http://localhost:8080/health | jq
```

## Documentation

| Document | What it covers |
| --- | --- |
| [Self-hosting](docs/self-hosting.md) | The setup wizard, upgrading, backups, scaling, troubleshooting |
| [Configuration](docs/configuration.md) | Every setting, and the ones where the value matters |
| [Architecture](docs/architecture.md) | How it is put together, and why |
| [Roadmap](docs/roadmap.md) | What each phase shipped |
| [E-mail](docs/email.md) | Mailboxes, notification templates, email-to-ticket |
| [Service levels](docs/sla.md) | Calendars, policies, targets, escalations |
| [Automation](docs/automation.md) | Rules, conditions, actions, webhooks |
| [Knowledge base](docs/kb.md) | Articles, versions, visibility, search, suggestions |
| [Approvals](docs/approvals.md) | Workflows, steps, the transition gate, deciding by e-mail |
| [Assets](docs/assets.md) | The register, relations, linking to tickets, CSV import |
| [Reporting](docs/reporting.md) | The metrics pipeline, the four reports, export |
| [API & webhooks](docs/api.md) | Scoped tokens, the endpoints, outgoing webhooks |
| [OpenAPI spec](docs/openapi.yaml) | The machine-readable contract |
| [Releasing](docs/releasing.md) | Cutting a version, and what to set up in GitHub for it |
| [Design decisions](docs/decisions.md) | Defaults chosen where the brief left room |
| [Screenshots](docs/screenshots.md) | What the product looks like |
| [Contributing](CONTRIBUTING.md) | Local development without Docker, coding standards |
| [Changelog](CHANGELOG.md) | What changed per release |
| [Build brief](docs/PROMPT.md) | The original specification, verbatim |

## The stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Frontend | Inertia.js + React 18 + TypeScript, Vite, Tailwind |
| Database | MySQL 8 (utf8mb4) |
| Cache / queue / sessions | Redis |
| Background work | A dedicated worker container: `queue:work` + `schedule:work` |
| Web server | nginx in front of php-fpm |
| Auth (UI) | Laravel sessions; local accounts and/or LDAP / Active Directory |
| Auth (API) | Laravel Sanctum, scoped personal access tokens |

## Licence

[MIT](LICENSE).
