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
docker compose up -d --build
```

The stack comes up on <http://localhost:8080>. The first boot waits for MySQL,
generates an application key and runs the migrations. A development mail server
(GreenMail: SMTP, IMAP and a web interface) is available at
<http://localhost:8025> — see [docs/email.md](docs/email.md).

```bash
# seed a demo instance (roles, queues, SLAs, sample tickets)
docker compose exec app php artisan ticktz:demo
```

Health check:

```bash
curl -s http://localhost:8080/health | jq
```

## Documentation

| Document | What it covers |
| --- | --- |
| [Roadmap](docs/roadmap.md) | What each phase shipped, and what is next |
| [E-mail](docs/email.md) | Mailboxes, notification templates, email-to-ticket |
| [Service levels](docs/sla.md) | Calendars, policies, targets, escalations |
| [Automation](docs/automation.md) | Rules, conditions, actions, webhooks |
| [Knowledge base](docs/kb.md) | Articles, versions, visibility, search, suggestions |
| [Approvals](docs/approvals.md) | Workflows, steps, the transition gate, deciding by e-mail |
| [Assets](docs/assets.md) | The register, relations, linking to tickets, CSV import |
| [Design decisions](docs/decisions.md) | Defaults chosen where the brief left room |
| [Screenshots](docs/screenshots.md) | What the product looks like |
| [Contributing](CONTRIBUTING.md) | Local development without Docker, coding standards |
| [Build brief](docs/PROMPT.md) | The original specification, verbatim |

The consolidated self-hosting guide, architecture overview, configuration
reference and data-model documentation are written in Phase 12; until then each
phase documents its own configuration on the pages above.

## The stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 11, PHP 8.3 (8.4 supported) |
| Frontend | Inertia.js + React 18 + TypeScript, Vite, Tailwind |
| Database | MySQL 8 (utf8mb4) |
| Cache / queue / sessions | Redis |
| Background work | A dedicated worker container: `queue:work` + `schedule:work` |
| Web server | nginx in front of php-fpm |
| Auth (UI) | Laravel sessions; local accounts and/or LDAP / Active Directory |
| Auth (API) | Laravel Sanctum, scoped personal access tokens |

## Licence

[MIT](LICENSE).
