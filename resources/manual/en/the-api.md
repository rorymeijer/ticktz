# The API

Ticktz has a REST API for the things that should not be done by hand: a monitoring system raising tickets, a script closing a batch, another system reading what is open.

## Tokens

**Settings → API tokens** is where you mint one. A token acts as *you*: it can do what you can do and no more, so a token belonging to somebody who cannot delete tickets cannot delete tickets either.

Two things about tokens worth taking seriously:

- **You see it once.** It is shown when it is created and never again. Store it where the thing using it will find it, immediately.
- **Give it the narrowest scopes that work.** A token that only files tickets should have only `tickets.write`. A scope is a second limit on top of your permissions, not a replacement for them — a token is the intersection of what it was issued for and what its owner may do.

Revoke a token the moment whatever used it is retired. An unused token is a credential nobody is watching.

## What it can do

Tickets can be read, filed, updated and moved through their workflow; comments can be read and posted. The full description — every endpoint, every field, every error — is in `docs/openapi.yaml` in the repository, which is the document to work from rather than this page.

Two behaviours worth knowing before you write against it:

- **Every field on an update is optional**, and only what you send is touched. Reading a ticket, changing one field and sending the whole object back cannot overwrite what somebody else changed in between.
- **Status is not editable directly.** It goes through the transition endpoint, which respects the workflow. That is deliberate: a script that could set any status could close a ticket with no SLA record and no trace of why.

## Rate limits and errors

Requests are rate-limited per token. Errors come back in one shape, with a code you can branch on rather than a message you would have to match.

An assignment refused because the person is not on the ticket's team looks like any other validation error, and says so in the field. Scripts that assign should expect it.
