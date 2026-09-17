# Self-hosting Ticktz

Everything needed to run Ticktz on your own hardware and keep it running.
Ticktz is built to be self-hosted: all data stays in your MySQL, it makes no
outbound requests except the ones you configure, and there is no analytics SDK,
no telemetry and no third-party tracker anywhere in it.

- [Trying it out](#trying-it-out)
- [Running it for real](#running-it-for-real)
- [Behind your own TLS](#behind-your-own-tls)
- [First run](#first-run)
- [Backups](#backups)
- [Upgrading](#upgrading)
- [Scaling](#scaling)
- [Without Docker](#without-docker)
- [When something is wrong](#when-something-is-wrong)

## Trying it out

```bash
git clone https://github.com/rorymeijer/ticktz.git
cd ticktz
docker compose up
```

That is the whole thing. The development stack builds the image, waits for
MySQL and Redis, runs the migrations, seeds a populated demo desk and starts
Vite. Open <http://localhost:8080>.

| Role | E-mail | Password |
| --- | --- | --- |
| Administrator | `rianne@ticktz.test` | `ticktz-demo` |
| Agent | `joost@ticktz.test` | `ticktz-demo` |
| Requester | `m.visser@zandvliet.test` | `ticktz-demo` |

Outgoing mail goes to the bundled GreenMail server at <http://localhost:8025>
instead of anywhere real, so you can watch the notification pipeline without
configuring SMTP. It speaks IMAP as well, which is what lets you try
e-mail-to-ticket end to end locally: reply to a notification and watch the
comment appear on the ticket.

**This stack is for looking at, not for keeping.** It runs with debug on,
publishes MySQL and Redis on the host, uses fixed demo passwords and mounts
your working copy into the container.

## Running it for real

```bash
git clone https://github.com/rorymeijer/ticktz.git
cd ticktz
cp .env.example .env
```

Then edit `.env`. The minimum that has to change:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://servicedesk.example.org      # must match how people reach it
APP_KEY=                                      # generated on first boot if blank

DB_DATABASE=ticktz
DB_USERNAME=ticktz
DB_PASSWORD=                                  # required, no default
DB_ROOT_PASSWORD=                             # required, no default

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.org
MAIL_FROM_ADDRESS=servicedesk@example.org
```

`APP_URL` matters more than it looks. Links in notification e-mail, the
approve-by-e-mail links and the `instance` field on every webhook payload are
built from it; get it wrong and every one of them points somewhere useless.

Then:

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

The production stack differs from the development one in the ways that matter:
the image is self-contained with vendor and compiled assets baked in, there are
no bind mounts, MySQL and Redis are not published on the host, and there is no
Vite and no mail sink.

`APP_KEY` is generated on first boot if you left it blank. **Copy it out of the
container into your `.env` and keep it.** It encrypts the mailbox and directory
passwords stored in the database; lose it and those become unreadable, with no
error that says why.

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker \
  --execute="echo config('app.key');"
```

Full list of settings: [`configuration.md`](configuration.md).

## Behind your own TLS

The stack does not terminate TLS. It listens on `TICKTZ_HTTP_PORT` — `8080` in
the shipped `.env.example`, `80` if the variable is unset — and expects a
reverse proxy in front. Almost every organisation
running this already has one, along with its own certificate process, and a
second opinion about certificates inside the compose file only gets in the way.

Put Caddy, Traefik, nginx or your load balancer in front, and make sure it
sets `X-Forwarded-Proto`. The app trusts proxy headers already, so once that
header is right the generated URLs and the `secure` cookie flag follow.

A minimal nginx front end:

```nginx
server {
    listen 443 ssl http2;
    server_name servicedesk.example.org;

    ssl_certificate     /etc/ssl/certs/servicedesk.pem;
    ssl_certificate_key /etc/ssl/private/servicedesk.key;

    # Attachments. Keep this at or above TICKTZ_MAX_ATTACHMENT_KB, or uploads
    # fail at the proxy with an error the application never sees and cannot
    # explain.
    client_max_body_size 32m;

    location / {
        proxy_pass http://127.0.0.1:8080;   # TICKTZ_HTTP_PORT
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## First run

The migrations run automatically when the app container starts
(`TICKTZ_AUTO_MIGRATE=false` turns that off if you would rather run them
yourself). What is left is an administrator:

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan ticktz:admin
```

Then work through *Administration*:

1. **Settings** — the instance name, the ticket key prefix, the default
   language.
2. **Mailboxes** — outgoing SMTP and, if you want e-mail-to-ticket, an IMAP
   mailbox. Both have a *Test* button; use it before you trust either.
3. **Service levels** — a business calendar with your holidays, then the
   policies. Until a policy matches, nothing is measured.
4. **Teams, organisations and roles** — before you invite anybody, so people
   land somewhere.
5. **Request types** — what the portal offers, and which fields each one asks
   for.

Do not seed demo data into a production instance. `ticktz:demo` refuses to run
outside local unless forced, and that guard is there for a reason.

## Backups

```bash
scripts/backup.sh /var/backups/ticktz
```

Writes a timestamped directory containing the database, the storage directory
and `.env`. Restore with:

```bash
scripts/restore.sh /var/backups/ticktz/ticktz-20260917T031500Z
```

Three things worth knowing:

- **The three parts belong together.** `APP_KEY` from `.env` decrypts the
  mailbox and directory passwords held in the database. A database restored
  without the matching key gives you an instance that starts, shows every
  ticket, and cannot read a single mailbox — with nothing in the log that says
  why. `restore.sh` checks for this and warns before it overwrites anything.
- **The archive is as sensitive as the instance.** It contains working
  credentials. Store it where you store the production `.env`, not next to
  your public assets.
- **`restore.sh` never overwrites `.env`.** It drops the archived copy beside
  it as `.env.restored` so you can diff the two — a new host usually needs a
  different `APP_URL`, different mail settings and different TLS, and silently
  adopting a backup of someone else's configuration is a worse failure than
  the one you are recovering from. The line you must carry across is `APP_KEY`.

A nightly cron:

```cron
30 2 * * *  cd /srv/ticktz && ./scripts/backup.sh /var/backups/ticktz >> /var/log/ticktz-backup.log 2>&1
```

Test a restore onto a scratch host at least once. An untested backup is a
hypothesis.

## Upgrading

```bash
cd /srv/ticktz
./scripts/backup.sh /var/backups/ticktz          # always, first
git fetch --tags && git checkout v1.1.0
docker compose -f docker-compose.prod.yml up -d --build
```

Migrations run on boot. The workers pick up the new code when their container
restarts, which `up -d` does.

Read [`CHANGELOG.md`](../CHANGELOG.md) before a major version: within a major,
upgrades never need a manual step, and across one they sometimes do.

Published images, if you would rather not build:

```bash
docker pull ghcr.io/rorymeijer/ticktz:1.1.0
```

Pin a version rather than `latest` on anything you care about. `latest` moves.

## Scaling

The brief this was built to sets the target at 500 agents and 10,000
requesters. The shape that gets you there:

**The app is stateless.** Sessions and cache live in Redis, so you can run
several app containers behind your load balancer and lose one without logging
anybody out. The public API is stateless by construction — tokens, no session.

```bash
docker compose -f docker-compose.prod.yml up -d --scale app=3
```

**The workers scale separately**, and should. Background work is split across
five queues so a slow mailbox poll can never starve SLA breach detection:

| Queue | Carries |
| --- | --- |
| `high` | SLA breach detection and escalation |
| `default` | automation rules, approvals |
| `mail` | outgoing notifications |
| `webhooks` | outgoing webhook deliveries |
| `low` | housekeeping, log pruning |

```dotenv
TICKTZ_QUEUES=high,default,mail,webhooks,low
TICKTZ_QUEUE_WORKERS=4
```

A dead webhook endpoint retries with a growing backoff on its own queue, so it
cannot delay an SLA escalation. Do make sure something consumes `webhooks`: a
queue with no worker is a queue that silently never delivers.

**The database is the one thing that does not scale sideways.** Every list
query is paginated and indexed, and reporting reads a daily rollup rather than
aggregating the ticket table, precisely so the database stays boring. Give it
memory and fast disk before you give it anything else.

## Without Docker

If you would rather run it directly, see
[`CONTRIBUTING.md`](../CONTRIBUTING.md) for the development setup. For
production you need PHP 8.3 or 8.4 with `bcmath`, `gd`, `intl`, `ldap`,
`mbstring`, `pdo_mysql`, `redis`, `sockets` and `zip`; MySQL 8; Redis; and:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

Two things must keep running, or features go quiet without any error:

```bash
# The queue worker. Under supervisor, systemd, anything that restarts it.
php artisan queue:work --queue=high,default,mail,webhooks,low --tries=3

# The scheduler, every minute.
* * * * * cd /srv/ticktz && php artisan schedule:run >> /dev/null 2>&1
```

Without the scheduler there is no mailbox polling, no SLA sweep, no approval
reminders and no metrics rebuild. Nothing breaks visibly; the desk just stops
noticing things.

## When something is wrong

**Check the health endpoint first.** `/health` reports the database, the cache
and the queue, and will usually tell you which one it is. It does not check the
scheduler — see the last item below.

**No e-mail is arriving.** Is the `mail` queue being consumed? `php artisan
queue:failed` lists what died. The mailbox screen has a *Test* button that
sends through the real path.

**E-mail-to-ticket is not creating tickets.** The scheduler polls the mailbox;
without it nothing happens. Check *Administration → Mailboxes* for the last
poll time and the error from the last attempt.

**SLA clocks are not moving.** A ticket only gets a clock if a policy matches
it, and policies are checked in order. *Administration → Service levels* shows
which policy a ticket took.

**A webhook is not arriving.** *Administration → Webhooks* shows every attempt
with the response and the error. After twenty consecutive failures a
subscription disables itself; it stays in the list, marked, with its count.

**Nothing at all happens in the background.** Almost always the scheduler, the
worker, or a queue nobody is consuming — in that order. A stopped scheduler is
the quiet one: `/health` cannot see it, nothing errors, and the desk simply
stops noticing things. `php artisan schedule:list` shows when each task is next
due; if those times never advance, the scheduler is not running.

**A container will not start.** `docker compose -f docker-compose.prod.yml logs
app`. The entrypoint waits up to two minutes for the database and says so while
it waits.

Logs are on stdout, so `docker compose logs` and whatever aggregates them
already has everything. Nothing is written to a file inside the container that
you would have to go and find.
