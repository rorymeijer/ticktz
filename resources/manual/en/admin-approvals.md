# Setting up approvals

An approval workflow is an ordered list of steps. That one sentence is the whole model, and the three shapes people ask for are all built from it.

| Shape | How it is built |
| --- | --- |
| **Single** | One step, one approver |
| **Parallel** | One step, several approvers |
| **Sequential** | Several steps |

Within a step everybody is asked at the same time, and the step's **mode** says whether **any** of them is enough or **all** are needed. A second step does not open until the first is settled.

## Who a step asks

- **Named people** — the ones you pick. Anybody with an account, not only agents: an approver is very often a budget holder who never opens the console.
- **A team** — anybody on it.
- **A role** — anybody holding it.
- **The requester's manager** — read from the requester's own record. Make sure managers are filled in before relying on this, or the step has nobody to ask.
- **A field on the ticket** — the approver is whoever a custom field names.

## Attaching one

An approval workflow does nothing until something uses it. Two ways:

- **On a request type**, so every ticket filed that way opens the approval automatically. This is the usual one.
- **By hand from a ticket**, for the case nobody predicted.

## What it blocks

The ticket cannot move past the point requiring the approval until it is settled. Set that on the workflow transition — an approval nothing gates is a notification.

## Things worth getting right

**A step with nobody in it stops the ticket forever.** The most common cause is "the requester's manager" on a desk where managers are not filled in.

**Rejections need a route.** Decide what a "no" means: back to the requester, or straight to closed. A workflow with no answer to that leaves tickets sitting rejected and untouched.

**Keep chains short.** Every step is a person who has to notice an e-mail. Three steps is a week.
