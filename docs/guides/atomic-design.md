---
description: 'A practical method for designing reusable blocks: atoms, molecules and organisms as block tags, and the restriction settings that hold the system together.'
---

# Designing blocks with atomic design

Block libraries fail in one of two directions.

They **sprawl**: every new page brings two new blocks, nobody remembers that `hero-2` exists, and after a year the picker offers 60 blocks, six of which are nearly identical. Or they **collapse into one**: a single "Section" block with 40 fields and a layout dropdown, where editors guess which twelve fields apply to their case and the rest sit empty.

Atomic design is a way out of both. It is not a b10cks feature, it is a naming convention from web design that maps onto b10cks so cleanly that the CMS can enforce it for you.

## The idea in one minute

Brad Frost's atomic design describes interfaces as a hierarchy, borrowing the vocabulary of chemistry:

- An **atom** is the smallest useful piece that cannot be split further and still mean something. A button. A label. An image with a caption.
- A **molecule** is a small group of atoms doing one job together. A card made of image, headline, text, and button. A quote with an author line.
- An **organism** is a complete, standalone section of a page. A hero. A three-column feature grid. A testimonial carousel. A footer call to action.

Each level is built from the level below. That is the whole idea, and it is what makes the pieces reusable: a card does not know whether it sits in a feature grid or a blog teaser list, so it works in both.

Here is how the vocabulary maps onto b10cks:

| Atomic design | In b10cks                                                | Block type  |
| ------------- | -------------------------------------------------------- | ----------- |
| Atom          | A small nestable block, often just two or three fields   | Nestable    |
| Molecule      | A nestable block whose schema contains atoms             | Nestable    |
| Organism      | A nestable block that fills a full page section          | Nestable    |
| Template      | A root block: the page type and its overall structure    | Root        |
| Content Entry | A content entry that an editor created from a root block | Not a block |

The two lower rows are worth pausing on. In atomic design, a "template" is the page-level skeleton and a Content Entry (e.g. a "page", "blog post") is that skeleton filled with real content. In b10cks a **root block** is the skeleton and a **content entry** is the filled page. The words line up.

::: warning One word, two meanings
b10cks also has **block templates**, which are pre-filled blocks an editor can insert, such as "Testimonial, two columns, with photo". Those are a convenience feature and have nothing to do with the "template" level of atomic design. When both come up in the same conversation, say "root block" for the structural level.
:::

## Step 1: create three tags

In **Blocks**, create three tags and give each one a colour and an icon so they are recognizable at a glance:

- `atom`
- `molecule`
- `organism`

Tags, not folders. A block lives in exactly one folder but can carry many tags, and folders are for topic ("Marketing", "Commerce", "Editorial") while these tags describe the level. More importantly, tags are what the restriction settings can match on, and folders are not.

::: warning Choose tag names once
A tag is identified by its name. Blocks carry the tag's name, and restriction lists store the name too. Renaming a tag afterwards does not rewrite either of them, so the blocks silently lose the association and your allowlists point at a tag nobody carries any more.

To rename a tag properly: create the new one, re-tag every block, update the restriction lists that mention the old name, and only then delete the old tag. Deleting is clean, since it removes the tag from every block that carries it.

Lowercase, singular, no spaces. Then never touch them again.
:::

Tag every block you create from now on with exactly one of the three. A block that seems to be two levels at once is a design smell worth resolving before it spreads.

## Step 2: choose the block type

The tag records your intent. The **block type** is what b10cks enforces, so the two have to agree.

| Your level                          | Type to choose | Why                                                                                                                                                     |
| ----------------------------------- | -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Atom, molecule, organism            | **Nestable**   | Nestable blocks can only be used inside another block's Blocks field, which is exactly right for a building brick. They never turn up as a stray page.  |
| Page type                           | **Root**       | Root blocks are what editors create in the content tree.                                                                                                |
| Navigation, footer, global settings | **Singleton**  | Exactly one entry can ever exist, and it holds no nested blocks.                                                                                        |
| A teaser that is also its own page  | **Universal**  | Creatable in the tree _and_ nestable. Genuinely useful, easy to overuse. Default to nestable and promote a block to universal when a real case appears. |

Only **nestable** and **universal** blocks are offered inside a Blocks field. Only **root** and **universal** blocks can be created in the content tree. That is enforced by b10cks before any of your own restrictions apply.

## Step 3: wire up the restrictions

This is the step that turns a naming convention into a system that holds. Without it, the tags are documentation nobody reads. With it, the editor only ever offers what belongs in a place.

Each **Blocks** field has two ways to restrict what may be inserted:

- **By tag**, which allows any block carrying one of the listed tags.
- **By block**, which allows specific blocks by name.

Set them per level:

**On a root block's main content field**, allow the tag `organism`. Editors composing a page get exactly the page sections, and never a lone button.

**Inside an organism**, allow the tag `molecule` for its repeating parts, for example the `cards` field of a feature grid. Where a slot really only accepts one specific thing, name that block explicitly instead.

**Inside a molecule**, allow the tag `atom`.

::: tip Why this pays off immediately
Restricting by tag rather than by name is the part that keeps working as your library grows. Build a new organism, tag it `organism`, and it appears in every page type that accepts organisms. No schema edits, no touching twelve root blocks, no deployment. The rule was written once and the tag does the rest.
:::

### How the matching actually works

Worth knowing precisely, because the behaviour is forgiving in one place and strict in another:

- If neither list has anything in it, **everything nestable is allowed**. An unrestricted field is a wide-open field.
- If either list has entries, restriction is active.
- A block passes if it matches the **block list or the tag list**. The two lists are alternatives, not conditions that both have to hold, so "everything tagged `molecule`, plus the `divider` block" is a single rule you can express directly.
- Block type is checked first and always. A root block never appears in a Blocks field, whatever your lists say.

