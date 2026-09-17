# Knowledge base

The answers the desk gives most often, written down once.

Three audiences read the same articles through three different doors, and which
articles reach each door is the whole security surface of the feature:

| Door | Who | What they can read |
| --- | --- | --- |
| `/portal/kb` | Requesters | Published, public, in a public category |
| `/agent/kb` | Agents with `kb.view` | The portal's set, widened by permission |
| `/admin/kb` | Editors with `kb.manage` | Everything, including drafts |

## The shape of it

```
   kb_categories          one level of nesting, public or internal
        │
        ▼
    kb_articles           body: sanitised HTML   ·   body_text: the same, flattened
        │                 status: draft / published / archived
        │                 visibility: public / internal
        │                 locale: nl, en, or NULL for "any language"
        ├──────────────►  kb_article_versions    every edit, restorable
        │
        └──────────────►  kb_article_ticket      which article answered which ticket
```

## Visibility

An internal article appearing on the portal is the one failure this feature
must not have, so there is no single scope with a flag in it. There are two
scopes, and they are written to be read side by side
([`KbArticle`](../app/Models/KbArticle.php)):

- **`visibleOnPortal()`** — published **and** public **and** in an active
  public category (or no category at all) **and** written for the reader's
  language or for everybody. Every portal query goes through it and nothing
  narrows or widens it by hand.
- **`visibleToAgent($user)`** — starts from the same set and *widens* by
  permission: `kb.view.internal` adds internal articles, `kb.manage` adds
  drafts. An agent holding neither sees exactly what a requester sees.

Two details that are deliberate:

- **The category check is not redundant.** A public article inside an internal
  category is a mistake somebody will make, and the portal is the wrong place
  to find out. The category's own visibility wins.
- **A direct URL to an internal article answers 404, not 403.** The existence
  of an internal article is itself information.

Linking is held to the same rule. An agent links an article by id, so the id is
looked up *through their own scope* — guessing one is not a way around the
permission. And the panel on a ticket filters what it shows on every render, so
an article linked while it was public disappears for an agent without
`kb.view.internal` the moment it is made internal. The link stays; it is
history.

## Article bodies are sanitised on write

`body` holds HTML, and HTML that a requester's browser renders is a stored-XSS
hole unless something removes the parts that are not text. That something is
[`ArticleSanitizer`](../app/Services/Kb/ArticleSanitizer.php), and it runs
**once, on the way in**, inside `ArticleService`. Nothing writes to
`kb_articles` outside that service, so a controller cannot forget, and an
import or a seeder gets the same treatment as the editor.

The configuration is an **allowlist**, built from an empty
`HtmlSanitizerConfig`. Symfony offers `allowStaticElements()` as a shortcut; it
is not used, because it installs a broad default set (`<marquee>` among them)
and the list below would then be *adding to* a default-allow config rather than
being the allowlist.

Three outcomes, and the naming is worth knowing because it is the opposite of
what it sounds like:

| Outcome | Symfony call | Effect |
| --- | --- | --- |
| Allowed | `allowElement` | Kept, with its allowed attributes |
| Unwrapped | `blockElement` | Tag removed, **text kept** — `<font>`, `<center>`, `<section>` |
| Removed | `dropElement` | Element **and its content** gone — `<script>`, `<iframe>`, `<svg>` |

Links are limited to `http`, `https` and `mailto`; images to `http` and
`https`. `javascript:` and `data:` URLs survive neither.

Restoring an old version re-sanitises it rather than trusting it: a body stored
before the allowlist last changed is not necessarily safe under the allowlist
as it stands now.

`body_text` is the flattened version and it is what search reads, so a query
for "href" finds articles that discuss links rather than every article that
contains one.

## Versions

Every edit that changes the title or the text snapshots the **previous**
version first, inside the same transaction, and then increments the counter. So
the history can never be half-written, and restoring is "put back what was
there" rather than a guess.

Restoring is itself undoable: the current text is snapshotted before the old
one lands, with a note saying so.

Changing only a label — the category, the position, the status — does not spend
a version. A history where half the entries say nothing is a history nobody
reads.

## Search

MySQL uses a `FULLTEXT` index on `title`, `excerpt` and `body_text`. SQLite —
the test database — has no such index, so the scope falls back to `LIKE`.

The fallback tokenises the way the index does, and this is not a detail. A
search term here is often a whole ticket subject: *"Printer keeps jamming"*
matched verbatim against an article called *"Printer paper jam"* finds nothing
at all. Words shorter than three characters are dropped, and the whole phrase
is kept as well so an exact match still scores.

## Suggestions

Two places offer articles before anybody has searched for one:

- **The portal request form** looks for an answer as the requester types the
  subject, and shows at most five. The cheapest ticket is the one nobody had to
  file. It renders nothing until there is something to show, and the links open
  in a new tab — following one with Inertia would throw away a half-filled
  form.
- **The ticket page** seeds its search from the ticket's own subject, so an
  agent opens a search box that has already had a go rather than an empty one.
  Articles already linked to the ticket are not suggested again.

Both endpoints return JSON rather than a page, and both cap the result count:
the point is a nudge, not a second search results page.

## Views

`recordView()` increments `view_count` with a bare query builder update —
`toBase()`, deliberately out of Eloquent. An Eloquent update stamps
`updated_at`, and an article does not become newer because somebody read it;
the knowledge base would otherwise reorder itself by who happened to click
what.

Without a search term, both lists order by `view_count`. With one, relevance
decides.

## Permissions

| Permission | Grants |
| --- | --- |
| `kb.view` | Read the knowledge base in the agent console; link articles to tickets |
| `kb.view.internal` | Also read articles and categories marked internal |
| `kb.manage` | Write, publish, restore and delete articles and categories |

The portal needs no permission — what a requester may read is decided entirely
by `visibleOnPortal()`.

## Audit trail

Creating, editing, publishing, restoring, deleting, linking and unlinking are
all audited, attributed to the editor. Link and unlink are recorded against the
**ticket**, not the article, because that is where somebody will be reading the
trail.
