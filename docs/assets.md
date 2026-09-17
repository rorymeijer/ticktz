# Assets

The things the desk is asked about: laptops, servers, phones, licences.

A configuration management database earns its keep in one moment — an agent
opens a ticket about a laptop and can see whose it is, what it is plugged into,
and what else broke last month. Everything here is shaped by that moment rather
than by the ambition of modelling an entire estate.

## The shape of it

```
   asset_types              laptop · server · phone · licence
        │                   tag_prefix: LAP → LAP-0001, LAP-0002
        │
        ├── asset_type_fields ──► custom_fields (entity: asset)
        │
        ▼
      assets                asset_tag: the sticker on the box
        │                   status: in_stock → in_use → in_repair → retired
        │                   assigned_to · organization_id · warranty_ends_at
        │
        ├── asset_relations   stored once, read in both directions
        │
        └── asset_ticket      what this ticket is about
```

## The tag is the human key

`asset_tag` is what somebody reads off the underside of a laptop, types into a
search box, and what a CSV import matches on. It is unique, indexed, and
generated from the type's prefix when nobody supplies one: `LAP-0001`,
`LAP-0002`.

The next number is derived from the **highest existing tag**, not from a
counter. Assets arrive by import carrying tags somebody else allocated, so a
sequence table would drift out of step with reality on the first import. Asking
the data is the only answer that stays true.

Deleted assets still hold their tags — the lookup runs `withTrashed()` — so a
tag is never silently reused for a different machine.

## Custom attributes reuse the field system

A laptop has a screen size; a licence has a renewal date. Those are
{@link CustomField}s scoped to the `asset` entity, and an asset type declares
which of them it wants — exactly as a request type declares its form.

One field editor, one validation path, one place this can go wrong. It is also
why there is no field editor on the asset-type screen: fields are defined once
in **Administration → Custom fields** and used in both places.

## Relations are stored once

`asset_relations` holds one row per relationship, in one direction. The other
side is derived when rendering:

| Stored | Reads from the other side as |
| --- | --- |
| `installed_on` | `hosts` |
| `part_of` | `contains` |
| `depends_on` | `required_by` |
| `backs_up` | `backed_up_by` |
| `connected_to` | `connected_to` — a cable has no direction |

A row per direction would let the two halves of one relationship disagree with
each other, and in a CMDB that disagreement is the bug that makes people stop
trusting the whole thing. Writing the mirror of a relation that already exists
is therefore a no-op rather than a second row, and an asset cannot be related
to itself.

## Linking to tickets

This is the half that earns its keep. A register nothing points at answers
"what do we own"; one attached to tickets answers **"what keeps breaking"**,
which is the question that changes a purchasing decision.

The picker on a ticket is seeded from the requester's own equipment when no
search term is given — a ticket about a broken laptop is almost always about
*their* laptop — so the box opens with an answer rather than a cursor.

Assets are soft-deleted, because an asset a ticket was raised about is part of
that ticket's history and erasing it leaves the link dangling.

## Search

MySQL uses a `FULLTEXT` index over name, serial, model, manufacturer, location
and notes. SQLite — the test database — falls back to `LIKE`.

The tag and serial number are matched with `LIKE` on **both** drivers rather
than through the index, and that is not laziness. A FULLTEXT index tokenises on
word boundaries, so `LAP-0042` is stored as two words and a search for `0042` —
which is what somebody types when they can only read half a worn sticker —
finds nothing.

## Importing

Nobody starts a CMDB empty. The first thing a desk does is export whatever they
have been keeping in Excel, so the importer is built around three things:

- **A dry run that reports rather than writes.** The upload says exactly what
  would be created, what would be updated and which rows are wrong; only an
  explicit second request writes anything. An import that acts on the first
  click is one you undo by hand, and nobody has ever undone four hundred rows
  by hand.
- **Matching on `asset_tag`, so it is idempotent.** Importing the same export
  twice updates rather than doubling the estate — which is what actually
  happens, because the second import is usually somebody re-running it after
  fixing three rows. Columns the file does not carry are left alone, so a CSV
  without a `location` column does not blank the locations somebody typed in.
- **Per-row errors, not a failed file.** One malformed date in row 400 must not
  reject the other 399. Each row is its own transaction; the bad ones come back
  with their line numbers.

It reads what spreadsheets actually produce: comma **or** semicolon separated
(a Dutch Excel export is semicolons), a UTF-8 BOM, headers in any
capitalisation, dates as `2026-12-31` **or** `31-12-2026`, and money as
`1299.00` or `€ 1.299,00`. A desk that has to reformat its own export before
importing it will not import it.

Recognised columns: `asset_tag`, `name`, `type`, `serial_number`,
`manufacturer`, `model`, `status`, `location`, `assigned_to` (an e-mail
address), `organization`, `purchased_at`, `warranty_ends_at`, `purchase_cost`,
`currency`, `notes`. Everything else is ignored.

The parsed rows live in the session between the two steps rather than the file
being stored: it is somebody's asset register, it is needed for thirty seconds,
and a copy sitting on disk is one more thing to protect.

## What a requester sees

`/portal/equipment` shows a requester their own equipment, plus their
colleagues' when their organisation shares tickets. Retired kit and the desk's
own stock are never on the portal.

The payload is built by **naming what may be shown**, not by removing a few
keys from the agent one — a payload built by subtraction leaks the next field
somebody adds. No purchase cost, no supplier, no internal notes.

It exists because half the tickets a desk gets about a machine open with the
requester not knowing what the machine is called.

## Permissions

| Permission | Grants |
| --- | --- |
| `assets.view` | Read the register; link assets to tickets |
| `assets.manage` | Create, edit, delete, relate; configure asset types |
| `assets.import` | Import from CSV |

The register sits inside `/agent` but **outside** the `tickets.view` gate the
rest of the console is behind. In plenty of organisations the CMDB is kept by a
procurement or asset team who never touch a ticket, and putting it behind
`tickets.view` would mean giving those people an agent's view of the whole desk
to let them update a serial number.

## Assigning

Handing an asset to somebody moves it from `in_stock` to `in_use`, and taking
it back moves it the other way — an asset that is with somebody and still reads
"in stock" is how a CMDB starts lying.

It never overrides `in_repair` or `retired`. Those are statements somebody made
deliberately, and quietly overwriting them would be the same lie in the other
direction.
