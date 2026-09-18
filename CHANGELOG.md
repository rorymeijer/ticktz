# Changelog

Notable changes per release. Dates are the tag date; the phase numbers refer to
[`docs/roadmap.md`](docs/roadmap.md), which explains what each one set out to
build and why.

This project follows [semantic versioning](https://semver.org). For a
self-hosted application that mostly means: a major version may require a manual
step during an upgrade, a minor version never does, and a patch never changes
the database.

## 1.2.0

**A user manual, and a `?` that opens it at the page you are on.** Twenty
chapters in English and Dutch, covering both sides of Ticktz — raising and
following a request, working a ticket, service levels, approvals, the knowledge
base, assets, reporting, every administration area and the API.

It is permission-aware chapter by chapter, so an administrator's manual and an
agent's manual are different documents. A manual that describes doors you
cannot open makes somebody feel locked out rather than helped.

The `?` in the top bar opens the chapter for the screen you are on, without
navigating away from what you were stuck on. It does not appear on screens
with no chapter: a `?` that opens and admits there is nothing written is worse
than no `?`.

The chapters are Markdown in the repository rather than rows in the database,
so the manual an instance shows is the manual for the version it is running,
and an upgrade replaces it without touching anything anybody wrote in the
knowledge base. Documented as D73.

**The agent console shows the answers the requester gave.** A custom field
exists so the person who has to act on a request has the information without
asking. The portal collected the answers, stored them and showed them back to
the customer — and the agent console never rendered them, so the whole feature
was half a feature.

**A ticket held by a deactivated agent no longer reads "Unassigned".** The
assignee control was a `<select>` whose option list was filtered to active
agents. Deactivate somebody holding tickets and no option matched any more, so
the browser fell back to rendering the first one. The picker reads who holds
the ticket off the ticket, so it cannot disagree with it.

**Cloning a ticket**, optionally as another kind of request. What carries over
is what describes the request; what does not is everything recording the
original being handled. Filing the copy as another type moves it to that type's
queue, team and workflow, and drops answers the new form does not ask for.

**Searchable person pickers everywhere somebody is chosen.** Every picker was a
dropdown filled from a list rendered into the page: the first five hundred
active agents, or the first hundred users, with the rest dropped and nothing
saying so. On a desk of forty that is invisible; on a desk of four hundred,
half the staff simply cannot be chosen.

A list assembled by the page also could not tell the truth about who may hold a
ticket, so the assignee dropdown offered every agent on the desk while the
server refused anybody off the ticket's team — the rule reached the operator as
an error under a name they had just been offered.

The pickers search the server instead, out of the set it will actually accept.
Team membership, a user's manager, approval steps and automation rules stop
carrying the staff directory too. A team's leads and an approval step's teams
and roles deliberately stay checkbox lists: the rule is the size of the pool,
not the kind of thing in it.

**Handing a ticket to somebody is its own permission, on the way in too.**
`tickets.assign` gated the assign action and the button on the screen, and
nothing on the way in — so the way to hand a ticket to somebody without the
permission to hand out tickets was to do it while creating one, or through the
ticket form. The same shape of hole the team rule had, one layer further out,
and the API description had claimed the rule since the API existed. All four
doors check it now, and the create form stops drawing a field its reader cannot
use.

**Two things that were built and unreachable.** Adding a watcher to a ticket
had an endpoint, a permission and a translated label in both languages, and
nothing on any screen that called it — so putting somebody on a ticket meant
the API. Recording who holds an asset was validated by the server and shown on
the asset page, with no field anywhere to set it, on a register whose entire
point is knowing who has what.

**The agent console shows the answers the requester gave.** A custom field
exists so the person who has to act on a request has the information without
asking for it. The portal collected the answers, stored them and showed them
back to the customer — and the console never rendered them, so the whole
feature was half a feature.

**A ticket held by a deactivated agent no longer reads "Unassigned".** The
assignee control was a dropdown whose option list was filtered to active
agents. Deactivate somebody who is holding tickets and no option matches any
more, so the browser falls back to rendering the first one — which was
"Unassigned". Every ticket they held read as unassigned while still being
assigned to them.

**A release is about six minutes rather than eight.** The test suite in the
release runs the same `pest` and `composer audit` that CI has usually already
run twice — on the pull request, and on `main` after the merge — and its only
step CI does not also run is the tag-versus-version check.

It is still there, because a tag can be pushed at any commit and this is then
the only thing between that commit and everybody's `docker pull`. It just no
longer holds the image builds up: they start beside it and push by digest under
no tag, so nothing they produce is reachable by name until the job that applies
the tags, which does wait for the suite. A failing suite leaves a few untagged
blobs in the registry and nothing anybody can pull.

## 1.1.8

**The redis extension is built from source rather than fetched through pecl.**
The first two attempts at cutting this release both failed on `pecl install
redis`, an hour apart, with two different errors: `504 Gateway Timeout` on the
package tarball, and then `Package "redis" does not have REST info xml
available`.

The second is the one that mattered. `pecl install redis` cannot work out what
to download without the channel's REST metadata, so when `pecl.php.net` is
degraded the request that fails is the one that decides what to ask for. There
is nothing to retry into, and pinning a version does not help either — a
versioned install consults the same endpoint.

phpredis is now fetched from its own repository at a pinned tag and compiled in
place, which is what `pecl install` did underneath anyway. It trades a host
nobody here operates for the one this whole pipeline already cannot run
without, and pins the version so that two builds of the same tag cannot ship
different extensions.

Nothing about what the image contains changes.

**A ticket can only be held by somebody on its team.** Its own team, or its
queue's when it has none; a ticket with no team at all still goes to any agent,
because plenty of work arrives without one. Enforced on the server at all four
places an assignment can come from — the assign action, the ticket form, the
API and an automation rule — rather than by leaving people out of a dropdown,
which only one of the four has.

Two of those four were open. Creating a ticket already assigned skipped the
check entirely, and so did **claim**: the difference between being handed a
ticket and taking it was the difference between the rule applying and not.

**Moving a ticket to another team releases an assignee who is not on it.**
Refusing the move would make routine triage a two-step job. The release is
audible — the audit trail, the notification and the API response all show it —
and it happens only when the move is what you are doing, so editing the subject
of an older ticket that already breaks the rule leaves it alone.

**A release takes eight minutes instead of forty-five.** Nothing about the
application changed; this is how it is built.

The container image was built for both architectures in one job, with
`linux/arm64` running through QEMU on an Intel runner. The runtime stage
compiles a dozen PHP extensions from C, and emulating a compiler means
translating every instruction of it: forty-one of the forty-five minutes were
that one step, and a log frozen on `docker-php-ext-install` for half an hour is
indistinguishable from a build that has died. Two releases were cancelled on
that suspicion.

Each architecture is now built on a runner of its own architecture — GitHub
provides arm64 runners free to public repositories — and the two run side by
side, so a release costs the slower rather than the sum. Neither pushes a tag:
they push by digest, and a final job writes one manifest list over both and tags
that. `:latest` therefore never exists as one architecture while the other is
still building.

Two corrections came with it. Build caches are scoped per architecture, so the
second build no longer evicts the first's layers. And every job now checks out
the version being released rather than the ref the run started from: rebuilding
an old tag from **Run workflow** used to build the branch's code, and the image
tags — derived from a branch name by a semver pattern — came out empty, giving
a release whose image was reachable only by digest.

A run started from **Run workflow** also no longer re-drafts the release it
attaches to. That is how a release whose archive never uploaded gets its
archive, and doing it by retracting the release from every instance checking for
updates was the wrong trade.

**A tag typed without its `v` now says so.** *Run workflow* passed whatever was
in the box straight to `actions/checkout`, which retried a fetch for a ref that
does not exist twice, sixteen seconds apart, and then reported `The process
'/usr/bin/git' failed with exit code 1` — a message that names neither the tag
nor the problem. Both `1.1.8` and `v1.1.8` are accepted now, anything that is
not a version is refused in seconds, and a tag that does not exist is answered
with the command to create it and a list of the tags that do.

**`package.json` said 1.0.3.** It had said so for four releases while the
application reported 1.1.7 and `docs/releasing.md` said the two must agree. The
release now refuses to build unless the tag, `config/ticktz.php` and
`package.json` all say the same thing — a rule nothing checks is a rule that is
already broken.

## 1.1.7

**Your own database now works on the Docker stack.** Choosing it in the setup
wizard migrated that database, created the administrator in it, wrote the
credentials to `.env` — and then every request reconnected to the bundled one,
where that administrator does not exist. It had never worked.

The cause is one sentence: Laravel's dotenv is immutable, so a variable already
in the process environment is never replaced by `.env`. The compose file handed
the database details over as environment variables, which looked equivalent to
writing them in a file and is not: it made them outrank the very file the
wizard writes, for the life of the instance.

They are now delivered as *defaults for the instance's own `.env`*. The
entrypoint writes what is missing on first boot and never touches a key that is
already there, so an operator's settings and the wizard's both win — the
precedence people expect from a file that is theirs.

**And then taken back out of the environment.** `env_file` hands the operator's
whole `.env` beside the compose file to the container, so `DB_PASSWORD` arrives
there too, by a route the compose file cannot control. Having written the
settings into the instance's `.env`, the entrypoint unsets exactly the keys the
stack supplied a default for — and nothing else. `MAIL_HOST`, the queue tuning
and anything else in that file reach the container as before.

**Nothing to do on upgrade.** No renamed variables, no manual step. The first
version of this change renamed the bundled password to avoid the same
shadowing; it needed nested interpolation that older Docker Compose versions
reject, and it asked for a hand edit that this project's versioning policy says
a patch must not.

Two more things had to move with it, both found in review.

The setup wizard asked `env()` whether there was a bundled database to offer.
In production the boot compiles the configuration and Laravel then skips `.env`
entirely, so `env()` answers null for anything that is not also a real
environment variable — which these deliberately no longer are. The built-in
option would have disappeared from every production instance, one release after
being fixed. It asks the configuration now, which is compiled from the same
file. And the installer clears that compiled configuration after writing
`.env`, so that somebody who chose a database of their own is not sent to a
login page still querying the old one.

A third thing had to move with it. The entrypoint waits for the database before
migrating, and it was reading `getenv("DB_HOST")` — which, after this change, is
not set. That does not fail loudly: it waits two minutes for 127.0.0.1 on every
boot, logs one line, and then migrates the right database anyway, because the
framework reads `.env`. It now resolves settings the way the application does,
environment first and then `.env`, through `docker/php/instance-setting.php`
with a test of its own.


## 1.1.6

**The built-in database now asks for nothing.** Choosing it in the setup
wizard used to prefill four fields and leave the fifth blank — the password,
which is the only one that authenticates. Pressing *Test connection* then
answered:

> The server refused that username or password.

for a password the operator had never been asked for and could not have known.
It was the first screen of the installation, on the option labelled *nothing to
configure*.

The password was blank for a real reason: the installer runs before there is
anybody to sign in as, so putting a live database password in that page would
hand it to whoever reached the installer first. The mistake was asking for it
at all. Every value of the bundled database — host, port, name, user and
password — belongs to the container the compose file started, and the server
already has all five in its environment. So the screen now shows one sentence
saying which database will be used, and sends nothing but the choice; the
server reads the credentials itself and ignores any that were submitted, which
also stops an unauthenticated endpoint from being pointed at somebody else's
server.

Your own database is unchanged: every field is still asked for and tested.

## 1.1.5

**Installing production no longer means editing a file.**

```bash
git clone https://github.com/rorymeijer/ticktz.git
cd ticktz
./scripts/install.sh
```

The script writes the two passwords the bundled MySQL needs and starts the
stack; the setup wizard in the browser does the rest and writes `.env` itself.
Those two exist because the MySQL container is handed its password at the
moment it is *created*, before any application exists to ask for one — the one
genuine chicken and egg in the setup. The script writes nothing else on
purpose: a value in `.env` reaches the container as an environment variable, and
Laravel leaves existing environment variables alone, so anything planted up
front silently outranks what the wizard writes later. Running it twice never
replaces a value already there. `tests/Shell/install.test.sh` covers it, in CI.

**A production instance could come up on sqlite.** `DB_CONNECTION`,
`SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` came only from `.env`,
and Laravel's own fallback for the first of them is `sqlite`. An instance whose
`.env` did not name it started on a file, with a synchronous queue, beside the
MySQL and Redis it was supposed to be using — and said nothing.

They are now set by `docker-compose.prod.yml`, which is where they belong: the
file ships those services, so which drivers to reach them with is not an
opinion. Each is still `${VAR:-default}`, so pointing at your own database or an
external Redis works exactly as before. A `.env` naming them explicitly — every
one written before this release — is unaffected.

The repeated per-service environment blocks that caused it are gone too. A
service's own `environment:` replaces the base's rather than merging into it, so
every key had to be restated in both the app and the worker; they now merge one
map, and a setting added in one place reaches both.

**The production stack's default image exists.** It was `ticktz/app:latest`,
which is nobody's repository on Docker Hub, so the `docker compose pull` the
upgrade instructions call for answered with *pull access denied … or may require
docker login* — an error that reads like a login problem and is not one. The
default is now `ghcr.io/rorymeijer/ticktz:latest`, which is what the release
workflow publishes.

## 1.1.4

**`docker compose up -d` now means the application is ready.** The development
stack's `app` service had no health check, so `up -d` returned as soon as the
container had *started* — while the entrypoint was still running the migrations
and seeding the demo desk. A command run at that moment found tables that were
not there yet:

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'ticktz.permissions' doesn't exist
```

It now carries the same check the production stack has had since 1.1.1: php-fpm
is the last thing the entrypoint starts, so "is anything listening on 9000" is
exactly the question "is the first boot finished". nginx waits for it, and
`docker compose up -d --wait` waits for it too.

**And the quick start no longer tells you to seed a desk that seeds itself.**
The development stack has always set `TICKTZ_SEED_DEMO`, so
`php artisan ticktz:demo` straight after `up -d` was both unnecessary and the
most likely way to hit the race above. The README says what actually happens on
a first boot instead.

## 1.1.3

**Separates the development stack from the production one.** Both compose files
declared `name: ticktz`, and a Compose project's name is its identity — so the
two files were two descriptions of a single stack. Bringing one up replaced the
other's containers, and a `docker compose` command without `-f` then reached
whichever had gone up last. The symptom that finally explained it: `php artisan
ticktz:demo` refused to seed, "in production", run from a checkout whose `.env`
said `APP_ENV=local`. It was talking to the production container, where the
compose file sets `APP_ENV: production` outright.

The development stack is now `ticktz-dev`. Production keeps the plain name, so
a deployed instance is untouched by the upgrade. Its `vendor` volume is now
`ticktz-dev_vendor`.

A development stack started before this still carries the old name, and
`docker compose down` no longer reaches it — it looks for `ticktz-dev` now, so
the old containers stay up and the first `up` fails on a port they are still
holding. Name the old project once:
`docker compose -p ticktz -f docker-compose.yml down`. That stops a production
stack running alongside as well — same project, same service names, same
containers — so start it again afterwards. See
[self-hosting](docs/self-hosting.md#when-something-is-wrong).

**Stops a compiled config from outliving the boot that wrote it.** The
compiled config lives in the code volume, so an instance that once came up
without an `APP_KEY` kept answering from a snapshot saying there was none —
`printenv APP_KEY` showing the key while `config('app.key')` reported nothing,
with neither answer explaining the other. The entrypoint now drops it before
anything reads it, and production compiles a fresh one once the environment is
settled.

## 1.1.2

**Fixes an instance that comes up with no APP_KEY.** 1.1.1 correctly stopped
copying `.env` into the image — it was carrying whoever's database password
built it — and that broke the key generation which had been relying on it. The
entrypoint's guard was `[ -f .env ]`, satisfied only by that accident, so an
instance with no `APP_KEY` in its environment now generated none and answered
every page with `MissingAppKeyException` behind an empty 500.

Generating it moved into the placement script, under the same lock that guards
the code, because two containers generating different keys is worse than either
generating none: `APP_KEY` encrypts the mailbox passwords in the database, so a
second key makes the first one's data unreadable. It is written once and never
changed.

It is a fallback, not the arrangement to prefer. **Set `APP_KEY` in your own
`.env`** — compose injects it — so the key is backed up with your other
settings rather than living in the code volume and going with it.

**Fixes an empty 500 on every page that renders something.** The entrypoint
runs as root and php-fpm as `www-data`, and since 1.1.0 put the code at
`/usr/src/ticktz` there is no `/var/www/html/storage` in the image for Docker
to take ownership from — so a fresh `storage` volume is created owned by root
and the web server cannot write to it.

The symptom points nowhere near the cause: redirects work and pages do not.
Laravel compiles every Blade view into `storage/framework/views` on first
render, so `GET /` answers 302 quite happily and the first page that renders
anything returns 500 with an empty body, with no permission named in any log.

The entrypoint now hands `storage` and `bootstrap/cache` to `www-data` on every
boot, which also repairs a volume created by 1.1.0 or 1.1.1. Doing it by hand
on those versions: `docker compose exec app chown -R www-data:www-data storage
bootstrap/cache`.

## 1.1.1

**Fixes a Docker first boot that could end in a crash loop.** The app and the
worker mount the same code volume and start at the same time, and both ran the
placement — two `rm -rf vendor && cp -a` over each other, leaving a vendor
missing files and php-fpm dying on them. nginx answered 502.

Worse, it could not recover: the marker recording which image placed the code
was written when the copy started rather than when it finished, so every
restart read `1.1.0`, concluded there was nothing to do, and crashed again on
the same file.

Three changes. Placement takes a lock, so one container does it and the others
wait. The marker is written only after the copied tree is verified to load its
own autoloader, and a tree that claims to be placed but cannot load is replaced
rather than trusted. And nginx now waits for php-fpm to accept connections
instead of merely for the container to exist, which closes the window where a
first boot answers 502 while it is still copying.

Recovering an instance already stuck this way is in
[`docs/self-hosting.md`](docs/self-hosting.md).

**Adds the `.dockerignore` that should always have been there.** `docker build`
copies the directory you build from, not what is committed, so everything
gitignored but present on disk went into the image:

- **`.env`** — your database password, `APP_KEY` and mail credentials, baked
  into an image you might push to a registry. Rotate those if you have pushed
  an image built before this.
- **`public/hot`** — written by the Vite dev server, and it makes Laravel point
  every browser at `http://localhost:5173` for its JavaScript. The page comes
  up white with nothing in any server log to explain it. If you are seeing that
  now, `docker compose exec app rm -f public/hot` fixes it immediately.
- **`vendor`** — and this one is worse than size. In the build, `COPY . .` runs
  *after* `composer install --no-dev`, so a local vendor directory overwrote
  the clean production one. Images built from a working checkout shipped the
  development dependencies.

The image also strips `public/hot`, `.env` and `bootstrap/cache` itself, so the
two ways of shipping Ticktz produce the same tree. CI plants all three in the
build context before building and fails if any reaches the image — a guard that
cannot fail guards nothing.

## 1.1.0

**Upgrade from the browser.** Administration → Updates shows which version this
desk runs, whether a newer one is published, and — on a source install — a
button that installs it. Both halves are off until you switch them on, and they
are separate switches: checking GitHub is one decision, letting the instance
replace its own code is another. Nothing is ever installed on a schedule. See
[`docs/self-hosting.md`](docs/self-hosting.md).

The web request never writes a file. It creates a row naming a published
release, and the queue worker does the work — because a web-facing PHP process
must never be able to write the application's own code, and a service desk
takes uploads from anybody with an e-mail address. The readiness checks on the
screen are how you find out whether your deployment keeps those two apart.

The previous version is kept rather than deleted, `.env` and `storage` are
never touched, and a move that fails puts everything back. Docker installs are
shown the two commands instead: recreating a running container needs the Docker
daemon, and a web process must never be able to reach it.

`php artisan ticktz:upgrade` does the same thing from a terminal, which is also
the answer for an install with no worker running.

**Docker installs can upgrade themselves too.** The production stack now puts
the application code on a `code` volume shared by the app, the worker and
nginx, because the worker does the upgrade and the app has to be able to serve
what it wrote — a container's own writable layer is invisible to its sibling
and discarded on the next `up -d`. The image stays the source of truth: it
carries its code at `/usr/src/ticktz` and the entrypoint copies it into the
volume whenever the image is the newer of the two, so `docker compose pull`
still works and a version installed from the browser is left alone. A
bind-mounted source tree is never touched. See
[D67](docs/decisions.md) for the trade this makes.

**Upgrading an existing Docker install to 1.1.0** is the usual `docker compose
pull && docker compose -f docker-compose.prod.yml up -d --build`. The new
`code` volume is created and filled from the image on that first boot; the old
`public` volume is no longer used and can be removed once you are happy.

New permission: `updates.manage`. It is not `settings.manage` — replacing the
application's code is a different kind of act from changing a preference.

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
