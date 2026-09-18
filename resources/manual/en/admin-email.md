# E-mail

Two halves that are configured separately: what Ticktz sends, and what it reads.

## Sending

Set the SMTP server Ticktz sends through, and the address it sends as.

**Templates** are what goes out: a ticket created, a reply posted, an approval requested, an SLA breached. Each is editable, and each carries placeholders for the ticket's own details.

Two things that repay care:

- **The reply-to address must be a mailbox Ticktz reads.** That is what makes replying to a notification land on the ticket instead of in a void.
- **Send yourself one of each** before going live. A template with a broken placeholder looks fine in the editor.

## Reading

An **e-mail channel** is a mailbox Ticktz polls. Mail arriving in it becomes tickets, and replies to existing tickets become comments on them.

Per channel you set which queue and request type incoming mail lands as, so a desk can run `support@` and `facilities@` into different queues from one Ticktz.

**Auto-provisioning** decides what happens when mail arrives from an address with no account. On, and a requester account is created so they can follow their request in the portal. Off, and the ticket is created without one. On is usually right for an internal desk; consider it carefully for an address the public can reach.

## How a reply finds its ticket

By a marker Ticktz puts in the mail it sends, not by the subject line. That is why a reply still finds its ticket after somebody has edited the subject, and why forwarding an old notification can attach a new message to an old ticket.

Processing is **idempotent**: the same message arriving twice does not make two tickets. That matters more than it sounds, because a mailbox that fails halfway through a poll gets retried.

## When mail is not arriving

In order:

1. **Poll now**, on the channel. It reports what it found.
2. Check the channel is enabled and the credentials still work — an expired app password is the usual answer.
3. Look at whether the messages are unread in the mailbox. Ticktz reads unread mail; something else marking it read first takes it away.
