# The public API

A REST API for the things that surround a service desk: the monitoring system
that files a ticket at 03:00, the inventory sync that owns the truth about the
hardware, the intranet page that searches the knowledge base, the middleware
platform that wants to hear about SLA breaches.

The machine-readable contract is [`openapi.yaml`](openapi.yaml) — 16 paths, 22
operations, and a test that checks it against the real route table. This page
is the part a spec cannot carry: why it is shaped this way.

Base URL: `https://your-instance/api/v1`.

## A token is never more than its owner

The one idea everything else follows from.

A personal access token carries **scopes**. A scope is not a permission — it is
a narrowing of one. Every scope names the permissions its holder must already
have, and a request has to pass both checks:

```
tickets.write  →  requires tickets.create, tickets.update or tickets.transition
assets.write   →  requires assets.manage
kb.read        →  requires kb.view
```

So a token is the *intersection* of what it was issued for and what its owner
may do today. Two consequences worth stating plainly:

- **A token cannot be an escalation.** Ask for every scope in the catalogue and
  a requester's token still reaches only what a requester reaches. The UI does
  not even offer the scopes the person could not exercise, because a checkbox
  that mints a token which then 403s is a bug report waiting to happen.
- **Revoking a role revokes the tokens that leaned on it.** Take somebody's
  agent role away and every token they ever created narrows with it — including
  the ones everybody has forgotten about. Nobody has to go hunting.

Per-record authorisation still runs afterwards. Scopes decide which *endpoints*
a token may reach; the policies decide which *rows* it may see, using the same
query scope as the browser. There is no view of the data that is reachable by
token and not by the person.

### The scopes

| Scope | Lets a token | Needs |
| --- | --- | --- |
| `tickets.read` | read tickets and their conversations | `tickets.view` or `portal.submit` |
| `tickets.write` | file, edit and move tickets | `tickets.create` / `.update` / `.transition` |
| `comments.write` | reply and post notes | `tickets.comment` or `portal.submit` |
| `queues.read` | read queues | `tickets.view` |
| `assets.read` | read the CMDB | `assets.view` |
| `assets.write` | create and update assets | `assets.manage` |
| `kb.read` | read the knowledge base | `kb.view` |
| `webhooks.manage` | manage subscriptions | `webhooks.manage` |

`comments.write` accepts `portal.submit` deliberately. A requester replying to
their own ticket holds no `tickets.comment` permission — the policy lets them
through as a participant instead. Leaving `portal.submit` out would refuse the
most ordinary integration there is: a portal or chat bot posting a customer's
reply.

## Getting a token

*Settings → API tokens*, which anyone with `api.tokens.manage` can reach
(admins and agents by default). Give it a name that says what it is for —
a list of eight tokens called "token" is a list nobody dares revoke anything
from — pick its scopes, optionally give it its own rate limit and an expiry.

The plaintext is shown **once**, in the dialog that follows. Only a SHA-256
hash is stored, so it genuinely cannot be recovered; the honest alternative to
"show me that again" is minting a new one.

Tokens look like `17|ticktz_xxxxxxxx…`. The prefix is what makes a leaked token
recognisable — secret scanners key off exactly that, and so does a person
staring at a pasted config file.

```bash
curl -s https://your-instance/api/v1/me \
  -H "Authorization: Bearer 17|ticktz_..."
```

`/me` is the first call to make. It answers with the token's scopes, the
permissions behind them and the rate limit in force, which turns a `403` from
"it does not work" into a specific missing scope or a missing permission. It
sits outside the scope middleware on purpose: a token with no usable scopes
still has to be able to find that out.

## Filing a ticket

```bash
curl -X POST https://your-instance/api/v1/tickets \
  -H "Authorization: Bearer $TICKTZ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "subject": "Disk usage above 90% on db-01",
        "description": "/var is at 94%. Triggered at 03:14 UTC.",
        "priority_id": 2
      }'
```

Nothing about this bypasses the desk. The request goes through the same
service the agent console and the mail poller use, so the ticket gets its key,
its opening status from the workflow, its SLA clocks, its watchers, its audit
entry and its automation run exactly as one filed by hand. An API that
reimplements any of that is an API that drifts from the UI, and then people
stop trusting whichever one they are not looking at.

