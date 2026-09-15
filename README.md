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
generates an application key and runs the migrations. A development mail sink
is available at <http://localhost:8025>.

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
| [Self-hosting guide](docs/self-hosting.md) | Installing, upgrading, backups, scaling, TLS |
| [Architecture](docs/architecture.md) | How the pieces fit together and why |
| [Configuration](docs/configuration.md) | Every environment variable |
| [Data model](docs/data-model.md) | Tables and relationships |
| [Design decisions](docs/decisions.md) | Defaults chosen where the brief left room |
| [Screenshots](docs/screenshots.md) | What the product looks like |
| [Contributing](CONTRIBUTING.md) | Local development without Docker, coding standards |

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
