# Reporting

Four questions, one period, and a CSV of whatever is behind them.

| Report | Answers |
| --- | --- |
| SLA compliance | Of the clocks that finished, how many were met |
| Ticket volume | How much arrived, and how much the desk cleared |
| Agent workload | Who resolved what, and how long they took |
| How long it takes | Time to a first reply, and time to a resolution |

## The pipeline

Everything the dashboards draw comes out of `report_daily_metrics` — one row
per day, per metric, per dimension value. The alternative is aggregating the
ticket table on every page load, which is fine at a thousand tickets and
unusable at a million, and which puts a full-table scan behind a screen people
leave open all day.

```
   tickets · sla_timers
        │
        │  MetricsCollector::rebuildDay()   one grouped query per metric
        ▼                                   per dimension — never a row walk
 report_daily_metrics
   date · metric · dimension · dimension_id · count · total
        │
        │  ReportBuilder                    sum over a range, group, divide
        ▼
   the four reports
```

**A day is recomputed, never incremented.** Counters bumped as things happen
drift the first time a queued job is retried, a ticket is edited straight in
the database, or a deploy lands mid-request — and nothing about a wrong counter
announces itself. A day rebuilt from its source rows is either right or
reproducibly wrong, and re-running it is always safe.

The rebuild replaces the day rather than upserting into it, so a metric that
produced rows yesterday and none today ends up with none. An upsert alone would
leave the old figures standing and the number would never go down.

### Schedule

| When | What |
| --- | --- |
| Every 15 minutes | Rebuild **today**, so the dashboards move during the day |
| 03:40 nightly | Rebuild the **last 7 days** |

The nightly pass is what catches a ticket resolved at 23:59, a backdated edit,
or a run the queue dropped. Because the rebuild is idempotent, doing it again
costs a query and fixes anything that drifted.

## Which day does a number belong to?

- **Volume** buckets on when it happened — created on its creation date,
  resolved on its resolution date. A ticket raised in September and resolved in
  October counts once in each.
- **Durations** bucket with the event that completes them.
- **SLA outcomes** bucket on the date the clock **finished**, not the date the
  ticket was raised. Bucketing by creation date means September's compliance
  figure keeps changing for weeks after September ends, which is no way to
  report a number to anybody.

## What counts as a breach

A breach is `sla_timers.breached_at`, not `status = 'breached'`.

The SLA engine stamps that column the moment a target passes and leaves the
clock running until the work is actually done, so a promise already broken on a
ticket still being worked has a breach date and a `running` status. Counting
only the finished ones reported a desk with visibly late tickets at 100%
compliance — exactly the kind of wrong number a dashboard is trusted never to
produce.

A clock that breached and was then finished late carries both columns. It is
one outcome, and it is a breach, counted on the day it broke.

## Averages

An average is `sum(total) / sum(count)` across the whole range — never the mean
of the daily averages. The second weights a quiet Sunday the same as a busy
Monday, and is wrong by an amount nobody can eyeball. That is why the rollup
stores a sum and a count rather than an average.

## `dimension_id` is 0, not NULL

MySQL treats NULLs as distinct in a unique index, so a nullable dimension column
would let the same `(date, metric, dimension)` be inserted any number of times
and the replace-then-insert this table depends on would quietly become an
append. Zero means "no dimension": the `all` rollup, and an unassigned ticket.

## Export

Two exports, because people want two different things and giving them one is
how a report ends up being re-derived in Excel anyway:

- **These figures** — the chart as numbers.
- **The tickets behind them** — the underlying rows, so somebody can pivot it
  themselves and answer the question this screen did not anticipate.

Both stream rather than being assembled in memory, both are chunked by id
rather than by offset (an offset walk re-scans everything it has already sent),
and both start with a UTF-8 BOM — Excel reads a CSV as the local codepage
otherwise, which turns every Dutch name in the export into mojibake.

The ticket export is held to `tickets.export` on top of `reports.view`:
exporting a year of tickets is exporting the desk's whole record of who asked
for what.

## Saved reports

A saved report stores its **filters**, not its figures. One that cached its
numbers would be a screenshot with a date on it, and the only thing anybody
wants from "SLA compliance, servicedesk, this month" is what it says today.

Sharing one puts it on everybody's reporting screen, so it needs
`reports.manage` — a change to other people's workspace rather than to your
own. A private report is the author's own bookmark.

## The charts

Hand-drawn inline SVG. The whole of it is a path and some text, and a charting
library would be larger than the rest of the front end put together.

The rules they follow, which are the ones that keep a dashboard honest:

- **Never two y-axes.** A first response is measured in minutes and a
  resolution in days, so they are two charts. Aligning two scales on one plot
  invents a correlation that is not in the data.
- **Status colours mean status.** Met and missed are a judgement, so they use
  the good/critical pair and never appear as "series 3"; created and resolved
  are identities, so they use the categorical slots in fixed order.
- **The palette is validated, not eyeballed** — colour-vision separation,
  lightness band, chroma floor and contrast against the white card, checked
  with a script rather than by looking.
- **A legend is always present for two or more series**, so identity is never
  carried by colour alone.
- **An axis whose labels repeat is a broken axis.** Small integer ranges get
  one tick per unit rather than five ticks rounding into each other.
- **One number is a stat tile, not a chart.** Compliance, clearance and the
  averages are figures; only the things that vary over time are plotted.

## Permissions

| Permission | Grants |
| --- | --- |
| `reports.view` | Open the reporting screen; save private reports |
| `reports.manage` | Share a saved report with everybody; delete anybody's |
| `tickets.export` | Additionally required for the ticket-level CSV |

Reporting reads the rollups rather than the ticket table, so it sits outside
`tickets.view`: a service owner who reports on the desk without working it
needs `reports.view` and nothing else.