Two things the caller does not get to decide:

- **`source` is always `api`.** Where work comes from is a number the desk
  reports on, and a caller that can claim to be the portal makes it
  meaningless.
- **The opening status.** A new ticket starts where its workflow says it
  starts. Letting a caller pick would let a ticket be created already resolved,
  with no clock and no trace of why.

Omit the requester and the ticket is filed for the token's owner — what a
script filing its own tickets wants. Naming somebody else (`requester_id` or
`requester_email`) needs the `tickets.create` permission; without it this is
impersonation with an audit trail pointing at the wrong person.

## Rich text

Ticket descriptions and comment bodies are rich text: an allowlisted subset of
HTML, sanitised on the way in and therefore safe to render as markup without
doing anything to it first.

Every one of them comes back twice:

| Field | What it is |
| --- | --- |
| `description`, `body` | The markup as stored |
| `description_text`, `body_text` | The same content with the tags taken out |

Read whichever suits what you are building. A page rendering a ticket wants the
markup; a script grepping for a word, a chat relay, or anything with no room
for formatting wants the text.

**Writing is the forgiving direction.** Send markup and it is sanitised against
the allowlist — anything that could execute, load something or collect a
password is removed, not escaped. Send plain text and it is wrapped in the
paragraphs it was already implying, so a client written against 1.0.0 needs no
changes at all:

```json
{ "subject": "Disk almost full", "description": "/var is at 94%.\n\nTriggered at 03:14 UTC." }
```

comes back as:

```json
{
  "description": "<p>/var is at 94%.</p><p>Triggered at 03:14 UTC.</p>",
  "description_text": "/var is at 94%.\n\nTriggered at 03:14 UTC."
}
```

The one thing that changed in 1.0.1: a client that *reads* `description` or
`body` now gets markup where it used to get a sentence. That is why the text
fields sit beside them rather than replacing them — switch the field you read
and you are back where you were.

Asset notes and multiline custom fields work the same way. Everything else —
names, keys, e-mail addresses, short descriptions — is plain text and stays
plain text.

## Moving a ticket

Status is not a field on `PATCH`. It gets its own endpoint, because it is not a
field edit: the workflow decides which moves are legal, and an outstanding
approval can stand in the way of one.

```bash
curl -X POST https://your-instance/api/v1/tickets/SUP-1042/transition \
  -H "Authorization: Bearer $TICKTZ_TOKEN" \
  -d '{"status": "resolved", "comment": "Cleared the log partition."}'
```

Both refusals come back as `422` with the reason in `error.message`. That is
deliberate: the move is not allowed *yet*, which is a different thing from the
request being malformed, and a caller can show the message to a human.

The approval gate is enforced in the service, not in the controller — an
automation rule, an inbound e-mail and an agent's click all meet the same wall.
A gate the API can walk around is not a gate.

## Errors

One shape, whatever went wrong:

```json
{ "error": { "code": "missing_scope",
             "message": "This token was not issued with the tickets.write scope.",
             "required_scope": "tickets.write" } }
```

Laravel's defaults are reasonable per exception type and inconsistent taken
together — `{errors:…}` for validation, `{message:…}` for a 404, something else
for a 403. A caller then writes three handlers, or, more often, one that works
until it meets the second shape.

`code` is a stable string chosen by us, not an exception class name: renaming a
class must not break somebody's integration. Branch on it.

| Code | Status | Means |
| --- | --- | --- |
| `unauthenticated` | 401 | No token, an unknown one, or an expired one |
| `account_inactive` | 403 | The account behind the token is deactivated |
| `missing_scope` | 403 | The token was not issued this scope |
| `forbidden` | 403 | The owner lacks the permission behind the scope |
| `not_found` | 404 | No such record — *or* one you may not see |
| `validation_failed` | 422 | See `error.fields` |
| `transition_refused` | 422 | Illegal workflow move, or an approval outstanding |
| `rate_limited` | 429 | This token's per-minute limit is spent |
| `server_error` | 500 | Our fault. Logged, and deliberately not described |

A record you may not see answers `404`, never `403`. Telling them apart is a
way to enumerate the key space: `SUP-1` through `SUP-9999` is a short loop and
the answer is a map of the desk's volume.

