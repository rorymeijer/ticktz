# Service levels

A service level agreement in Ticktz is three things: a **calendar** that says
when the clock runs, a **policy** that says which tickets are covered, and
**targets** that say how long they get.

Everything here is configured in **Administration → Service levels**
(`/admin/sla`), which requires the `sla.manage` permission.

## The shape of it

```
business calendar        when does the clock run?
        │                  Mon–Fri 08:30–17:30, minus public holidays
        ▼
   SLA policy            which tickets does this cover?
        │                  queue / team / request type / organisation / priority
        ▼
     goals               how long do they get?
        │                  first response: 1 h  ·  resolution: 8 h
        │                  narrowed by priority and request type
        ▼
   sla_timers            one ticket's clock, with its own copy of the target
        │
        ▼
   sla_events            everything that happened to it
```

## Calendars

A target of "four hours" means four *working* hours. Where that lands depends
entirely on the calendar, so this is the first thing to get right.

| Setting | What it does |
| --- | --- |
| Timezone | Working hours are wall-clock times in this zone |
| Working hours | Per weekday, one or more blocks. Leave a day empty to close it |
| Holidays | Days the desk is shut; the clock does not run |

A day can have several blocks, which is how you model a lunch break:
`09:00–12:00` and `13:00–17:00`. Use `24:00` for midnight at the end of a day,
so a round-the-clock calendar is `00:00–24:00` on every day rather than an
interval that spills into tomorrow.

Ticktz ships two: **Office hours** (Mon–Fri 08:30–17:30, Europe/Amsterdam, with
the fixed-date Dutch public holidays) and **Around the clock**.

### Holidays

A **recurring** holiday repeats on the same date every year and ignores the year
it is stored under — Christmas, New Year's Day. The movable feasts (Easter and
everything hanging off it) shift, so they need a row per year and are not seeded.

### Daylight saving

Working hours are wall-clock times, so the desk still opens at 08:30 on the day
the clocks change. A working day is eight hours either side of the switch.

## Policies

A ticket takes the **first** policy whose conditions it matches, checked in
`position` order; the default catches everything else. So a narrow policy goes
above a broad one.

Conditions are a fixed set — queue, team, request type, organisation, priority —
rather than an expression language. A policy is read on every ticket creation,
and an administrator should not be able to write a condition the engine will
silently ignore. Anything genuinely arbitrary belongs in an automation rule.

Every condition you set must match. A condition left blank is not a test, so a
policy with no conditions matches everything.

## Targets

| Metric | Measured from | Stops when |
| --- | --- | --- |
| First response | The ticket is created | The first public reply from anyone but the requester |
| Resolution | The ticket is created | The ticket moves to a resolved or closed status |

A goal can name a priority, a request type, both, or neither. The most specific
match wins: priority **and** request type beats priority alone, which beats the
catch-all.

An internal note is not a first response. Neither is the requester writing
again.

### What happens when the promise changes

A timer keeps its own copy of the target. Editing a goal, or deleting the whole
policy, does not touch clocks that are already running — see
[D15](decisions.md). Changing what the *ticket* is (its priority, its queue)
does recalculate, measured from the original start so time already spent counts
against the new target, and it records a `recalculated` event.

## Pausing

A status flagged **pauses SLA** stops the clock. Out of the box that is
"Waiting for requester" and "Waiting for a third party"; any status can be
flagged in **Administration → Service desk**.

The pause is *paid back*: five working hours waiting on the customer push the
deadline five working hours out. Time spent waiting on someone else is not time
the desk owes.

A ticket that comes back from resolved gets a **fresh** resolution clock,
because a promise to fix it again is a new promise. The original is kept exactly
as it finished — met or breached on its own terms.

## Escalations

Each goal can carry escalation steps, at a percentage of the target. `100` is
the moment the target passes; above 100 is after it.

| Action | What it does |
| --- | --- |
| Notify | Adds the assignee, the team leads or the watchers to the ticket, which sends the usual notification ([D17](decisions.md)) |
| Raise the priority | One step up the ladder, recorded in the audit log |
| Hand over to a team | Moves the ticket, recorded in the audit log |

Each step fires **once** per ticket, however often the sweep runs.

That is the whole list, deliberately ([D18](decisions.md)). A breach also emits
a `SlaBreached` domain event, which the automation engine (phase 6) can act on
with arbitrary conditions — building a second rule engine inside the SLA feature
would mean two places to look when a ticket does something unexpected.

Out of the box only the urgent and high tiers escalate. Warning on every
normal-priority ticket at 75% trains agents to ignore the warning, which is
worse than not having one.

## The sweep

A breach is the absence of an event, so something has to go and look. A job
runs every minute from the scheduler:

1. Mark running clocks whose target has passed. They keep running — "two hours
   late" and "two days late" are different conversations.
2. Fire any escalation threshold that has been crossed.

It needs the worker container:

```bash
docker compose ps worker
docker compose logs -f worker | grep -i sla
```

To run one by hand:

```bash
docker compose exec app php artisan tinker
>>> app(App\Jobs\Sla\SweepSlaTimersJob::class)->handle(
...     app(App\Services\Sla\SlaEngine::class),
...     app(App\Services\Sla\SlaEscalator::class),
... );
=> ["breached" => 3, "escalated" => 1]
```

Running it twice is safe and does nothing the second time — see
[D16](decisions.md).

## What agents see

A **countdown badge** on the ticket list and on the ticket itself, coloured by
how much trouble the ticket is in: grey on track, amber within the hour, red
past the target, blue paused, green met. The remaining time is computed on the
server against the calendar, because the browser has no idea when the desk is
shut.

The **Service level panel** on a ticket shows both clocks with their targets and
due dates, the policy and calendar in force, and — under *Clock history* —
every start, pause, resume, escalation and breach. That history is what a
dispute about a breach is settled with.

The **filter bar** offers:

| Filter | Means |
| --- | --- |
| Breached | A clock on this ticket ran out |
| Due within an hour | Still on track, but not for long |
| On track | A clock is running and has time left |
| Paused | A waiting status has stopped the clock |
| Not measured | No policy claimed it, or every clock has finished |

Lists sort on SLA without joining a table per row: the tightest live clock is
mirrored onto the ticket as `sla_due_at` and `sla_breached`.

## Reporting

`sla_timers` and `sla_events` are the raw material for the SLA compliance
dashboard in phase 10. Both are append-friendly: timers are updated in place,
events are only ever inserted.

## Troubleshooting

**Nothing is being measured.** The ticket shows "No service level applies":
either no policy matched and there is no default, or the matching policy has no
goal for that priority. Check the policy list — a policy with no targets is
flagged.

**Breaches are never detected.** The sweep runs on the worker. Check
`docker compose ps worker`, and that `schedule:work` is running inside it.

**A deadline looks wrong.** It is almost always the calendar. Open the policy,
check which calendar it uses, and check that calendar's timezone: working hours
are wall-clock times in *its* zone, not the server's.

**A clock did not pause.** Pausing follows the status's **pauses SLA** flag, not
the status name. Check it in Administration → Service desk.

**An escalation did not fire.** Escalations only run on clocks that are still
live — a target that has already been answered does not escalate. Check the
clock history on the ticket: an escalation that fired is recorded there.
