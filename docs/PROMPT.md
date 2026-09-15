# Original build brief

This is the verbatim brief (in Dutch) that Ticktz was built from, kept in the
repository for traceability: every architectural decision in `docs/architecture.md`
traces back to a requirement below. The brief is the source of truth for
*intent*; where the implementation deliberately deviates, the deviation is
recorded in `docs/decisions.md`.

---

# Bouwprompt — Ticktz (self-hosted Jira Service Management-alternatief)

> **Naam:** Ticktz

Dit is een bouwopdracht voor Claude Code. Bouw **fase voor fase**. Elke fase eindigt in een **draaiende, testbare** staat (`docker compose up` werkt, migraties draaien, tests groen) voordat je aan de volgende begint. Vraag niet om bevestiging tussen fases tenzij een keuze de architectuur fundamenteel raakt.

---

## 1. Context & doel

**Ticktz** is een **self-hosted service desk** — een open-source alternatief voor Jira Service Management, bedoeld voor teams die niet naar cloud-only willen. Het wordt op GitHub gepubliceerd én intern gebruikt binnen een Nederlandse overheids-/juridische organisatie.

Kernprincipes:
- **Self-host-first:** één `docker compose up` draait de volledige stack. Geen externe SaaS-afhankelijkheden.
- **Privacy & controle:** alle data in eigen MySQL. Geen analytics-SDK's, geen telemetrie, geen third-party trackers.
- **Meertalig:** volledige i18n (start NL + EN), UI-taal per gebruiker instelbaar.
- **Schaal-doel:** tot **500 agents** en **10.000 requesters**. Stateless API + Redis zodat horizontaal schalen mogelijk is.

## 2. Architectuur & stack

- **Backend:** Laravel 11, PHP 8.3. Benut het framework maximaal: Eloquent, migrations + seeders, **queues + scheduler** (Redis) voor achtergrondwerk, **events/listeners** als fundament onder de automation-engine, **policies/gates** voor RBAC, Form Requests voor validatie, Mailables voor uitgaande mail, en Laravel-localization voor i18n.
- **Database:** MySQL 8 (utf8mb4), goed geïndexeerd, pagineer alles.
- **Frontend:** **Inertia.js + React + TypeScript**, gebouwd met Vite en door Laravel geserveerd — geen aparte SPA met eigen API-auth/JWT-refresh/CORS-laag. Tailwind + een lichte headless componentenset (bijv. Radix). Houd de dependency-footprint klein; geen zware UI-frameworks (geen MUI/AntD).
- **Auth (app-UI):** sessie-gebaseerd via Laravel-auth (Inertia). Lokale accounts **én** LDAP/Active Directory via `directorytree/ldaprecord-laravel` met group→role mapping; beide tegelijk actief mogelijk.
- **Auth (publieke API):** `laravel/sanctum` met scoped personal-access-tokens — losstaand, in een eigen latere fase.
- **Sleutel-packages:** `directorytree/ldaprecord-laravel` (AD/LDAP), `webklex/php-imap` (email-to-ticket), `laravel/sanctum` (API-tokens). Verder terughoudend met packages.
- **Background jobs:** aparte **worker-container** die `queue:work` + `schedule:run` draait — SLA-timers, escalaties, IMAP-polling, geplande rapporten en automation scheduled-triggers. **Redis** voor queue + cache + sessies.
- **Reverse proxy:** nginx voor de Laravel-app.
- **Mail:** uitgaand via SMTP (Mailables), inkomend via IMAP (email-to-ticket).

### Docker Compose-services
`nginx` · `php-fpm` (Laravel-app: web + Inertia + API) · `worker` (`queue:work` + `schedule:run`) · `mysql` · `redis` · `vite` (dev-server / prod-build). Voor dev een mail-testcontainer (MailHog of GreenMail).

### Conventies
- **Code, commits, repo-docs (README, CONTRIBUTING, self-host guide) in het Engels** (internationaal GitHub-project).
- **UI meertalig** via translation-bestanden (NL + EN uit de doos).
- Elke fase: migraties, seeders, en minimaal happy-path tests (Pest of PHPUnit backend, Vitest frontend).
- RBAC afgedwongen op **elke** endpoint. Audit-log op alle muterende acties.
- `.env`-driven config, veilige defaults, geen secrets in de repo.

## 3. Rollen

- **Admin** — beheer van alles: gebruikers, rollen, request types, SLA's, automation, kanalen, branding.
- **Agent** — werkt tickets af (queues, toewijzing, interne/publieke reacties, transities). Optioneel per team beperkt.
- **Requester** — dient verzoeken in via het portaal, volgt eigen tickets, reageert. Kan tot een organisatie behoren.