Requests to `/api/*` always answer in JSON, even without an `Accept` header. An
integration that forgets the header should not receive an HTML error page it
cannot parse.

## Rate limiting

Per **token**, not per account. Two integrations owned by the same service
account are two callers, and one of them polling in a loop must not be able to
lock the other out — which is exactly what an instance-wide limit does, because
it cannot tell them apart.

A token may carry its own ceiling; without one it gets
`TICKTZ_API_RATE_LIMIT` (120/minute by default). Keying the bucket by token id
also means revoking a token frees its counter rather than leaving an exhausted
one behind for its replacement to inherit.

`X-RateLimit-Limit` and `X-RateLimit-Remaining` come back on every response.

## Pagination

Everything paginates. `?page=` and `?per_page=`, capped at 100 — a cap the
caller cannot raise by asking for a bigger number. An endpoint that returns
"all tickets" is fine on a desk with two hundred and an outage on one with two
hundred thousand.

## Webhooks

Polling an API to find out whether anything happened is how a quiet integration
becomes an expensive one. Register an endpoint instead, under *Admin →
Webhooks* or through `POST /webhooks`, and Ticktz posts events as they happen.

### Events

| Event | Fires when |
| --- | --- |
| `ticket.created` | a ticket is filed, from any source |
| `ticket.updated` | fields change |
| `ticket.assigned` | it moves onto or off somebody's plate |
| `ticket.transitioned` | the status changes |
| `ticket.commented` | a reply or a note is added |
| `sla.breached` | a target passes with the work undone |
| `approval.completed` | an approval is granted, refused or withdrawn |

These hang off the domain events every phase already dispatches, so a webhook
fires for a ticket created by an agent, by e-mail, by an automation rule and by
the API itself — with nothing to remember at each call site.

### The payload

One envelope for every event:

```json
{
  "event": "ticket.created",
  "id": "9f1c…",
  "occurred_at": "2026-02-03T09:14:22+00:00",
  "instance": "https://your-instance",
  "data": { "ticket": { "...": "..." }, "actor": { "...": "..." } }
}
```

`id` is the *event* id, not the delivery id: the same event fanned out to four
endpoints carries the same one, and every retry of a delivery reuses it. That
is what lets a receiver be idempotent, which it should be — at-least-once is
what a retrying sender gives you.

Two deliberate omissions:

- **Internal notes.** `ticket.commented` carries `is_internal`, and when the
  note is internal it carries no body. A webhook receiver has no policy to run
  and no user to check, so the only safe assumption is that whoever operates
  the endpoint is not entitled to the agent's side of the conversation.
- **What fields changed *to*.** `ticket.updated` names the changed fields and
  stops there. Anything more is a ticket export over a webhook; a receiver that
  needs the values can read the ticket with a token, if it is entitled to one.

Status slugs are sent, never display names. A receiver keying off "In
behandeling" breaks the moment somebody renames a status, and the point of a
slug is that it does not move.

### Verifying a delivery

Every subscription gets a signing secret, generated when it is created and
shown exactly once. Deliveries carry:

```
X-Ticktz-Event:     ticket.created
X-Ticktz-Delivery:  4711
X-Ticktz-Timestamp: 1770110062
X-Ticktz-Signature: sha256=<hmac>
```

The HMAC covers the **exact bytes of the body**, not the fields, so the check
is one line on the receiving side and cannot drift from what was actually sent:

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

if (! hash_equals($expected, $request->header('X-Ticktz-Signature'))) {
    abort(401);
}
```

```python
import hmac, hashlib
expected = 'sha256=' + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, request.headers['X-Ticktz-Signature']):
    return 401
