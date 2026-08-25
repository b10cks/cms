---
description: "The block library explained: what a block is, how to create one, what its fields can do, and how templates and history help."
---

# Block library

Everything you write in b10cks is made of **blocks**. A block is a type of content that your team designs once and then uses over and over: "Page", "Blog post", "Hero image", "Testimonial", "Newsletter box".

Think of it like a paper form. Somebody decides which boxes the form has, what each box is called, and which ones must be filled in. After that, anybody can fill in the form without deciding what a form should look like. In b10cks the form is a block, each box is a **field**, and the list of fields is called the block's **schema**.

Designing blocks is usually done by a smaller group: whoever is responsible for how the site is structured, often together with a developer. Once the blocks exist, everyone else just fills them in. If you only write content, you can skip this page and go to [Content](content.md).

The ideas behind blocks and fields are described in [Blocks](../concepts/blocks.md) and [Fields](../concepts/fields.md).

## The library

All blocks are listed with their icon, colour, name, type, tags, and folder. You can search the list and filter it by any of those.

- **Folders** keep a growing library tidy. Deleting a folder never deletes the blocks in it. They simply move back to the top level.
- **Tags** group blocks across folder boundaries, and they do something clever on top: a field that holds sections can be set to accept "anything tagged `content-section`". Tag a newly built block with it and it immediately becomes available in every place that accepts that tag, without anyone editing a single form.
- **Icon and colour** give a block a recognizable identity in the editor, the page tree, and the picker where editors choose what to add.

## Creating a block

Click **Create Block** and fill in the basics.

| Setting | What it is for |
| --- | --- |
| **Name and description** | What your colleagues see when they pick a block. b10cks derives a technical name from it, which is what developers use to match a design to it. |
| **Type** | Where this block may be used. The four options are explained right below. |
| **Icon and colour** | Its visual identity in the app |
| **Preview image** | A small screenshot shown in the picker, so people recognize blocks by sight instead of by name |
| **Folder and tags** | Where the block lives and how it groups |

### The four block types

| Type | Meaning | Example |
| --- | --- | --- |
| **Root** | A page in its own right, with its own web address | Blog post, landing page |
| **Nestable** | A building brick used inside another block, never on its own | Hero image, gallery, call-to-action |
| **Singleton** | Exists exactly once in the whole space | Main navigation, footer |
| **Universal** | Can be both a page of its own and a brick inside another page | A teaser that is also a standalone page |

Choose deliberately, since this decides where the block can turn up. More detail in [Block types](../concepts/blocks.md#block-types).

After the basics, you build the block's fields.

## The schema editor: adding fields

A block's schema is simply its list of fields, in the order editors will see them.

- **Add a field** and pick its type. There are 21, from a single line of text to a list of nested sections. Every type and every one of its options is listed in the [field reference](../concepts/fields.md).
- **Reorder fields** by dragging. The editing form follows exactly this order, so time spent here is time your colleagues save every day.
- **Copy and paste fields between blocks.** The clipboard carries the complete configuration, so a carefully tuned rich text setup moves to another block in two clicks instead of being rebuilt from memory.
- **Renaming needs care.** Renaming a field's *label*, the text people read, is free. Renaming its *key*, the technical name underneath, is not: existing content keeps its text under the old key, and any website code reading it stops finding the value. The rename dialog says so plainly. Conditions inside the same block that refer to the field are updated for you.

## What you can configure on a field

Every field, whatever its type, has the same handful of shared settings:

| Setting | What it does |
| --- | --- |
| **Required** | The field must be filled in |
| **Translatable** | The field gets its own value per language. Leave it off for things that are the same everywhere, such as a product number. |
| **Indexable** | The field's text is included when someone searches the space |
| **Default value** | Pre-filled whenever a new entry is created |
| **Validation** | Rules such as minimum and maximum length, how many items are allowed, or a pattern the value must match |

On top of that, each type has its own options. Three are worth calling out.

**Conditions.** A field can be shown only when other fields have certain values, for example "show the video address only when the layout is set to Video". You can require that all conditions match or that any of them does. This keeps forms short and only surfaces complexity when it is actually relevant.

**Choices from a data set.** Dropdown fields can take their options from a central [data set](data-sources.md) instead of a hardcoded list. Maintain "Departments" in one place and every dropdown that uses it stays correct.

**Rich text configuration.** For formatted text you decide exactly what writers may do: which heading levels are offered, and which formatting features exist at all. Switching a feature off removes the capability entirely rather than just hiding a button, and text pasted from Word or Google Docs is cleaned up to match what you allow. You can also define named text styles and list styles that map to your website's own design, and placeholder tokens such as `{firstName}` that your website fills in when the page is displayed.

## Grouping fields into tabs

Fields can be grouped into named **editor pages**, which appear as the sections or tabs of the editing form, for example *Content*, *SEO*, and *Settings*. This is purely about presentation and changes nothing about the content itself. It does turn a block with 25 fields from an intimidating wall into a form people are happy to fill in.

## Before you change something: the Usage panel

::: tip Highlight
Refactoring a content model is normally the scary part. Here it is not: you can see where a block is used before touching it, every schema change is versioned with a message and can be rolled back, and removing a field never destroys the content that was written into it.
:::


The **Usage** panel answers the question "where does this block actually appear?" before you restructure or delete it:

- **Can be nested inside** lists the blocks that allow this one in their section lists.
- **Can be referenced by** lists the blocks that point at it through a reference field.

No detective work, and no unpleasant surprises after the change is live.

## Templates

A **template** is a saved, pre-filled block, for example "Testimonial, two columns, with photo". Editors insert the template instead of an empty block and start from something that is already 80 percent right.

Create one from any existing block on a real page with *Save as template*, then give it a name, a description, and a preview image. Templates keep recurring patterns consistent and save everyone the same setup clicks.

## History for block definitions

Block definitions get the same complete history as content. Every save is a snapshot with an author and, if you write one, a short message such as "split body into intro and content". Per version you can:

- open a **Changes** tab showing exactly what differs from the version before,
- open a **Preview** tab showing the definition as it was,
- and **restore** it, which rolls the schema back without you rebuilding it by hand.

That makes structural changes far less frightening. Change boldly, compare honestly, roll back in seconds.

> **Good to know:** removing or renaming a field never rewrites content that already exists. The stored values stay where they are, and editors see them in the affected entries under *Out of schema*. Add fields whenever you like. Rename keys deliberately.