## 4. Datamodel (kern-entiteiten)

Identity: `users`, `roles`, `permissions`, `role_permissions`, `teams`, `team_members`, `organizations`, `ldap_configs`.
Ticketing: `tickets` (met leesbare key, bijv. `SUP-1042`), `ticket_statuses`, `workflows`, `workflow_transitions`, `priorities`, `queues`, `comments` (public/internal), `attachments`, `watchers`, `labels`, `ticket_links`, `audit_log`.
Custom fields: `custom_fields`, `custom_field_values` (herbruikbaar over tickets, request types, assets).
Portal: `request_types`, `request_type_fields`, `portal_categories`, `portal_settings` (branding).
Email: `email_channels` (SMTP/IMAP-config per postbus), `email_templates`, `inbound_messages`.
SLA: `sla_policies`, `sla_goals`, `business_calendars`, `calendar_holidays`, `sla_timers`, `sla_events`.
Automation: `automation_rules`, `automation_executions`.
Knowledge base: `kb_categories`, `kb_articles`, `kb_article_versions`.
Assets/CMDB: `asset_types`, `assets`, `asset_relationships`.
Approvals: `approval_workflows`, `approval_steps`, `approval_requests`, `approval_decisions`.
Reporting: `dashboards`, `dashboard_widgets`, `saved_reports`.
API & integraties: `personal_access_tokens` (Sanctum), `webhook_subscriptions`, `webhook_deliveries`.
Cross-cutting: `notifications`, `translations`, `settings`.

## 5. Gefaseerd bouwplan

Elke fase is een compileerbare mijlpaal. "Runbaar" = `docker compose up` werkt, migraties draaien schoon, tests groen.

### Fase 0 — Fundament & Docker
Laravel-scaffold met Inertia + React + TypeScript (Vite), Docker Compose (alle services), `.env`-config, Redis voor cache/queue/sessies, healthcheck-route, lege Inertia app-shell, CI-skeleton (GitHub Actions: build + test).
**Acceptatie:** `docker compose up` serveert de Inertia app-shell en `GET /health` → 200; queue-worker draait.

### Fase 1 — Auth, gebruikers & RBAC
Migraties voor users/roles/permissions/teams/organizations. Sessie-gebaseerde lokale auth (Laravel-auth, wachtwoord-reset). LDAP/AD via `ldaprecord-laravel` (bind, group→role mapping, config in admin). RBAC via policies/gates. Admin-CRUD voor gebruikers/rollen/teams. Inertia: login, geauthenticeerde shell, rol-gebaseerde navigatie.
**Acceptatie:** lokale + LDAP-login werken; een agent ziet een andere shell dan een admin; policies afgedwongen server-side.

### Fase 2 — Ticket-core & agent-console
Tickets met leesbare keys, statussen, prioriteiten, workflows + transities, queues (opgeslagen filters), toewijzing, publieke/interne comments, attachments, watchers, labels, ticket-links, audit-log. Agent-console: queue-lijst, ticketdetail, reageren, toewijzen, status wijzigen.
**Acceptatie:** een agent maakt/behandelt een ticket end-to-end; interne notes onzichtbaar voor requesters; transities respecteren de workflow.

### Fase 3 — Klantportaal & request types
Custom-fields-framework, request types met dynamische formulieren, portaalcategorieën, portaal-branding. Portaal-UI: request types bladeren, verzoek indienen, "mijn verzoeken", reageren.
**Acceptatie:** een requester dient via het portaal een verzoek in dat als ticket in de juiste queue verschijnt.

### Fase 4 — E-mail (SMTP/IMAP)
`email_channels` (SMTP uit, IMAP in), e-mailtemplates, notificaties via Mailables (ticket aangemaakt/geüpdatet/beantwoord). Scheduled job pollt IMAP via `webklex/php-imap` → maakt/threadt tickets, e-mail-reply wordt comment, requester auto-provisioning bij onbekend adres.
**Acceptatie:** mail naar de postbus maakt een ticket; agent-reply gaat als mail uit; vervolgmail belandt als comment op hetzelfde ticket.

### Fase 5 — SLA-engine & escalaties
SLA-policies, goals per prioriteit/request type, business-calendars + feestdagen, per-ticket timers (pauzeren op wachtstatussen), breach-detectie via scheduled job, escalatie-acties als queued jobs. SLA-status zichtbaar op tickets/queues.
**Acceptatie:** een ticket toont aftellende SLA; breach triggert escalatie/notificatie; kloktijd respecteert kantooruren.

