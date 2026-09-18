# Shaping the desk

Queues, request types, statuses and workflows: the shape work moves through.

## Request types

A request type is a form plus a destination. It decides what is asked, and where the ticket lands.

Each one carries:

- **The questions.** Add the fields that are always needed. Every question you add here is a round trip you do not have later — and one more thing between somebody and asking for help, so add them for a reason.
- **A queue, a team and a workflow.** Tickets filed under this type go there.
- **An approval workflow**, if this kind of request needs signing off.
- **Whether it appears in the portal**, and under which category.

This is the single highest-leverage screen in Ticktz. Most of what a desk feels like to use is decided here.

## Custom fields

A custom field is defined once and attached to the request types that need it, so "cost centre" is one field asked by five forms rather than five fields that nearly match.

A field can be **private**, which means the desk sees it and the portal does not.

## Queues

A queue is a saved filter with a name, an order and optionally an owning team. Queues are how agents decide what to work on next, so build them the way your desk actually divides work rather than the way the org chart does.

A queue owned by a team is visible only to that team.

## Statuses and workflows

A **status** is where a ticket is. Each belongs to a category — new, open, pending, resolved, closed — and the category is what the rest of Ticktz reasons about, so a status called "Awaiting parts" in the pending category behaves like pending everywhere.

Two flags on a status matter:

- **Pauses the SLA clock** — usually the ones meaning "waiting on somebody else".
- **Counts as resolved or closed** — which drives reporting and automatic closing.

A **workflow** is which statuses can follow which. It is what makes the buttons on a ticket the moves that make sense rather than a list of everything.

A transition can **require a comment**, which is how you get a desk whose resolved tickets say what was done.

Start permissive and tighten. A workflow that forbids the move somebody needs at four o'clock on a Friday teaches people to work around Ticktz.

## Priorities and labels

**Priorities** are ordered and drive SLA targets and sorting. Keep the list short — a desk with seven priorities has two.

**Labels** are free-form tags for everything priorities are not: a project, a recurring fault, a supplier.
