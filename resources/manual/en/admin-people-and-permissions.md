# People and permissions

Who can sign in, and what each of them can do.

## Roles carry permissions, people carry roles

Nobody is given a permission directly. A **role** is a named bundle of permissions — "Agent", "Team lead", "Read-only" — and people are given roles. Change what a role can do and everybody holding it changes with it.

A role has a **scope**, and the scope decides which side of Ticktz its holders land on. An agent- or admin-scoped role makes somebody staff; a requester-scoped one makes them a customer.

The permission list itself is fixed — it is defined in the code, not built in the interface — so a permission cannot be created by typo, and every one of them is something the application actually checks.

## Adding somebody

**Administration → Users → New user.** The things worth deciding deliberately:

- **Roles.** This is the whole of what they can do. Give the narrowest role that lets them work.
- **Organisation**, for a customer. It is what makes "everybody at Acme" a meaningful group, and it drives whether colleagues can see each other's requests.
- **Manager.** Used by approval steps that ask for "the requester's manager". Without it, those steps have nobody to ask.

Somebody who signs in through your directory is created on first sign-in; you do not add them by hand.

## Deactivating rather than deleting

Deactivate somebody who has left. It stops them signing in and takes them out of the pickers, and it keeps every ticket, comment and decision they ever made intact and attributed.

Deleting a person who has worked tickets would leave a desk full of work nobody appears to have done.

## Teams

A team is a group of agents that owns queues and tickets. Teams are how work is divided, and they are load-bearing in one specific way: **a ticket that belongs to a team can only be assigned to somebody on that team.**

That is worth knowing before you build them. A team that is too narrow produces tickets nobody can be given.

**Leads** are members with a flag. Escalations and reports use it.

## Organisations

An organisation groups customers — a company, a department, a site.

The one setting that changes behaviour is **shared ticket visibility**. Turn it on and colleagues in the same organisation can see each other's requests in the portal; leave it off and each person sees only their own. It is the right setting for a shared mailbox and the wrong one for an HR desk.

## Directory sign-in

Connecting a directory means people use their existing password and you stop maintaining a second list. Ticktz reads accounts and groups; it does not write to your directory.

Test the connection before you rely on it. A directory that authenticates but returns no groups produces people who can sign in and do nothing.
