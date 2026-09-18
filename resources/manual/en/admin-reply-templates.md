# Reply templates

The answers your desk sends often enough to be worth writing down once. An agent picks one in the reply box; an automation rule sends one on its own. Both read the same list, so the wording never ends up with two versions.

## Why one list

Most desks end up with canned text in two places: a shared document the agents copy from, and the message body typed into an automation rule. The two drift, and the one that drifts is always the robot's — nobody thinks to open the rule when the desk changes how it apologises.

So a template here is a single row with a single body, and the two things that use it read it at the moment they use it. Change the wording once and both change.

## Writing one

**Name and description.** The name is what an agent sees in the dropdown; the description is the line under it. Write the description as *when* to use this, not what it says — the agent can read what it says.

**The reply itself** is ordinary rich text. Keep it short: a canned reply that needs editing before it can be sent is a canned reply nobody uses.

**Placeholders** go anywhere in the body, in double braces — `{{ requester.first_name }}`, `{{ ticket.key }}`, `{{ ticket.portal_url }}`. They are filled in from the ticket at the moment of sending. The full list is on the edit screen, under *Placeholders you can use*.

A placeholder nobody recognises renders as nothing rather than being shown to the customer. That is deliberate — a typo costs you a slightly odd sentence rather than a customer discovering the machinery. It also means a misspelled token fails quietly, so read your template back before you save it.

`{{ agent.first_name }}` is the person sending. An automation rule has nobody, so it renders as nothing there: do not sign an automated reply with it.

## Reply or internal note

A template is one or the other, and the choice lives on the template rather than on the moment of sending.

That is on purpose. An internal note like *"escalated to the supplier, do not tell them yet"* is exactly the sentence that must never reach a customer, and the way it reaches one is a tickbox in the wrong state. A note template is only offered on the note tab, and an automation rule that sends it posts it as a note no matter what the rule says.

## Who sees which

**Team.** A template with no team is offered to everybody. One belonging to a team is offered to that team's agents, and an automation rule may only send it on that team's tickets.

**Language.** A template with no language is always offered. One set to a language is offered when *the requester* reads that language — not the agent. A Dutch agent answering an English customer needs the English wording.

Automation rules do not substitute by language: a rule names one template and sends that one. A desk that wants a rule to answer in the requester's language writes one rule per language, with a condition to match.

## Switching one off

Deleting a template leaves any automation rule pointing at it with nothing to send. The rule does not fail silently — its run log says the template is gone — but the reply does not go out either.

Switching a template off instead keeps it out of the agents' dropdown and out of the automation dropdown, while the rules that reference it keep their configuration. If you are retiring wording, switch it off, check nothing has broken, then delete.

## Sending one from a rule

In **Administration → Automation**, add a *Send a reply template* action and choose the template. It is posted as an ordinary comment: audited, e-mailed to the requester if it is a reply, and visible on the ticket exactly as an agent's would be — except that it has no author, because the desk sent it rather than a person.
