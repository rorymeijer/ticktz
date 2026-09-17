# Architecture

How Ticktz is put together, and why. The per-feature pages explain what each
part does; this one is about the shape they share.

## The stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Frontend | Inertia.js + React 18 + TypeScript, Vite, Tailwind |
| Database | MySQL 8, `utf8mb4` |
| Cache, sessions, queues | Redis |
| API auth | Laravel Sanctum, scoped personal access tokens |
| Directory | LdapRecord (LDAP / Active Directory) |

Laravel does the heavy lifting rather than being routed around: Eloquent,
migrations and seeders, queues and the scheduler, events and listeners,
policies and gates, Form Requests, Mailables, and Laravel's own localisation.
There is no service layer reimplementing what the framework already does.

The frontend is served by Laravel through Inertia, so there is no separate
SPA build to deploy, no second auth flow and no API the UI depends on. The
public API in [`api.md`](api.md) exists for *other* systems, and the UI does
not use it — which is what lets the API be versioned without holding the
interface still.

## The shape of a request

```
                                    ┌─────────────┐
  browser ──── session cookie ────▶ │             │
                                    │   Laravel   │──▶ MySQL
  script ───── Bearer token ──────▶ │             │──▶ Redis  (cache, sessions, queues)
                                    └──────┬──────┘
                                           │ domain event
                                           ▼
                                    ┌─────────────┐
                                    │   Listeners │──▶ queued jobs ──▶ worker
                                    └─────────────┘
```

Two front doors, deliberately kept apart. The interface uses the session guard
with CSRF protection; the API is token-only, with Sanctum's stateful domains
list left empty so a cookie can never authenticate an API request. The API
therefore has no CSRF surface at all, and the two cannot be confused for one
another.

## Domain events are the seam

The one structural decision everything else follows from. A service writes its
change and dispatches an event; the subsystems that care listen.

```
TicketService::create()
      │
      ├─▶ TicketCreated ──┬─▶ SendTicketNotifications   (phase 4)
      │                   ├─▶ MaintainSlaTimers         (phase 5)
      │                   ├─▶ QueueAutomationRules      (phase 6)
      │                   ├─▶ OpenRequestTypeApproval   (phase 8)
      │                   └─▶ PublishDomainEvent        (phase 11, webhooks)
      │
      └─▶ TicketAssigned ──▶ ...
```

Nothing in `TicketService` knows that SLAs, automation or webhooks exist. That
is why a ticket filed by an agent, by e-mail, by an automation rule and by the
API all behave identically — there is one path, and the integrations hang off
it rather than being repeated at each call site.

It is also why each phase could be added without touching the ones before it.

## Services own the invariants

Controllers validate and authorise. Services own everything that must be true
regardless of who asked:

- `TicketService` — numbering, lifecycle timestamps, watcher bookkeeping, the
  audit trail, the workflow rules and the approval gate. **Nothing writes to
  `tickets` outside it.**
- `SlaEngine` / `SlaEscalator` — clocks, pauses, breaches.
- `ApprovalService` — steps, decisions, and the cached `approval_state` the
  transition gate reads.
- `AssetService`, `ArticleService`, `MetricsCollector`, `WebhookDispatcher` —
  the same pattern in their own areas.

The approval gate is the clearest illustration. It lives in
`TicketService::transition()`, not in a controller, so an agent's click, an
inbound e-mail, an automation rule and an API call all meet the same wall. A
gate the API can walk around is not a gate.

## Authorisation

RBAC is enforced on every endpoint, and the vocabulary is defined in code
(`App\Support\PermissionCatalog`) rather than created through the UI — so a
policy references a constant and a typo becomes a failing test instead of a
silently open endpoint.

Visibility is written **once**, as a query scope, and the policy for a single
record mirrors it exactly:

```php
Ticket::query()->visibleTo($user);   // lists
$user->can('view', $ticket);         // one record
```

