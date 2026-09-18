# Configuration

Everything Ticktz reads from the environment. Configuration is `.env`-driven
with safe defaults, and no secret is committed to the repository — the settings
below are the whole surface.

Settings that belong to a *person* (language) or to the *desk* (statuses, SLA
policies, request types, mailboxes) are not here. Those live in the database
and are edited in *Administration*, because they change while the instance is
running and an operator should not need a deploy to rename a status.

`.env.example` is the annotated reference; this page explains the ones where
the value matters more than it looks.

## Application

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_NAME` | `Ticktz` | Shown in the interface and in e-mail |
| `APP_ENV` | `local` | `production` on anything real — it also switches on config, route and event caching at boot |
| `APP_KEY` | *(generated)* | See below. Losing it costs you data you can still see |
| `APP_DEBUG` | `true` | `false` in production. With it on, a 500 returns the exception message to the caller |
| `APP_URL` | `http://localhost:8080` | Must match how people actually reach the instance |
| `APP_LOCALE` | `en` | Fallback for users who have not chosen one |
| `APP_FALLBACK_LOCALE` | `en` | Used when a translation is missing |
| `APP_TIMEZONE` | `Europe/Amsterdam` | Business calendars are evaluated in this zone |
| `TICKTZ_VERSION` | the release | Reported by `/health` and sent as `User-Agent` on webhooks |

### APP_KEY

Generated on first boot if blank, and then **you have to keep it**. It encrypts
the mailbox and directory passwords held in the database. Restore a database
onto an instance with a different key and you get something that starts, shows
every ticket, and cannot read a single mailbox — with nothing in the log
explaining why. `scripts/backup.sh` puts it in the archive for exactly this
reason.

Rotating it means re-entering every mailbox and directory password afterwards.

### APP_URL

Notification links, the approve-by-e-mail links and the `instance` field on
every webhook payload are built from this. Behind a reverse proxy, set it to
the public address, not the container's.

## Database and cache

| Variable | Default | Notes |
| --- | --- | --- |
| `DB_CONNECTION` | `mysql` | MySQL 8 with `utf8mb4` |
| `DB_HOST` / `DB_PORT` | `mysql` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | No defaults in the production stack, deliberately |
| `DB_ROOT_PASSWORD` | — | Only the bundled MySQL container uses it |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` | |
| `REDIS_PASSWORD` | *(none)* | Set it if Redis is reachable from anywhere but the compose network |
| `SESSION_DRIVER` | `redis` | Keep it on Redis: it is what lets you run more than one app container |
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | `sync` runs jobs inline — useful locally, wrong in production |

`QUEUE_CONNECTION=sync` is worth understanding rather than just avoiding. On
`sync` there is no worker, so an unreachable webhook endpoint or mail server
would throw inside the request that triggered it. Ticktz deliberately reports
and swallows those on `sync`, because a ticket that cannot be filed because
someone else's server is down is a worse failure than a notification nobody
received. In production, with a real queue, the same failures are retried
instead.

## Mail

| Variable | Default | Notes |
| --- | --- | --- |
| `MAIL_MAILER` | `smtp` | `log` writes to the log instead of sending; `array` discards |
| `MAIL_HOST` / `MAIL_PORT` | `mail` / `3025` | The GreenMail container in the development stack |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | *(none)* | |
| `MAIL_SCHEME` | *(none)* | `tls` where the server wants it |
| `MAIL_FROM_ADDRESS` | `servicedesk@example.org` | Also the reply-to for e-mail-to-ticket |
| `MAIL_FROM_NAME` | `${APP_NAME}` | |

Incoming mailboxes are *not* configured here. They are per-mailbox rows with
their own host, credentials and folder, set up in *Administration → Mailboxes*,
because a desk usually has more than one and they change without a deploy.

## Queues

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_QUEUES` | `high,default,mail,webhooks,low` | What the bundled worker consumes, in priority order |
| `TICKTZ_QUEUE_WORKERS` | `2` | Processes per worker container |
| `TICKTZ_QUEUE_HIGH` | `high` | SLA breach detection and escalation |
| `TICKTZ_QUEUE_DEFAULT` | `default` | Automation rules, approvals |
| `TICKTZ_QUEUE_MAIL` | `mail` | Outgoing notifications |
| `TICKTZ_QUEUE_WEBHOOKS` | `webhooks` | Outgoing webhook deliveries |
| `TICKTZ_QUEUE_LOW` | `low` | Housekeeping and log pruning |

The split exists so one slow thing cannot starve another: a mailbox poll
against an unresponsive IMAP server, or a webhook retrying into a dead
endpoint, must not delay SLA breach detection. If you narrow `TICKTZ_QUEUES`,
make sure every queue still has something consuming it — a queue with no worker
does not error, it just silently never runs.

