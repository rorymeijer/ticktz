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

### Audit log
Every mutating action with a before/after diff, filterable by event and actor
type. Automation, inbound e-mail and API tokens are labelled as such.

![Audit log](screenshots/19-admin-audit-log.png)

## Agent & portal

### Agent dashboard
The agent shell. It fills up in phase 2, when tickets arrive.

![Agent dashboard](screenshots/20-agent-dashboard.png)

### Customer portal
Deliberately different chrome from the agent console: no sidebar, and only the
destinations a requester needs.

![Customer portal](screenshots/30-portal.png)

### Profile, in Dutch
Language is a per-user preference and applies to notification e-mail as well as
the interface.

![Profiel](screenshots/31-profile-nl.png)

### On a phone
Every screen is usable at 400px.

![Portal on a phone](screenshots/40-mobile-portal.png)
