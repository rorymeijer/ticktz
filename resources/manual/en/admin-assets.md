# Managing the register

## Asset types

An asset type is a shape: laptop, server, phone, licence. Each carries its own fields and its own tag prefix, so laptops get `LAP-0001` and servers get `SRV-0001` and the tag says what the thing is.

Give a type the fields that are actually looked up. Warranty end, cost centre, licence count — the things somebody needs while working a ticket. A field nobody reads is a field somebody has to fill in.

## Importing

**Administration → Assets → Import** takes a CSV.

It previews before it commits. Read the preview: it shows what will be created, what will be updated, and what it could not match. Importing is the fastest way to fill a register and the fastest way to fill it with nonsense, and the preview is the difference.

Match on the asset tag. It is the one field that is supposed to be unique and printed on the thing itself.

## Keeping it true

A register nobody corrects becomes a register nobody trusts, and then nobody corrects it. This is the failure mode of every CMDB, and it is not a technical one.

The two fields that carry the weight are **who holds it** and **its status**. Everything else is read through those. If you only keep two things accurate, keep those.

Two habits that help:

- **Agents update it from the ticket.** Attaching an asset takes a moment and updating the holder takes another; a register maintained as a side effect of work stays closer to true than one maintained in a project.
- **Retire rather than delete.** A retired asset keeps its history, and its history is what tells you the replacement is the third one this year.
