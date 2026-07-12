---
description: "Plan and restructure site sections on the infinite Canvas whiteboard, then apply changes in one click."
---

# Canvas

The **Canvas** turns your content tree into an infinite whiteboard. Where the tree view is built for day-to-day edits, the Canvas is built for the big moves: sketching a new site section, reorganizing fifty pages, planning next quarter's structure — visually, with drag-and-drop, and without touching the live site until you say so.

It's a genuinely different way to work with a CMS: plan like you would on a whiteboard, then apply the whole plan in one click.

## The staging model

Nothing you do on the canvas touches real content until you **Apply**:

- Every change — create, rename, move, delete, restore — is **staged** into a local draft, with per-node change badges (*Created*, *Edited*, *Moved*, *Deleted*).
- **Apply** commits all staged changes to the content tree at once; the summary reports how many changes were applied.
- **Discard** (or *Reload from server*) throws the draft away and reloads the live state.
- Deleting a node marks its whole subtree; children show *Deleted with parent* and can be rescued by restoring the ancestor or dragging them out of the deleted branch.
- Validation runs on the draft — issues must be resolved before it can be applied.

## Working on the canvas

- **Pan & zoom** freely; nodes are cards laid out as a tree from the global root.
- **Add pages** — top-level via *Add top-level page*, or children from any node; pick from the eligible blocks (respecting nesting restrictions — the block filter only offers what's allowed there).
- **Drag** cards to re-parent or reorder branches; keyboard navigation and undo/redo are supported.
- **Slugs** can be auto-generated from names or set manually per node (*Auto*/*Manual* mode).

## AI planning

The AI dock (when AI is enabled for the space) reshapes the draft from a prompt — *"add a five-page product section under /products with a landing page per feature"*. AI actions (create, move, rename, delete, restore, retarget) stream onto the canvas as staged changes; anything it couldn't preview locally is flagged for review with a jump-to-node shortcut. You still review and Apply — AI never commits directly.

## When to use canvas vs. the tree

| Task | Tool |
| --- | --- |
| Edit one entry's fields | [Content editor](content.md) |
| Move/rename a handful of entries | Content tree drag-and-drop |
| Restructure a section, plan a new site area, bulk-create scaffolding | **Canvas** |
