---
description: "Git-like content history: drafts, versions with commit messages, scheduled and selective publishing."
---

# Versions & Publishing

Content in b10cks works like a well-run git repository: every save is a **commit** with an author and an optional message, history is never rewritten, and going live is an explicit, reversible act. If you've ever lost work to a CMS that saves over itself, this is the part of b10cks you'll appreciate most.

## Every save is a commit

Saving an entry creates a new **version**: a full snapshot of the content, linked to the version it was based on (its *parent*), stamped with who created it and when. Publishing with a message works like a commit message — "Reworked hero copy for spring campaign" tells your team *why*, not just *what*.

Because each version knows its parent, history isn't just a flat list — it's a tree, like git:

- **Restore** an old version and keep editing: your new work branches off from that point while the abandoned line stays in history.
- Nothing is ever overwritten. Every state the content was ever in remains reachable.

Block definitions are versioned the same way — schema changes get commit messages and diffs too ([Blocks](blocks.md#versioning)).

## Draft and published: two pointers

Think of them like git branches pointing at commits. Each entry has:

- a **current version** — the draft your team is working on. Only visible to API requests that explicitly ask for `vid=draft` (previews).
- a **published version** — what the world sees. `vid=published` is the default for all delivery.

Saving moves the draft pointer; the published pointer doesn't budge until someone publishes. An entry can happily be *live with version 41* while *version 45 is in review* — that's the normal state of things, not an edge case.

## Comparing versions

The version history offers **schema-aware diffs** between any two versions. Instead of a wall of raw JSON, each field diffs according to its type:

- text fields show inline word-level changes
- rich text diffs render readably, formatting included
- nested blocks align by identity, so a reordered block shows as *moved* — with typed sub-field diffs inside each block item
- assets, links, options, and tables each have their own diff view

Review what changed before restoring or publishing — like `git diff`, but for content.

## Going live

Four ways to publish, from simple to orchestrated:

| | When to use |
| --- | --- |
| **Publish now** | The everyday case — the draft goes live immediately |
| **Publish with message** | Same, plus a commit message for the history |
| **Schedule** | Attach a date/time to a version; it publishes itself |
| **Release** | Bundle versions of *many* entries and flip them live atomically — see [Releases](releases.md) |

You can also publish a *specific* version from history — not necessarily the newest draft.

Publishing changes the space's content revision, so API caches roll over instantly and cleanly ([how caching works](access-tokens.md#revision-based-caching)). **Unpublish** takes the entry offline while keeping every version.

## What the API serves

| Request | Serves |
| --- | --- |
| `vid=published` (default) | The published version — live traffic |
| `vid=draft` | The current draft — previews |

Draft delivery still requires a valid access token; the visual editor appends `b10cks_vid` to preview URLs so your frontend can pass it through. Search only ever covers published content.

## Translations version independently

Each translation is its own entry with its own history and its own publish state — German can go live days after English, and each has its own commit log. Fallback at delivery time is described in [Internationalization](internationalization.md).
