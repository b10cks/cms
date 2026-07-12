---
description: "Reviewing who changed what and when across the space."
---

# Audit Log

The **Audit Log** is the space's memory: who did what, to which item, when. When someone asks "who unpublished the pricing page?", the answer is a filter away.

## What is logged

Operations across all space resources:

- **Content** — created, updated, deleted, published, unpublished, scheduled, moved, restored, version selected
- **Content versions**, **blocks**, **block versions/templates/folders/tags**
- **Assets** and their folders/tags
- **Data sources** and entries
- **Redirects**, **releases** (including committed/canceled)
- **Comments** — created, resolved/unresolved, reactions

Each event stores the time, the actor (user or system), the operation, and the affected item with its type.

## Filtering

The log is filterable by:

- **Actor type / actor** — humans vs. system processes, or a specific user
- **Operation** — e.g. only publishes
- **Item type / item name** — e.g. only redirects, or one specific entry
- **Date range**

Combine filters to answer precise questions — "all deletions by anyone in the last week", "everything that happened to `pricing` this month".
