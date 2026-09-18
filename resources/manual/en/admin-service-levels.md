# Setting up service levels

Three things, in this order: a calendar that says when the clock runs, a policy that says which tickets are covered, and targets that say how long they get.

## The calendar first

Get this right before anything else, because every target is measured through it.

A calendar carries a timezone, working hours per weekday, and the days you are shut. A day can have several blocks, which is how you model a lunch break. Leave a day empty and the desk is closed.

If the calendar is wrong, every target is wrong by the same amount and nobody will work out why.

## Policies

A policy says which tickets it covers — by queue, team, request type, organisation or priority — and which calendar it measures with.

Policies are checked in order and the first match wins, so put the specific ones above the general ones. A catch-all at the bottom is what stops tickets falling through with no target at all.

## Targets

A target is a metric and a duration: first response within an hour, resolution within eight. Both are in working hours.

Targets can be narrowed by priority and request type within a policy, which is the normal case: the same policy covers everything in a queue, and a critical ticket gets less time than a low one.

## Which statuses stop the clock

This is set on the status, not here, but it belongs in the same decision. A status that means "waiting on the customer" should pause; one that means "waiting on us" should not.

The time is paid back rather than forgiven, so pausing is not a way to make a target easier — it is a way to stop counting time that was not yours.

## Escalations

An escalation acts when a target is about to be missed or has been: raise the priority, notify a team, reassign.

Use them sparingly. An escalation that fires on every ticket is a notification everybody has learned to ignore, and then the real ones go unread too.

## Checking it works

File a test ticket into a covered queue and look at the clock. The panel shows which policy matched and which calendar it is measuring with, which is the quickest way to find a policy ordered above the one you meant.