They sit next to each other in the source on purpose. A difference between the
two is a data leak, and there is a test that walks every ticket and asserts
the scope and the policy agree.

API scopes narrow this further rather than bypassing it: a token's abilities
are intersected with its owner's live permissions, so it can never exceed the
person it belongs to.

## Payloads name what may be shown

Every payload that leaves the application — Inertia props, API resources,
webhook bodies — is built by naming its fields. Never by taking a model and
removing what should not be there.

The difference matters a year from now: a column added to `users` must not
start appearing in a webhook because nobody remembered to exclude it. Two tests
assert this directly, checking that a token hash and a webhook signing secret
never reach the browser.

## Background work

Five queues, so one slow thing cannot starve another:

| Queue | Carries | Starving it would mean |
| --- | --- | --- |
| `high` | SLA breach detection, escalation | breaches noticed late |
| `default` | automation rules, approvals | rules not firing |
| `mail` | outgoing notifications | silence |
| `webhooks` | outgoing deliveries | integrations out of date |
| `low` | housekeeping, log pruning | disk |

A webhook retrying into a dead endpoint backs off on its own queue and cannot
delay an SLA escalation. The scheduler drives mailbox polling, the SLA sweep,
approval reminders, the metrics rebuild and log pruning.

Jobs carry **ids, not models**. A serialised model is a snapshot of a row that
may have changed by the time a worker picks it up, and re-reading costs one
primary-key lookup.

## Idempotency

Two places where "exactly once" has to hold across retries, and both use the
same trick — claim before you act, with a conditional update on the row's own
state:

- **Inbound mail.** A re-polled mailbox must not create the same ticket twice,
  so the message id is claimed before the ticket is created.
- **Approval decisions.** A double-clicked approve link must decide once.

Webhook deliveries are deliberately *not* idempotent on our side — at-least-once
is what a retrying sender can promise. Each payload carries a stable event id,
reused across retries and across endpoints, so a receiver can deduplicate.

## The database

68 tables, every list query paginated and indexed. Three conventions worth
knowing:

- **Derived inverses are stored once.** A ticket link or an asset relation is
  one row, read from both sides by inverting the label. Two rows per
  relationship lets the halves disagree, which in a CMDB is the bug that makes
  people stop trusting the whole thing.
- **Reporting reads a rollup.** `report_daily_metrics` is recomputed per day,
  never incremented, so a figure with nothing behind it goes down. Aggregating
  the ticket table behind a screen people leave open all day does not survive a
  real desk.
- **Soft deletes for anything referenced by history.** Accounts are never hard
  deleted from the UI, because tickets, comments and audit entries keep
  pointing at them.

## Scaling out

The app is stateless: sessions and cache in Redis, so you can run several
containers behind a load balancer and lose one without logging anybody out. The
API is stateless by construction. Workers scale separately from the web tier.

The database is the part that does not scale sideways, which is why pagination,
indexing and the reporting rollup are treated as correctness rather than
optimisation.

See [`self-hosting.md`](self-hosting.md#scaling) for the operational detail.

## What is deliberately absent

- **No analytics SDK, no telemetry, no third-party tracker.** Nothing phones
  home. The only outbound requests are the ones an operator configures: SMTP,
  IMAP, LDAP and their own webhooks.
- **No component library.** Tailwind and a small set of headless primitives.
  MUI or AntD would be a large dependency to dress a form.
- **No webfonts.** A self-hosted tool that fetches a font from a CDN has told
  that CDN who is using it.
- **No separate SPA.** One deployable, one auth flow, one build.

## Where to read next

| Page | Covers |
| --- | --- |
| [`self-hosting.md`](self-hosting.md) | Running it |
| [`configuration.md`](configuration.md) | Every setting |
| [`api.md`](api.md) | The public API and webhooks |
| [`decisions.md`](decisions.md) | Fifty-four decisions, with the reasoning |
| [`roadmap.md`](roadmap.md) | What each phase built |
