# E-mail

Ticktz talks to requesters over e-mail in both directions: it sends
notifications when something happens to a ticket, and it turns replies — and
new messages — back into tickets and comments.

Everything on this page is configured in **Administration → Mailboxes**
(`/admin/email`), which requires the `email.manage` permission.

## How a conversation flows

```
requester writes to servicedesk@example.org
        │
        │  IMAP poll (every minute, or on demand)
        ▼
  inbound_messages ── claimed on a unique (channel, hash) index
        │
        ├── first time  → ticket created / comment appended
        └── redelivery  → dropped, nothing created
        │
        ▼
  TicketCreated / TicketCommented event
        │
        ▼
  SendTicketNotifications (queued, "mail" queue)
        │
        ▼
  SMTP out, with Message-ID  ticktz.SUP-1042.17.9f2a@example.org
        │
        ▼
  requester replies, quoting that Message-ID in In-Reply-To
        └────────────────────────────────── back to the top
```

## Mailboxes

A mailbox (`email_channels`) is one address and everything that hangs off it.
You can run several: `servicedesk@`, `facilities@` and `hr@` can each route to
a different team with a different default priority.

| Setting | What it does |
| --- | --- |
| Address | What replies are sent from, and the inbox that is read |
| Sender name | The display name on outgoing mail |
| Reply-to | Optional, when replies should go somewhere else |
| SMTP block | Leave the host blank to use the application default from `.env` |
| IMAP block | Switch on "read this mailbox" to turn messages into tickets |
| Folder to read | `INBOX` unless your server sorts mail first |
| Move processed mail to | Optional. Blank means "mark as read and leave it" |
| Delete after processing | Off by default; a processed message is evidence |
| Routing | The queue, team, request type and priority inbound mail lands on |
| Create an account for unknown senders | Off means mail from an unknown address is dropped |
| Ignore mail from | Glob patterns, one per line, e.g. `*@newsletter.example` |

Passwords are encrypted at rest, never sent to the browser, and an empty
password field on save means "keep the stored one" rather than "clear it".

### Testing a mailbox

**Send a test e-mail** delivers a message to your own address through this
mailbox's SMTP settings, and reports the transport error verbatim if it fails.
**Check for mail now** polls the IMAP side immediately instead of waiting for
the scheduler, and reports how many messages were fetched and processed.

## Notifications

Six notifications ship with the product:

| Key | Goes to |
| --- | --- |
| `ticket.created.requester` | The requester — their copy of the record |
| `ticket.created.agent` | The assignee and watching agents |
| `ticket.replied.requester` | The requester, on a public agent reply |
| `ticket.replied.agent` | Agents, on any reply including internal notes |
| `ticket.assigned.agent` | The new assignee, when work is handed over |
| `ticket.resolved.requester` | The requester, with the closing comment |

Each is written in the *recipient's* language, not the language of whoever
triggered it.

### Templates

Every notification has packaged NL and EN text, so a fresh instance sends
something sensible with nothing configured. Override any of them per mailbox
and per language under **Notification templates**. Resolution order:

1. this mailbox, this language
2. this mailbox, the fallback language
3. instance-wide, this language
4. instance-wide, the fallback language
5. the packaged default

Removing an override falls back one step; nothing ever stops sending.

Templates are plain text with `{{ placeholder }}` tokens — **not** Blade, and
nothing in them is evaluated (see [D12](decisions.md)). A token that is not in
the list below renders as nothing rather than appearing in someone's inbox.

```
ticket.key            ticket.subject         ticket.description
ticket.status         ticket.priority        ticket.queue
ticket.portal_url     ticket.agent_url
requester.name        requester.first_name   requester.email
assignee.name         recipient.name         recipient.first_name
comment.body          comment.author         app.name
```

## Inbound processing

### Which ticket a message belongs to

1. A Message-ID Ticktz generated, quoted in `In-Reply-To` or `References` —
   the ticket key is inside it, so this survives a rewritten subject line.
2. A `[KEY]` in the subject.
3. Any earlier message in the same thread.

No match means a new ticket.

### What never becomes a ticket

- Senders matching the mailbox's ignore list.
- Bounce handlers and no-reply addresses (`mailer-daemon@*`, `postmaster@*`,
  `no-reply@*`, `noreply@*`, `donotreply@*`, `bounce*@*`) — always, on every
  mailbox.