## Rate limiting

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_LOGIN_RATE_LIMIT` | `5` | Per account and IP per minute. A coarser per-IP backstop sits at 4× this |
| `TICKTZ_PORTAL_RATE_LIMIT` | `60` | Per requester per minute |
| `TICKTZ_API_RATE_LIMIT` | `120` | Per **token** per minute, for tokens with no ceiling of their own |

The API limit is per token rather than per account on purpose: two integrations
owned by one service account are two callers, and an instance-wide limit cannot
tell them apart. Individual tokens can be given a tighter ceiling in the UI.

## Attachments

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_ATTACHMENT_DISK` | `local` | Never a public disk: attachments are streamed through a policy check |
| `TICKTZ_MAX_ATTACHMENT_KB` | `25600` | 25 MB |
| `TICKTZ_ALLOWED_ATTACHMENT_EXTENSIONS` | *(see `.env.example`)* | An allow-list, not a block-list |

Raising `TICKTZ_MAX_ATTACHMENT_KB` above your reverse proxy's body limit gives
uploads that fail at the proxy, with an error the application never sees and
cannot explain. Raise both.

The extension list is an allow-list because a block-list is a list of the
attacks somebody already thought of.

## Public API

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_API_TOKEN_MINUTES` | *(unset)* | Instance-wide expiry for tokens issued without one |
| `SANCTUM_TOKEN_PREFIX` | `ticktz_` | Prefixes the plaintext so a leaked token is recognisable to a secret scanner |

See [`api.md`](api.md) for how scopes work.

## Approvals

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_APPROVAL_EMAIL_LINKS` | `true` | Lets an approver decide from their inbox |
| `TICKTZ_APPROVAL_TOKEN_DAYS` | `30` | How long such a link stays valid |
| `TICKTZ_APPROVAL_REMINDER_HOURS` | `24` | `0` turns reminders off |

Set `TICKTZ_APPROVAL_EMAIL_LINKS=false` on a desk approving things that matter.
The links are single-use, hashed at rest and expiring, but they are still a
credential sitting in an inbox; with this off, the notification says what is
waiting and links to the portal, where the approver signs in.

## Automation

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_AUTOMATION_MAX_DEPTH` | `5` | How far a chain of rules reacting to rules may run |
| `TICKTZ_AUTOMATION_LOG_DAYS` | `30` | The log records every evaluation, including skips, so it grows quickly |

A rule already runs at most once per ticket per cascade, so the depth limit is
the backstop rather than the main defence — but a runaway automation is the one
failure mode of that feature that can take an instance down.

## Pagination

| Variable | Default |
| --- | --- |
| `TICKTZ_PER_PAGE_TICKETS` | `25` |
| `TICKTZ_PER_PAGE_ADMIN` | `25` |
| `TICKTZ_PER_PAGE_PORTAL` | `15` |

Everything paginates. The API caps `per_page` at 100 regardless of what a
caller asks for.

## Rich text

Nothing to configure. Every field somebody writes prose into is rich text, and
what each one may contain is decided by a profile in the code rather than a
setting — an administrator who could widen the allowlist could grant themselves
a script tag, which is the one thing the allowlist exists to refuse.

Two profiles: knowledge base articles get the document vocabulary (headings,
tables, images, code blocks), and everything else gets the message vocabulary
(emphasis, lists, quotes, links, tables). Both refuse scripts, forms, iframes,
embedded media, event handlers, `style` and `class` attributes, and any link
scheme a browser would execute.

What stays plain text: names, keys, slugs, addresses, CSV mappings, automation
conditions, and e-mail templates. See [`decisions.md`](decisions.md) (D62).

### Pasted images

Tickets, replies and knowledge base articles take images; nothing else does.
They are stored on the attachment disk by default, never on a public one, and
served through a policy that follows whatever they were pasted into — a
screenshot on an internal note is not readable by the requester.

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_RICH_TEXT_IMAGE_DISK` | the attachment disk | Where pasted images are stored. Never a public disk |
| `TICKTZ_MAX_INLINE_IMAGE_KB` | `5120` | Smaller than the attachment limit on purpose: this is a picture inside somebody's reading, not a file they chose to send |
| `TICKTZ_ALLOWED_INLINE_IMAGE_EXTENSIONS` | `png,jpg,jpeg,gif,webp` | Checked by reading the file, not by trusting its name |
| `TICKTZ_INLINE_IMAGE_ORPHAN_DAYS` | `7` | How long an image nobody ever saved is kept. `ticktz:prune-images` sweeps the rest, daily |
| `TICKTZ_UPLOAD_RATE_LIMIT` | `30` | Uploads per minute per person |

