# Automation

Rules that act on tickets without anybody asking. A rule is a trigger, some conditions and some actions: *when* this happens, *if* these are true, *do* this.

## Triggers, conditions, actions

A **trigger** is the moment a rule is considered — a ticket created, updated, commented on, or a scheduled sweep.

**Conditions** narrow it. Field comparisons — priority is high, queue is Hardware, assignee is nobody — combined with all or any.

**Actions** are what happens: assign to somebody, move to a team, set a priority, add a label, transition, add a watcher, send a webhook, or send one of the desk's reply templates.

For anything your agents also send by hand, prefer *Send a reply template* over typing the text into the rule. The template is one row that both the rule and the reply box read, so the wording cannot end up with two versions — see **Reply templates**.

## Rules to write rules by

**Start with the conditions.** A rule with no conditions runs on everything, and the way you find out is the audit trail filling with it.

**Make the first one narrow enough to watch.** Every run is recorded with what it did and whether it worked. Watch a new rule for a day before widening it.

**Order matters.** Rules run in order and can see what earlier ones did. Two rules that both set the priority will both run; the last one wins.

**Assignment obeys the team rule.** A rule assigning somebody who is not on the ticket's team does not do it, and records why. That is deliberate: a rule that could bypass the rule would be the way round it, and the quietest one — it happens at three in the morning and the trail says no person did it.

## Watching them

The execution list shows what ran, on which ticket, and what each action did. An action that could not do anything says so rather than claiming success — "no such active user", "not on this ticket's team", "not allowed by the workflow".

A rule that never fires and a rule that fires and fails look completely different here, which is the point.

## Webhooks

A webhook posts to a URL you control when something happens, which is how Ticktz tells another system about a ticket.

The URL must be https. Add a secret and the payload is signed, so the far end can tell your Ticktz from anybody else who found the address.

Failures are retried. A URL that is down for ten minutes does not lose the event.