- Anything carrying `Auto-Submitted`, `X-Autoreply`, `X-Autorespond`, or a
  `Precedence` of `bulk`, `auto_reply` or `junk`.
- Mail from an unknown sender when auto-provisioning is off.
- Mail from a deactivated account.
- An empty message.

Everything Ticktz sends carries `Auto-Submitted: auto-generated` and
`X-Auto-Response-Suppress: All`, so other systems can extend the same courtesy.

### Duplicates

A mail server will hand you the same message twice. Ticktz claims the message
before it processes it, on a unique index, so a redelivery creates nothing —
see [D13](decisions.md). Messages are flagged on the server only after the
database says they are handled, so a crash costs a re-fetch, never a duplicate
ticket.

### Replies

An inbound reply is always a **public** comment; there is no way to write an
internal note by e-mail. The quoted thread is trimmed off the bottom
conservatively — only separators mail clients actually emit, because losing
what the customer wrote is worse than a little extra quoting.

A reply to a resolved ticket reopens it, bounded by the
`tickets.reopen_window_days` setting (14 by default; `0` disables reopening).

### The log

**Recent inbound mail** on the mailbox page lists every message the poller has
seen, processed or not, with the reason it was ignored and a link to the ticket
it became. That is where to look when a customer says they wrote and nothing
happened.

## Polling

The scheduler queues a poll for every active, readable mailbox once a minute.
The job is `ShouldBeUnique` per mailbox for 15 minutes, so a slow mailbox does
not stack up jobs behind it. Polling needs the worker container running:

```bash
docker compose ps worker
docker compose logs -f worker
```

## Development

The development stack runs [GreenMail](https://greenmail-mail-test.github.io/greenmail/),
which speaks both SMTP (3025) and IMAP (3143) — MailHog speaks only SMTP, which
would leave half of this page untestable (see [D14](decisions.md)).

| | |
| --- | --- |
| Web interface | <http://localhost:8025> |
| SMTP | `mail:3025` from inside the stack, `localhost:3025` from the host |
| IMAP | `mail:3143` from inside the stack, `localhost:3143` from the host |

Authentication is disabled and mailboxes are created on first use, so any
address works and any password is accepted. `php artisan ticktz:demo` seeds a
mailbox pointing at it.

To watch the whole round trip:

```bash
docker compose up -d --wait   # the first boot seeds the demo desk itself

# reply to a ticket in the agent console, then read it at localhost:8025
# reply to that mail from GreenMail, then:
docker compose exec app php artisan tinker
>>> app(App\Services\Mail\MailboxPoller::class)->poll(App\Models\EmailChannel::first());
```

## Configuration reference

| Variable | Default | Meaning |
| --- | --- | --- |
| `MAIL_MAILER` | `smtp` | Default transport when a mailbox has no SMTP host |
| `MAIL_HOST` | `mail` | Default SMTP host |
| `MAIL_PORT` | `3025` | Default SMTP port |
| `MAIL_FROM_ADDRESS` | — | Fallback sender when no mailbox is configured |
| `TICKTZ_QUEUES` | `high,default,mail,webhooks,low` | Queues the worker consumes; `mail` must be among them |

Per-instance settings live in **Administration → Settings**:

| Setting | Default | Meaning |
| --- | --- | --- |
| `tickets.reopen_window_days` | `14` | How long after resolution a reply reopens a ticket; `0` never reopens |

## Troubleshooting

**Nothing is sent.** Check the worker is running — notifications are queued on
the `mail` queue, and without a worker they sit there. Then use "Send a test
e-mail", which reports the transport error directly.

**Mail arrives but no ticket appears.** Look at the inbound log on the mailbox
page. A message listed as *Ignored* says why; a message that is not listed at
all was never fetched, which is an IMAP problem — use "Check for mail now" and
read the error.

**Replies create new tickets instead of threading.** The customer's client is
stripping `In-Reply-To` and the subject no longer carries `[KEY]`. Keep the key
in the subject of outgoing mail; the packaged templates do.

**Every reply creates a duplicate ticket.** It should not — that is exactly
what the claim on `inbound_messages` prevents. If you see it, the two messages
have different Message-IDs, which means something between the sender and the
mailbox is rewriting them.