### Restricting the content tree too

The same thinking applies one level up, to which pages may be created underneath which other pages. Open a page's **Config** tab and you can restrict its children by block, by tag, or both, and set the type that is pre-selected for a new child.

This is how a `/news` folder comes to accept only `article`, and a `/products` branch only `product`. It works on root and universal blocks, since those are the only ones that can exist in the tree at all.

Two details make this trustworthy:

- The rule is **enforced on the server**, not merely hidden in the menu. Somebody using the API cannot create an entry the rule forbids.
- The rule follows the **original entry**, so translations obey the same structure as the language they were created from.

If you use this a lot, a fourth tag such as `page-type` on your root blocks lets you write the rule once per branch instead of listing block names.

## A worked example

A typical marketing site, complete:

| Block                | Tag                        | Type      | Its Blocks fields allow    |
| -------------------- | -------------------------- | --------- | -------------------------- |
| `page`               | (none, it is a root block) | Root      | tag `organism`             |
| `article`            | (none)                     | Root      | tag `organism`             |
| `hero`               | `organism`                 | Nestable  | tag `atom` for its buttons |
| `feature-grid`       | `organism`                 | Nestable  | tag `molecule`             |
| `testimonial-slider` | `organism`                 | Nestable  | block `testimonial` only   |
| `logo-wall`          | `organism`                 | Nestable  | block `logo` only          |
| `feature-card`       | `molecule`                 | Nestable  | tag `atom`                 |
| `testimonial`        | `molecule`                 | Nestable  | nothing nested             |
| `button`             | `atom`                     | Nestable  | nothing nested             |
| `logo`               | `atom`                     | Nestable  | nothing nested             |
| `navigation`         | (none)                     | Singleton | nothing nested             |

Read the right-hand column downwards and the rule is visible: each level admits only the level below it. An editor building a page picks from eleven blocks in total but is never offered more than a handful at a time, and every one of them makes sense where it is offered.

## When to build a new block, and when not to

The hardest judgement in this method is where a variation belongs. Three rules of thumb that hold up well:

**Different content, new block. Different appearance, an option on the existing one.** A testimonial with a photo and one without are the same content in two skins, so that is a `layout` option. A testimonial and a case study are different content, so those are two blocks.

**Count the fields that would sit empty.** If a new option leaves half the form irrelevant, you are building two blocks inside one. Split it. [Conditional fields](../concepts/fields.md#conditional-fields) can hide what does not apply, and they are the right tool for two or three variations, not for eight.

**Build the second use before you generalize.** A block that exists once is not reusable, it is just a block. Wait for the second real use, then extract the shared part. Generalizing on the first occurrence is how libraries acquire abstractions nobody needs.

## Making it usable for the people who fill it in

The structure is for you. These are for everyone else:

- **Icons and colours** per block, so the picker and the page tree are scannable rather than readable.
- **A preview image** on every organism. Editors recognize sections by sight far faster than by name, and a screenshot removes the "what does 'Feature grid B' look like" question entirely.
- **Names from the editorial vocabulary**, not the design system's. "Quote" beats "Blockquote molecule".
- **[Editor pages](../ui/blocks.md#grouping-fields-into-tabs)** to group fields into tabs on anything with more than about eight fields.
- **[Block templates](../ui/blocks.md#templates)** for the combinations your team builds over and over, so the common case is one click instead of six.

## Adopting this in a library that already exists

You do not need to rebuild anything. Do it in this order, and each step is useful on its own even if you stop there:

1. **Tag what exists.** Go through the library once and give every block one of the three tags. This alone surfaces the problems: the blocks that are two levels at once, and the near-duplicates sitting next to each other.
2. **Merge the duplicates** the tagging exposed. `hero`, `hero-2`, and `hero-dark` are usually one block with an option.
3. **Restrict from the top down.** Start with the root blocks' main content fields, allowing tag `organism`. That is the change editors feel immediately, and it cannot break existing content: restrictions govern what may be _inserted_, so blocks already on a page keep working and rendering.
4. **Then restrict the inner fields**, organism by organism.
5. **Check the [Usage panel](../ui/blocks.md#before-you-change-something-the-usage-panel)** before you change or delete anything. It tells you which blocks may nest the one you are looking at and which reference it.

## Anti-patterns worth naming

- **The god block.** One "Section" with a layout dropdown and 40 fields. Every editor learns which subset applies to them, and none of them agree.
- **Atoms as organisms.** A `button` block allowed directly on a page, so pages end up with stray buttons floating between sections.
- **Restriction by name everywhere.** It works until the tenth block, then every new block means editing every root block. Restrict by tag and this never happens.
- **Tag drift.** `organism`, `Organism`, `organisms`, `section`. Pick three names, write them down, and reject the fourth.
- **Nesting four levels deep.** Atoms in molecules in organisms is enough. A fourth level is usually a molecule that wants to be an organism.

## Checklist

Before adding a block to the library:

- It has exactly one of `atom`, `molecule`, `organism`.
- Its type matches that level. Nestable unless it is genuinely a page.
- Every Blocks field on it restricts to the level below, by tag.
- It has an icon, a colour, and, if it is an organism, a preview image.
- Its name is the word editors already use.
- It is not a near-duplicate of something that exists. Check the library first.
- If it exists to serve exactly one page, it probably should not exist yet.

## Related

- [Blocks](../concepts/blocks.md) for block types, schemas, and nesting rules
- [Fields](../concepts/fields.md) for every field type and its options
- [Block library](../ui/blocks.md) for the screens described here
- [Content](../ui/content.md#the-config-tab) for the per-page child restrictions
