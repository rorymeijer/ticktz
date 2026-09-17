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

Open it in a browser and the **setup wizard** takes it from there: it checks
the server, asks where the database should live, and creates your
administrator. See [First run](#first-run).

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

Open the instance in a browser. A fresh one sends every URL to `/install` and
walks you through seven short steps: requirements, database, what to call this
service desk, your account, and optionally outgoing e-mail.

### Where the data lives

The one question worth thinking about, and the wizard asks it plainly:

- **The built-in database.** The MySQL container that came with the compose
  file, already running next to the app. Its credentials are already in the
  environment, so the wizard fills them in and you press *Test connection*.
  Nothing to configure, nothing to maintain separately. This is the right
  answer for most self-hosters.
- **Your own database.** A MySQL or MariaDB server you already run — managed,
  clustered, or simply the one that is already in your backup schedule. Give
  it host, port, database, user and password.

If you choose your own, **create the database first**. Ticktz will not create
it, because a process that can create databases is a process with more rights
on your server than a service desk needs. The user does need permission to
create tables in it, and the wizard checks that before it lets you continue —
a connection that succeeds with a read-only grant would otherwise fail on the
first migration, which is a much worse place to find out.

The wizard never writes anything until the last step. Close the tab halfway
and you have lost nothing but the typing.

### Once it closes

The installer disappears the moment it finishes. It is unauthenticated by
necessity — there is nobody to authenticate as before the first account
exists — so leaving it reachable on a running instance would be a complete
takeover in three screens. Afterwards its endpoints answer 404 and `/install`
redirects to the login page. There is no setting that re-opens it; running it
again is `php artisan ticktz:install --force`, which requires shell access.

Upgrading an instance that predates the wizard does not trigger it: a migrated
database with users in it counts as installed.

### Without a browser

For unattended deployments, the same install runs on the command line — same
service, so the two cannot drift:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan ticktz:install \
  --name="Service desk" --url="https://servicedesk.example.org" \
  --locale=nl --timezone=Europe/Amsterdam --prefix=SUP \
  --db-host=mysql --db-name=ticktz --db-user=ticktz --db-password="$DB_PASSWORD" \
  --admin-name="Rianne Bakker" --admin-email=rianne@example.org \
  --admin-password="$ADMIN_PASSWORD"
```

Supply every option and it never prompts. Leave some out and it asks, unless
it is running non-interactively — a first-boot script blocked on a hidden
prompt is indistinguishable from a crash.

(`ticktz:admin` still exists for adding administrators later.)

### Then

Work through *Administration*:

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

### From the web, under Administration → Updates

Ticktz can tell you a new version exists, and on a source install it can
install one. Both are off until you switch them on, and they are separate
switches because they are different decisions.

```dotenv
# Look for new releases on GitHub. Off by default: this is the only outbound
# request Ticktz makes that nobody configured, and no telemetry was the rule
# this was built to. What GitHub learns is your IP address and that something
# asked for a public release list — nothing about your desk or your people.
TICKTZ_UPDATE_CHECK=true

# Allow this instance to replace its own code. Source installs only.
TICKTZ_SELF_UPGRADE=true
```

The screen then shows the installed version, what is published, the release
notes, and a set of readiness checks. Nothing is ever installed without
somebody pressing the button: there is no automatic upgrade and no schedule.

**What actually runs it is the queue worker, not the web request.** That is the
whole design and not an implementation detail. A web-facing PHP process must
never be able to write the application's own code — a service desk accepts
uploads from anybody with an e-mail address, and if the web process could write
code then every file-write bug in the application would become a way to run
code. So the button writes a row asking for a named, published release, and the
worker, running as the user who owns the code, acts on it.

Which gives the two requirements the checks look for:

- the user running `queue:work` owns the application directory, and
- the user running PHP-FPM or Apache does **not**.

If your deployment has both running as the same user, the screen says so. It
does not refuse to upgrade — that exposure is already there and refusing does
not remove it — but it is worth fixing on its own account.

The button is also absent when the release has no `ticktz-<version>.zip`
attached, when another upgrade is running, or when the release on offer changed
between the screen and the worker picking it up: what gets installed is a
version somebody agreed to, never whatever happens to be newest.

**Take a database backup first.** The upgrade keeps the previous code — in
`storage/app/upgrades/<id>/previous`, which is the one directory an upgrade
never replaces — but nothing here backs up your database, and the migrations
run right after the swap.

If the migrations fail, the new code stays in place and the failure is on the
screen with the path to the previous version. It is deliberately not rolled
back: migrations that failed halfway have already touched the schema, and
putting the old code back in front of a half-migrated database is a second
broken state rather than a recovery.

### From the command line

The same mechanism without the queue, which is also the answer for an install
with no worker running:

```bash
php artisan ticktz:upgrade --check     # what is available, then stop
php artisan ticktz:upgrade             # install it, asking first
```

### Docker

The bundled production stack **can** upgrade itself from the browser, and the
arrangement that makes that possible is worth understanding before you rely on
it.

The app and the worker are two containers from one image. Only what they both
mount is shared, so code written into a container's own writable layer is
invisible to its sibling and thrown away the next time the container is
recreated — an upgrade that reports success and silently reverts. So
`docker-compose.prod.yml` puts `/var/www/html` on a `code` volume that the app,
the worker and nginx all mount. The worker does the upgrade; the app serves
what it wrote.

The image is still the source of truth. Its code lives at `/usr/src/ticktz`,
and the entrypoint copies it into the volume on first boot and whenever the
image is the newer of the two. So:

- `docker compose pull && up -d` with a newer image → the image's code wins.
- A version installed from the browser → newer than the image, so it is left
  alone on every restart until you pull something newer still.
- A bind-mounted source tree, as in the development stack → never touched at
  all, because the entrypoint only replaces code it put there itself.

One consequence worth stating plainly: after a browser upgrade, `docker compose
pull` no longer moves you to whatever the image has unless that image is
*newer* than what you installed. The volume shadows nothing — the entrypoint
compares versions — but the running version is the higher of the two, not
whichever you pulled last.

#### If a command is refused for running in production, and you are not in production

Or the reverse: a setting you changed in `.env` has no effect, `printenv`
inside the container shows one value and `config(...)` reports another.

You are probably in the other stack. The two compose files describe two
separate stacks — `ticktz` for production, `ticktz-dev` for development — and a
`docker compose` command without `-f` reads `docker-compose.yml`, so it talks
to the development stack. Name the file you mean:

```bash
docker compose ps                                  # development
docker compose -f docker-compose.prod.yml ps       # production
```

Before 1.1.3 both files were named `ticktz`, which made them one stack as far
as Compose was concerned: bringing either up replaced the other's containers,
and a bare `docker compose exec app` reached whichever had gone up last. If you
started your instance before 1.1.3, your development containers still carry the
old name. Remove them once, and the two stacks stop colliding:

```bash
docker compose -f docker-compose.prod.yml down     # or `docker compose down`
docker compose up -d --build
```

Nothing is lost that you want to keep: the development stack's database is demo
data, and the production stack's data lives in `ticktz_mysql` and
`ticktz_storage`, which `down` without `-v` never touches.

#### If every page is a 500 and the log says MissingAppKeyException

The instance has no `APP_KEY`. Set one in your `.env` — that is where it
belongs, because it is backed up with the rest of your settings:

```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan key:generate --show
```

Put the `base64:…` it prints on the existing `APP_KEY=` line in `.env`
(replace it, do not add a second), then `docker compose -f
docker-compose.prod.yml up -d`.

From 1.1.2 an instance with no key generates one into the code volume and says
so in its log. That keeps it running, but a key in your own `.env` is better:
**this key encrypts the mailbox passwords in your database**, and a key that
lives only in the code volume is lost with `docker volume rm ticktz_code`.

#### If pages return an empty 500 but redirects work

The give-away is that shape: `GET /` answers 302 and the first page that
renders anything returns 500 with an empty body, and no log names a reason.
Laravel compiles every Blade view into `storage/framework/views` on first
render, so a `storage` the web server cannot write to fails exactly there and
nowhere earlier.

In the bundled stack the entrypoint runs as root and php-fpm as `www-data`, so
Ticktz 1.1.2 hands `storage` and `bootstrap/cache` over on every boot. On
1.1.0 and 1.1.1 it did not, and a `storage` volume created by those versions
is owned by root:

```bash
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data storage bootstrap/cache
```

No restart needed. Upgrading to 1.1.2 repairs it by itself.

#### If a page comes up white

Almost always `public/hot`: a file the Vite dev server writes, which makes
Laravel tell the browser to fetch its JavaScript from `http://localhost:5173`.
Nothing is wrong on the server, so nothing is in any log.

```bash
docker compose -f docker-compose.prod.yml exec app ls -l public/hot
docker compose -f docker-compose.prod.yml exec app rm -f public/hot
```

Laravel checks for that file on every request, so the page works again at once.

It got into the image because `docker build` copies the directory you build
from rather than what is committed, and before 1.1.1 there was no
`.dockerignore` to stop it. If you built an image before 1.1.1 from a checkout
that also held a `.env`, **that image contains your database password and
`APP_KEY`** — rotate them if you have pushed it anywhere.

#### If the app container crash-loops on a missing vendor file

A `502 Bad Gateway` from nginx with `Failed opening required '.../vendor/...'`
repeating in `docker compose logs app` means the code volume holds an
incomplete copy. Ticktz 1.1.1 repairs this by itself on the next start: the
entrypoint checks that the tree can load its own autoloader before trusting the
marker that says it is placed, and puts the image's copy back when it cannot.

On 1.1.0 it could not, because the marker was written when the copy *started*
rather than when it finished — so every restart read the marker, decided there
was nothing to do, and crashed on the same file. To recover by hand:

```bash
docker compose -f docker-compose.prod.yml exec app rm -f /var/www/html/.ticktz-image
docker compose -f docker-compose.prod.yml restart app worker
```

Or, if the app container will not stay up long enough to exec into:

```bash
docker compose -f docker-compose.prod.yml down
docker volume rm ticktz_code          # code only; your data is in ticktz_storage and ticktz_mysql
docker compose -f docker-compose.prod.yml up -d
```

`ticktz_code` holds nothing but the application. Removing it is safe, and the
next start fills it from the image. Do **not** use `down -v`, which takes the
database with it.

If you would rather keep the image immutable, remove the `code` volume from
your own compose override. The readiness checks will then say the code does not
persist and the button will be gone, which is correct: on that stack, these are
the commands.

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

### Putting a version back

A web upgrade keeps the code it replaced. To go back to it:

```bash
cd /srv/ticktz
ls storage/app/upgrades                          # the upgrade's id
cp -a storage/app/upgrades/<id>/previous/. .     # the old tree over the new
php artisan optimize:clear
```

That restores the code, not the database. A patch or minor release never
changes the schema in a way the previous version cannot read, so for those the
code alone is enough; across a major version, restore the database backup you
took before the upgrade as well.

### Upgrading MySQL 8.0 to 8.4

Ticktz 1.0.1 moves both compose stacks from `mysql:8.0` to `mysql:8.4`, the
current LTS — 8.0 left support in April 2026. The container upgrades the data
directory itself on first boot with the new image, which takes a minute or two
on a large desk and is **one-way**: an 8.4 data directory cannot be opened by
8.0 again. Take the backup first, as above, and if you would rather stay on 8.0
for now, pin the old image in your own compose override rather than editing the
file in the repository.

Nothing in Ticktz changes with it. The upgrade is listed here because a major
database version is not something to discover in a release note after the fact.

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
production you need PHP 8.4 with `bcmath`, `gd`, `intl`, `ldap`,
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

**`dependency failed to start: container ticktz-mysql-1 is unhealthy`.** Read
the database's own log — `docker compose logs mysql` — rather than the compose
summary, which only reports that the container never became healthy. MySQL
aborts on startup rather than ignoring a setting it does not recognise, so an
option meant for a different major version reads as
`[ERROR] [MY-000067] [Server] unknown variable '…'` followed immediately by
`Aborting`.

A failed first boot leaves a half-written data directory behind, and the
official image only initialises an empty one — so fixing the setting is not
enough on its own and the next start fails with `Table 'mysql.plugin' doesn't
exist`. On a stack with no data worth keeping, `docker compose down -v` throws
the volume away and the next `up` initialises cleanly. On one with data, restore
the backup into a fresh volume instead.

**`Trait "App\…" not found`, or any class that is plainly on disk.** The image
builds its autoloader as a classmap, and the development stack bind-mounts a
newer tree over it: a class added since the image was built is right there and
absent from the map. Two things made that permanent — the map was built
`--classmap-authoritative`, which means "not in the map, does not exist, do not
look", and the `vendor` volume kept it, because Docker only fills a named
volume from the image while it is still empty. So rebuilding the image did not
help either.

Both are fixed: the map is no longer authoritative, and the entrypoint rebuilds
it on every non-production boot. An older container needs its volume dropped
once — and `-v` alone would take the database with it:

```bash
docker compose down
docker volume rm ticktz-dev_vendor
docker compose up --build
```

**A change you pulled has no effect, and the symptoms point elsewhere.** The
image in the development stack carries a production-tuned `php.ini`, and
production means `opcache.validate_timestamps = 0`: PHP reads each file once
and never looks at it again, which is correct for a deploy that replaces an
image and restarts, and wrong for a bind-mounted source tree.

The failures it produces do not mention caching. A route added five minutes ago
returns 404 — while `php artisan route:list` lists it happily, because the CLI
runs with opcache off and reads the real file. A config value stays at its old
number, so anything keyed on it keeps serving what it cached: translations
added in the same commit come out as raw keys like `editor.image.failed`.

`docker-compose.yml` mounts `docker/php/php.dev.ini` over it, which turns
revalidation back on. If you have an older checkout, or a container started
before that mount existed:

```bash
docker compose exec app php -r 'echo ini_get("opcache.validate_timestamps"), "\n";'
```

`0` means the container is serving whatever it read when it started. `docker
compose up -d --force-recreate app worker` picks the mount up.

**A white page at http://localhost:8080, and nothing in any log.** The
development stack serves its JavaScript from the Vite container, and the URL
the browser is told to fetch it from is written into `public/hot` by the dev
server itself. A server listening on `0.0.0.0` — which it has to, to be
reachable from outside its container — writes `http://0.0.0.0:5173` there, and
that is not an address a browser can fetch. Chrome quietly treats it as
localhost and the page works; Safari and Firefox do not and it does not.

```bash
docker compose exec vite cat public/hot     # expect http://localhost:5173
```

If it says `0.0.0.0` or `[::]`, the `server.hmr.host` setting in
`vite.config.js` is missing or has been overridden. Nothing is wrong with the
application, and no log will say so — the page simply has no script to run.

**`Host '172.20.0.x' is not allowed to connect to this MySQL server`.** Error
1130, and it does not mean what it sounds like. The server is running and
reachable; it simply has no account by that name. The official image creates
`MYSQL_USER` only when it initialises an *empty* data directory, so a volume
left over from an earlier, failed boot gives you a server that starts, answers
root, and has never heard of the application's user:

```bash
# The password is read inside the container. It lives in .env, which your own
# shell has not sourced, so a $DB_ROOT_PASSWORD out here only makes mysql ask.
docker compose exec mysql sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "select user, host from mysql.user; show databases;"'
```

No `ticktz` row means the initialisation never ran. `docker compose down -v`
and start again, or restore a backup into a fresh volume.

One other way to reach the same error: setting `DB_USERNAME=root` in `.env`.
The image refuses to create an account called root — it already exists, as
`root@localhost` only — and logs that it is ignoring the request, so the app
then tries to connect as root from a container address and is turned away. Use
any other name.

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
