# Running the system

## Settings

The name, the logo and the colours the portal and the notifications wear, plus the defaults a new ticket gets when nothing else decides.

Most of this is set once. The one worth revisiting is the default queue and request type: they are where anything that matched nothing lands, and a desk that has grown has usually outgrown its first answer.

## Updates

**Administration → Updates** checks whether a newer Ticktz exists, and — on an installation that allows it — installs one.

Before you press it:

- **Take a backup.** `scripts/backup.sh` is in the repository. An upgrade runs migrations, and migrations are the thing you cannot undo by putting the old code back.
- **Read the release notes.** A minor version never needs a manual step and a patch never changes the database, so what you are looking for is whether this is a major one.

The update screen checks what it can before starting — that the code can be written to, that the database is reachable, that there is room for the new version beside the old one — and refuses rather than half-installing. If it refuses, the reason it gives is the thing to fix.

The previous version is kept, so a failed migration has somewhere to go back to.

## The audit log

Everything that changed a ticket, who changed it and when. Field edits, assignments, transitions, label changes, deletions.

It exists for two moments: working out what happened to one ticket, and answering a question about what somebody did. Filter by actor type to separate what people did from what automation and inbound e-mail did — that is usually the first cut worth making.

Entries are not editable. That is the point of them.

## Health

The desk depends on three things being alive: the database, the cache, and the queue worker. The worker is the one that fails quietly, because everything still loads — but mail stops going out, webhooks stop firing, SLA breaches stop being noticed and scheduled rules stop running.

If notifications have stopped and the interface seems fine, check the worker first.