### Fase 6 — Automation rules
Regel-engine bovenop Laravel events/listeners: triggers (ticket created/updated/commented, SLA-breach, scheduled), condities (veld-matchers, AND/OR), acties (toewijzen, transitie, veld zetten, notificeren, webhook) als queued jobs. Uitvoeringslog + admin-UI om regels te bouwen.
**Acceptatie:** een regel "nieuw ticket in queue X → wijs toe aan team Y + zet prioriteit" werkt en is gelogd.

### Fase 7 — Knowledge base
KB-categorieën/artikelen, versionering, rich text, portaalzoek, artikelsuggesties op request-formulieren, artikel↔ticket koppelen, toegang (intern/extern).
**Acceptatie:** requester vindt en leest een publiek artikel; agent linkt een artikel aan een ticket; interne artikelen blijven verborgen op het portaal.

### Fase 8 — Approvals
Approval-workflows (single/parallel/sequentieel), approval-requests op tickets/request types, approver-beslissingen (ook per e-mail), transities gate-en op goedkeuring.
**Acceptatie:** een request type met verplichte goedkeuring blokkeert voortgang tot een approver akkoord geeft.

### Fase 9 — Assets / CMDB
Asset-types met custom attributen, assets/CI's, relaties, CSV-import, assets koppelen aan tickets/requesters, asset-zoek.
**Acceptatie:** een asset is aan een ticket gekoppeld en vindbaar; relaties tussen assets zijn te bekijken.

### Fase 10 — Rapportage & dashboards
Metrics-pipeline, dashboards met widgets (ticketvolume, SLA-compliance, agent-workload, doorlooptijd, optioneel CSAT), opgeslagen rapporten, CSV-export.
**Acceptatie:** een SLA-compliance-dashboard toont correcte cijfers over een periode; export werkt.

### Fase 11 — Publieke REST-API & webhooks (Sanctum)
Losstaande, gedocumenteerde REST-API voor integraties: `laravel/sanctum` met **scoped** personal-access-tokens, endpoints voor tickets/comments/queues/assets/KB (CRUD waar zinnig), per-token rate limiting, en **uitgaande webhooks** (`webhook_subscriptions` op events, met retry + `webhook_deliveries`-log). OpenAPI-spec + API-docs. Token-beheer in de UI.
**Acceptatie:** een extern script maakt met een scoped token een ticket via de API; een geconfigureerde webhook vuurt betrouwbaar op "ticket created" en wordt gelogd; token zonder scope wordt geweigerd.

### Fase 12 — i18n, polish, docs & release
Volledige i18n (NL/EN) via translation-bestanden, taal per gebruiker, toegankelijkheidsronde, seed/demo-data, README + self-host-gids, backup/restore-scripts, LICENSE, GitHub Actions CI compleet, versioned release + Docker images.
**Acceptatie:** een verse `git clone` + `docker compose up` levert een werkende, gevulde demo-instance; UI volledig NL óf EN.

## 6. Niet-functionele eisen

- **Security:** RBAC op elke endpoint, CSRF/XSS-bescherming, prepared statements overal, rate limiting op auth + portaal, attachment-limieten + type-checks, volledige audit-trail.
- **Performance/schaal:** paginering + indexen op alle lijst-queries, Redis-cache voor hot paths. App-UI draait op sessies in Redis (dus meerdere app-containers mogelijk); de publieke API is stateless via Sanctum-tokens. Worker afzonderlijk schaalbaar.
- **Betrouwbaarheid:** idempotente IMAP-verwerking (geen dubbele tickets), retry op mail/webhook-acties, graceful job-failures met log.
- **Onderhoud:** backup/restore-scripts, migratie-versionering, `.env.example`, duidelijke self-host-docs.

## 7. Werkwijze voor Claude Code

1. Begin bij Fase 0 en werk sequentieel. Commit per fase.
2. Houd de app na elke fase runbaar; draai migraties en tests voordat je verdergaat.
3. Schrijf migraties + seeders bij elk nieuw datamodel-onderdeel.
4. Voeg per fase minimaal happy-path-tests toe (Pest/PHPUnit + Vitest).
5. Documenteer nieuwe endpoints en config in de repo-docs terwijl je gaat.
6. Stel alleen een vraag als een keuze de architectuur fundamenteel verandert; kies anders een verstandige default en noteer die in de docs.

**Start nu met Fase 0.**

---

Additional instruction given alongside the brief:

> Maak ook screenshots en een goede documentatie en sla ook het prompt op.
> *(Also produce screenshots and good documentation, and save the prompt.)*
