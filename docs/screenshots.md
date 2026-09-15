# Screenshots

Captured from a demo instance (`php artisan ticktz:demo`) at 1440×900 on a
2× display. Regenerate them with:

```bash
php artisan migrate:fresh --force && php artisan ticktz:demo
npm run build
php artisan serve --port=8123 &
node scripts/screenshots.mjs
```

Each shot signs in through the real login form as the role it documents, so
what you see is what that role actually sees — including which navigation
items their permissions allow.

## Public

### Landing page
The only page an anonymous visitor sees. No webfonts, no analytics, no
third-party requests.

![Ticktz landing page](screenshots/01-landing.png)

### Sign in
One form for both account sources: a local password or a directory account.
The hint at the bottom only appears when a directory is configured.

![Sign in](screenshots/02-login.png)

### Sign in, in Dutch
Every string comes from `lang/{en,nl}`. The language switcher is available
before signing in, and a `?lang=` link renders in the language it promises.

![Inloggen](screenshots/03-login-nl.png)

## Administration

### Overview
Counts that link straight into the screen they describe, plus the tail of the
audit log.

![Administration overview](screenshots/10-admin-overview.png)

### Users
Server-side search and filters live in the query string, so a filtered list is
a shareable URL. "Source" distinguishes local accounts from directory ones.

![User administration](screenshots/11-admin-users.png)

### Creating a user
Roles and teams are assigned from the same form. The roles panel is hidden
entirely from an operator who holds `users.manage` but not `roles.manage` —
they can reset a password without granting themselves administrator.

![Creating a user](screenshots/12-admin-user-form.png)

### Roles & permissions
The three system roles ship with Ticktz. Their slug and landing shell are
fixed; what they may *do* is yours to decide.

![Roles](screenshots/13-admin-roles.png)

### Teams
Agents grouped into teams, with leads who may reassign work inside the team.

![Teams](screenshots/15-admin-teams.png)

### Organisations
Customer organisations claim new requesters by e-mail domain, and can share
ticket visibility between their members.

![Organisations](screenshots/16-admin-organizations.png)

### Directories (LDAP / Active Directory)
Bind settings, the attribute map and the group-to-role mapping, all editable
without touching `.env`. The bind password is write-only: it never comes back
to the browser.

![LDAP directories](screenshots/17-admin-directories.png)

### Settings
Instance-wide behaviour: portal branding, self-registration, ticket key prefix
and the resolve/reopen windows.

![Settings](screenshots/18-admin-settings.png)

### Service desk vocabulary
Statuses, priorities and labels on one screen. A status' *category* is what the
engine reasons about; the name is yours.

![Service desk configuration](screenshots/14-admin-service-desk.png)

### Workflow editor
Transitions as a matrix: tick a cell to allow moving from the row status to the
column status. Thirty possible moves in a six-status process are unreadable as
a list and obvious as a grid.

![Workflow editor](screenshots/14b-admin-workflow.png)

### Queue editor
A queue is a saved filter plus the columns it shows.

![Queue editor](screenshots/14c-admin-queue.png)

### Request types
What a requester can ask for, the form that asks it, and where the resulting
ticket lands.

![Request types](screenshots/14d-admin-request-types.png)

![Request type editor](screenshots/14e-admin-request-type-form.png)

### Custom fields
Define a field once, attach it to any form.

![Custom fields](screenshots/14f-admin-custom-fields.png)

### Audit log
Every mutating action with a before/after diff, filterable by event and actor
type. Automation, inbound e-mail and API tokens are labelled as such.

![Audit log](screenshots/19-admin-audit-log.png)

### Mailboxes
One row per address: what replies are sent from, what is read in, and where
inbound mail lands. Passwords are encrypted at rest and never reach this page —
the form shows whether one is stored, not what it is. Below it, the six
notifications with their packaged defaults, and the log of every message the
poller has seen including the ones it deliberately ignored.

![Mailboxes](screenshots/1a-admin-email.png)

### Mailboxes, in Dutch
Notification templates are overridable per mailbox *and* per language, and each
recipient is written to in the language they read.

![Postbussen](screenshots/1b-admin-email-nl.png)

### Service levels
Calendars decide when the clock runs; policies decide which tickets are covered;
targets decide how long they get. A four-hour target on a Friday afternoon
expires on Monday morning, which is what the calendar is for.

![Service levels](screenshots/1c-admin-sla.png)

### Service levels, in Dutch
![Serviceniveaus](screenshots/1d-admin-sla-nl.png)

## Agent & portal

### Agent dashboard
The agent shell.

![Agent dashboard](screenshots/20-agent-dashboard.png)

### Ticket list
Queues along the top are saved filters, so switching one just changes the query
string — the URL stays shareable. Sorting and filtering happen server-side.

![Ticket list](screenshots/21-agent-tickets.png)

### What is about to breach
The service level column is coloured by how much trouble the ticket is in, and
the filter bar finds the ones that are past their target, due within the hour,
or stopped waiting on somebody else.

![Breached tickets](screenshots/21b-agent-tickets-breached.png)

### A queue
The built-in "Unassigned" queue: open tickets nobody has picked up, oldest
first.

![Unassigned queue](screenshots/22-agent-tickets-queue.png)

### Queue overview
Every queue the agent may open, with a live count.

![Queue overview](screenshots/23-agent-queues.png)

### Ticket detail
The conversation, the system events, and the properties column where every
control posts immediately — an agent should never hunt for a save button to
reassign a ticket. The amber frame and lock mark an internal note: the cost of
mistaking one for a public reply is a note going out to the customer.

![Ticket detail](screenshots/24-agent-ticket-detail.png)

### Creating a ticket

![New ticket](screenshots/25-agent-ticket-create.png)

### The same list, in Dutch

![Ticketlijst](screenshots/26-agent-tickets-nl.png)

### Customer portal
Deliberately different chrome from the agent console: no sidebar, and only the
destinations a requester needs. Request types are grouped by category and
filtered as you type.

![Customer portal](screenshots/30-portal.png)

### The same portal, in Dutch
Request type names, categories *and* ticket statuses follow the reader's
language — they are configured data, so they carry per-locale overrides rather
than living in the language files.

![Klantportaal](screenshots/35-portal-nl.png)

### Submitting a request
The form is defined by data: whichever custom fields an administrator attached
to this request type are the fields that appear, get validated and get stored.

![Request form](screenshots/32-portal-form.png)

### My requests

![My requests](screenshots/33-portal-requests.png)

### Following a request
The requester's view of the same ticket an agent sees. The internal note from
the agent screenshot above is absent from this payload entirely.

![Request detail](screenshots/34-portal-request-detail.png)

### Profile, in Dutch
Language is a per-user preference and applies to notification e-mail as well as
the interface.

![Profiel](screenshots/31-profile-nl.png)

### On a phone
Every screen is usable at 400px.

![Portal on a phone](screenshots/40-mobile-portal.png)

![Ticket list on a phone](screenshots/41-mobile-tickets.png)
