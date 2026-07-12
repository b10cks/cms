---
description: "Working with the content tree and editor: creating, editing, translating, and publishing entries."
---

# Content

**Content** is where you'll spend most of your time: a page tree on the left, your editing form in the middle, and — once a preview is set up — a live view of your actual website beside it. What you type shows up on the preview as you type it.

**On this page:** [The content tree](#the-content-tree) · [Creating content](#creating-content) · [Editing](#editing-a-page) · [Working together](#working-together-live) · [Publishing](#saving-publishing-and-scheduling) · [Version history](#version-history) · [Live preview](#live-preview) · [Comments](#comments) · [Translating](#translating-content)

## The content tree

The tree on the left holds every page and folder of your project, nested the way your site is structured.

- **Rearrange** — drag items where you want them. Select several at once and move them together; while dragging, the tree shows exactly where things will land (*into*, *before*, *after*, *to root*). Whether you can hand-sort or things stay alphabetical is a space setting — and any folder can define its own order (see [page settings](#the-config-tab)).
- **Find things fast** — the tree search is fuzzy: type a few letters of what you remember (`prcng` finds "Pricing") and jump between matches with the arrows.
- **Right-click for everything** — view, edit, rename, copy, cut, paste (*into* a folder or *after* an item), publish, publish with message, schedule, delete. Deleting removes the item *and everything underneath it*, so the app asks first.
- **Read the badges** — each item shows its state at a glance: draft, published, published-but-edited-since, or scheduled.

> **Tip:** for restructuring whole sections at once — moving dozens of pages, planning new areas — switch to the [Canvas](canvas.md), stage everything visually, and apply it in one click.

## Creating content

Click **New content** (or right-click a folder → *New sub item*):

1. **Pick a content type.** You'll only be offered types that are allowed here — folders can restrict what may be created inside them, and often pre-select a default type for you.
2. **Start blank or from a template.** Templates are pre-filled starting points your team has saved — a "Product page" template arrives with the right sections already in place. *Blank* gives you the type's default values.
3. **Name it.** The URL slug is suggested from the name; adjust it if needed.

The new page opens in the editor as a draft — nothing is public yet.

## Editing a page

The editor has four tabs:

### The Edit tab

The content itself — all the fields your page is made of, grouped into the sections your team designed (e.g. *Content*, *SEO*, *Settings*).

- **Nested blocks** (page sections like heroes, galleries, text blocks) appear as a sortable list: click **+** to add one — blank or from a template — then drag to reorder, duplicate, or remove. New blocks arrive pre-filled with their defaults.
- **Fields can appear and disappear** based on what you choose — picking layout "Video" may reveal a video field. That's intentional; nothing is lost.
- **Some fields are smarter than boxes**: link fields let you point at a page (and the link survives if that page's address changes later), asset fields open the media library, reference fields pick related content, icon fields open a searchable icon picker.
- **AI assistance** (if enabled for your space): ask the AI dock to draft, rewrite, shorten, or translate — you always review before anything is saved.
- **Out of schema** — if a field was removed from the content type after this page was written, its stored value shows up under *Out of schema* so nothing silently vanishes. Keep it for reference or remove it.

The editor checks your work as you go: required fields, minimum lengths, and patterns are validated live. A status indicator counts open issues, and if a problem hides inside a nested block, that block is flagged so you can drill straight down to it. You can save a draft with open issues — but you'll know they're there.

### The Config tab

Settings for *this* page and its children:

| Setting | What it does |
| --- | --- |
| **Slug** | The page's URL segment |
| **Child sorting** | How sub-pages are ordered: by hand, by name, by date — or by a field *inside* the content, like an event's start date. The website receives them in this order too. |
| **Child content types** | Restrict what may be created beneath this page, and set the default type for *New sub item* |
| **Caching** | Hints for developers: a cache lifetime and cache tags this page is delivered with |
| **Preview** | Hide the preview panel for entries that aren't pages (pure data, settings containers) |

### The Info tab

The facts: created and updated times, authors, technical IDs.

### The Comments tab

Discussions about this page — see [Comments](#comments).

## Working together, live

You're never editing alone in the dark:

- Avatars show **who else is here** right now, and where they're working.
- Their changes appear on your screen **as they make them** — two people can comfortably work on different sections of the same page. There are no locks and no "this page is being edited" walls.
- If teammates have unsaved work and you're about to save, the editor tells you **whose changes would ride along** and asks before including them — no accidental overwrites.

## Saving, publishing, and scheduling

Saving stores a **draft** — visitors see nothing until you publish. When you're ready:

- **Publish** — the page goes live immediately.
- **Publish with message** — add a short note ("Updated pricing for Q3") that lands in the page's history, like a commit message. Your future self will thank you.
- **Schedule** — pick a date and time; the page publishes itself.
- **Add to a release** — bundle this page with others and take them all live in one moment ([Releases](releases.md)).
- **Unpublish** — take the page offline. Nothing is deleted; every version stays in history.

You can keep editing after publishing: the live page stays as it is while your new draft grows — publish again whenever the next round is ready.

## Version history

Every save becomes a **version** — a complete snapshot with author, time, and message, grouped by day/week in the history panel. Badges show which versions were published, scheduled, or belong to a release. For any version you can:

- **Compare** — the *Changes* tab shows exactly what differs from the previous version, field by field: text shows word-by-word edits, formatted text stays readable (no code soup), and rearranged sections show as *moved*, with the actual edits inside each block. The *Visual* tab renders the old version as a page.
- **Continue with this version as draft** — restore it and keep editing from there. Your abandoned line stays in history too — like branches, nothing is ever overwritten.
- **Publish this version** — put a specific version live, not necessarily the newest.

More on the model: [Versions & publishing](../concepts/versions-and-publishing.md).

## Live preview

With a preview environment configured (**Settings → Editor**), your real site renders next to the form — same design, same code your visitors get.

- **Click a section** on the preview to jump straight to its fields.
- **Simple text can be edited right on the page** — click and type.
- Teams with several stages (local, staging, production) can switch preview environments; everyone can set a personal favorite.

## Comments

Feedback lives with the content, not in chat threads that scroll away:

- Comment on the page as a whole, on a **specific field**, or **click anywhere in the preview** to pin a positioned comment to that exact spot.
- **@-mention** teammates — they're notified.
- Reply in threads, react with emojis, and **resolve** discussions when they're done (resolved threads stay retrievable).

## Translating content

If your space has multiple languages, switch the editor into **localization mode**: original and translation side by side, field by field, with anything missing clearly flagged — including the SEO fields.

- A **copy-source** button pulls the original text over as a starting point.
- With AI enabled, translate a field or the whole page for a first draft — then refine.
- Everything you know from normal editing carries over: translators see each other live, and edits made on the visual preview flow into the translation draft, not the original.
- Each language publishes **independently** — German can go live days after English.

For agencies and external translators, **Export translations** produces XLIFF, CSV, Excel, JSON, or YAML files; **Import translations** applies them back — as drafts for review, or published immediately, optionally creating language versions that didn't exist yet. An import summary shows exactly what changed. Background: [Internationalization](../concepts/internationalization.md).