```

Use a constant-time comparison (`hash_equals`, `compare_digest`). A plain `===`
on a signature is a timing oracle.

`X-Ticktz-Timestamp` is there for receivers that want replay protection.

The URL must be `https` outside local development. A signed payload is still a
ticket's subject and a requester's e-mail address crossing the network in the
clear — signing proves who sent it, not that nobody read it.

### Retries, and the log

A delivery row is written **before** the request is made, not after. That
ordering is the point: an event that was accepted but whose queue never ran it
still leaves a `pending` row, so "we never got it" has an answer. Writing the
row inside the job would mean the deliveries that vanish are exactly the ones
with no trace.

Failures retry four times with a growing backoff — roughly a minute, then five,
then twenty. Long enough for a deploy to finish, short enough that a recovered
endpoint gets the event while it is still worth having. A `4xx` is retried too:
an endpoint answering `401` during a credential rotation is the common case.

Each subscription gets its own job, so a slow endpoint never holds up a fast
one and a retry against a dead host never re-delivers to the three that already
succeeded.

After **20 consecutive failures** a subscription switches itself off. An
endpoint that has been gone all week is not coming back inside a backoff, and
every attempt against it is a queue slot another subscription could have used.
It stays in the list, marked disabled with its failure count, so somebody can
see what happened and turn it back on — which also clears the counter, or it
would trip again on the next single failure.

*Admin → Webhooks* shows the recent deliveries with the response and the error
text. That screen exists because nobody opens this page to admire a list of
URLs; they open it because somebody's system did not hear about a ticket.

## Syncing an inventory

The endpoint most instances will actually automate. `PUT /assets/tag/{tag}`
creates the asset if the tag is unknown and updates it if it is not:

```bash
curl -X PUT https://your-instance/api/v1/assets/tag/LAP-0042 \
  -H "Authorization: Bearer $TICKTZ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "ThinkPad X1", "asset_type_id": 3, "serial_number": "PF-9X2K"}'
```

Addressing by tag rather than by id makes the call idempotent without the
caller tracking our identifiers — so a sync script can run twice, or run again
after a restore, and end up in the same place. `201` means the tag was new,
`200` means it existed, which is how a script reports "added 3, updated 112"
without diffing anything itself.

`GET /asset-types` gives the slugs, so a script can map its own vocabulary onto
this instance's without an administrator reading ids out of the database.

## A worked example

An external script, end to end — the phase's acceptance criterion as a shell
session:

```bash
export TICKTZ_TOKEN="17|ticktz_..."
export TICKTZ_URL="https://your-instance/api/v1"

# What may this token do?
curl -s "$TICKTZ_URL/me" -H "Authorization: Bearer $TICKTZ_TOKEN" | jq '.data.token.scopes'
# ["tickets.read","tickets.write","comments.write"]

# File a ticket.
KEY=$(curl -s -X POST "$TICKTZ_URL/tickets" \
  -H "Authorization: Bearer $TICKTZ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"subject":"Nightly backup failed","priority_id":2}' | jq -r '.data.key')

# Add context.
curl -s -X POST "$TICKTZ_URL/tickets/$KEY/comments" \
  -H "Authorization: Bearer $TICKTZ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"body":"pg_dump exited 1: no space left on device"}' > /dev/null

# Resolve it once the job has been rerun.
curl -s -X POST "$TICKTZ_URL/tickets/$KEY/transition" \
  -H "Authorization: Bearer $TICKTZ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"resolved","comment":"Rerun succeeded after clearing WAL archives."}'
```

A token without `tickets.write` gets `403 missing_scope` on the second call and
creates nothing.

## Configuration

| Variable | Default | What it does |
| --- | --- | --- |
| `TICKTZ_API_RATE_LIMIT` | `120` | Requests per minute for a token with no ceiling of its own |
| `TICKTZ_API_TOKEN_MINUTES` | *(unset)* | Instance-wide expiry for tokens issued without one |
| `SANCTUM_TOKEN_PREFIX` | `ticktz_` | Prefix on the plaintext, for secret scanners |
| `TICKTZ_QUEUE_WEBHOOKS` | `webhooks` | Queue the delivery jobs run on |

The webhook queue is separate so a dead endpoint's backoff can never starve SLA
breach detection. Make sure a worker is consuming it:

```bash
php artisan queue:work --queue=high,default,mail,webhooks,low
```

On the `sync` driver — a demo instance, a seeder, a developer with no worker —
a failing webhook is reported and dropped rather than thrown. It would
otherwise travel back up through the listener into whatever raised the event,
and a ticket that cannot be filed because somebody else's server is down is a
far worse failure than a webhook nobody received. The delivery row records what
happened either way.
