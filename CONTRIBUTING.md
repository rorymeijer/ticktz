# Contributing to Ticktz

Thanks for helping out. This document covers running Ticktz without Docker,
the conventions the codebase follows, and what CI will check.

## Local development

Requirements: PHP 8.4+ with `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `zip`;
Composer 2; Node 22.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# the quickest way to get running: SQLite + file cache, no services needed
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
touch database/database.sqlite
php artisan migrate --seed

npm run dev            # terminal 1 — Vite
php artisan serve      # terminal 2 — the app on http://127.0.0.1:8000
php artisan queue:work # terminal 3 — background jobs
```

Against the Docker stack instead:

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
docker compose logs -f worker
```

## Checks

Run these before opening a pull request — CI runs exactly the same commands.

```bash
./vendor/bin/pint            # PHP formatting (Laravel preset)
./vendor/bin/pest            # backend tests
npm run typecheck            # TypeScript
npm test                     # Vitest
npm run build                # production bundle
```

## Conventions

**Language.** Code, comments, commit messages and repository documentation are
in English. User-facing strings are never written in a component — they go into
`lang/en/*.php` *and* `lang/nl/*.php` and are read through `__()` in PHP or
`t()` in React. `tests/Feature/LocalizationTest.php` fails the build if the two
locales drift apart.

**Authorisation.** Every route is authorised server-side through a policy or
gate. Hiding a button in React is a convenience, never the control. Any new
endpoint needs a matching test that asserts a user without the permission gets
a 403.

**Auditing.** Mutating actions write to `audit_log` through
`App\Services\AuditLogger`. If you add a state change, log it.

**Queries.** List endpoints paginate, always. Add the index in the same
migration as the column you filter on.

**Migrations.** One migration per change, never edited after it has shipped.
Avoid MySQL-only DDL unless it is behind a driver check — the test suite runs
on SQLite.

**Frontend.** Components live in `resources/js/Components/UI` (generic) or
`resources/js/Components/<Domain>` (feature-specific). Pages are thin: fetch
props, compose components, no business logic.

## Commit messages

Short imperative subject, optional body explaining *why*. Reference the phase
when a change belongs to one, e.g. `Phase 5: pause SLA timers on waiting states`.