A message may only show an image this instance is serving. A remote one is a
tracking pixel — see D63. That applies to inbound mail too: the remote images
in a customer's signature are dropped when the reply is filed. Articles may still embed a remote image, because they
are written by staff on purpose; that is one line to change if you would rather
they could not.

## Directories (LDAP / Active Directory)

Connection details are **not** environment variables. They are rows in the
database, configured in *Administration → Directories*, with the bind password
encrypted using `APP_KEY`. A desk often has more than one directory, and the
settings change without a deploy.

`config/ldap.php` holds only the driver-level defaults.

Directory sign-in needs `ext-ldap`. Ticktz runs without it — the directory
screens simply stay unavailable — but the bundled image ships with it.

**Group mapping.** With *Sync groups* on and at least one group mapped to a
role, the directory decides what somebody may do: roles are recomputed on every
sign-in, so taking a person out of a group upstream drops them back to the
default role here the next time they log in. With the map still empty nothing
is recomputed and existing roles are left alone, so switching group sync on
before filling the mapping in cannot demote you out of the screen you are
configuring.

## Updates

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_UPDATE_CHECK` | `false` | Ask GitHub whether a newer release exists. Off by default and it stays off: this is the only outbound request Ticktz makes that nobody configured. What GitHub learns when it is on is this server's IP address and that something asked for a public release list — nothing about the desk, its version or its people, and no token is sent |
| `TICKTZ_UPDATE_REPOSITORY` | `rorymeijer/ticktz` | Where releases are read from. Point it at your own fork if you publish your own builds |
| `TICKTZ_UPDATE_PRE_RELEASES` | `false` | Offer releases marked pre-release on GitHub |
| `TICKTZ_UPDATE_CACHE_HOURS` | `6` | How long an answer is kept. Hours rather than minutes: an unauthenticated caller gets sixty requests an hour from one address, and a desk with four administrators refreshing a screen should not be able to spend them |
| `TICKTZ_SELF_UPGRADE` | `false` | Allow this instance to replace its own code from Administration → Updates. Source installs only, and only where the queue worker owns the application directory and the web server does not. A Docker install cannot self-upgrade at all — see [`docs/self-hosting.md`](self-hosting.md) |
| `TICKTZ_INSTALL_ROOT` | the application directory | Where the code lives, when that is not where PHP thinks it is |
| `TICKTZ_UPDATE_FREE_BYTES` | `1073741824` | Free disk an upgrade requires beside the installation. A flat floor; the exact check happens against the release's own size |

Nothing is ever installed without somebody pressing the button. There is no
automatic upgrade and no schedule, by design — see
[D66](decisions.md#d66--upgrading-from-the-browser-without-letting-the-browser-write-code).

## Docker

| Variable | Default | Notes |
| --- | --- | --- |
| `TICKTZ_HTTP_PORT` | `8080` | Host port the bundled nginx publishes (the compose fallback is `80` when unset). Terminate TLS in front of it |
| `TICKTZ_IMAGE` | `ghcr.io/rorymeijer/ticktz:latest` | The published image the production stack runs. Override to pin a version — `ghcr.io/rorymeijer/ticktz:1.1.5` — or to point at your own registry |
| `TICKTZ_AUTO_MIGRATE` | `false` in the production stack, `true` otherwise | Migrations run when the app container boots. Off in production so a first boot lands on the setup wizard rather than migrating into a database nobody has chosen |
| `TICKTZ_SEED_DEMO` | `true` in the dev stack, `false` otherwise | Seeds the demo desk on first boot. Ignored in production, and skipped entirely once the instance has any user — a restart must never re-seed over a desk somebody is using |
| `CONTAINER_ROLE` | `app` | `app` or `worker`; set by the compose files |

Host ports for the development stack only, so two checkouts can run at once:

| Variable | Default | Publishes |
| --- | --- | --- |
| `TICKTZ_VITE_PORT` | `5173` | The Vite dev server. Also what the browser is told to fetch assets from, so publishing a different port here is enough — no second setting to keep in step |
| `TICKTZ_MYSQL_PORT` | `3306` | MySQL, for a database client |
| `TICKTZ_MAIL_UI_PORT` | `8025` | GreenMail's web interface |
| `TICKTZ_MAIL_SMTP_PORT` | `3025` | GreenMail's SMTP listener |
| `TICKTZ_MAIL_IMAP_PORT` | `3143` | GreenMail's IMAP listener, which is what makes e-mail-to-ticket testable locally |

The production stack publishes none of these — MySQL and Redis are reachable
only from inside the compose network.
