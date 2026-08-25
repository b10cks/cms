---
description: "Everyday work in b10cks: finding pages, writing and editing them, saving drafts, publishing, translating, and going back to an earlier version."
---

# Content

**Content** is where you will spend most of your time. The screen has three parts: a list of your pages on the left, the form you fill in down the middle, and, if your team has set it up, a live view of your actual website on the right. What you type appears in that preview while you type it.

Nothing you do here is instantly public. Content is saved as a **draft** first, which only your team can see, and becomes visible to visitors when you **publish** it. That two-step rhythm runs through everything on this page.

## The content tree

The list on the left holds every page and folder of your project, arranged the way your website is structured. A page that sits underneath another page in the tree usually sits underneath it in the website's address as well, so `Products → Shoes` becomes an address like `/products/shoes`.

**Moving things.** Drag an item to where it belongs. You can select several at once and move them together. While dragging, the tree shows exactly where the item will land: *into* a folder, *before* or *after* another item, or *to root*, meaning the very top level. Whether you are allowed to sort by hand at all, or whether pages are always listed alphabetically, is decided in the space settings, and any single folder can define its own order (see [the Config tab](#the-config-tab)).

**Finding things.** The search box above the tree is forgiving. Type a few letters of what you half remember and it still finds the page: `prcng` finds "Pricing". Arrows jump between the matches.

**The right-click menu.** Right-click any item for everything you can do with it: view, edit, rename, copy, cut, paste (either *into* a folder or *after* an item), publish, publish with a message, schedule for later, and delete. Deleting removes the item **and everything below it**, so the app asks you to confirm first. If your team has set up [content actions](automations.md#content-actions), they show up here under **Actions** and run against exactly this one page.

**The coloured labels.** Each item shows its current state at a glance:

| Label | What it means |
| --- | --- |
| **Draft** | Written but never published. Visitors cannot see it at all. |
| **Published** | Live on the website, and the live version matches what you last saved. |
| **Published, edited since** | Live on the website, but you have newer unpublished changes waiting. Visitors still see the older, published text. |
| **Scheduled** | Set to publish itself automatically at a chosen date and time. |

::: tip Highlight
The tree is not just a list. Fuzzy search finds a page from a few remembered letters, drag and drop moves whole branches, the right-click menu carries every action including your team's own [content actions](automations.md#content-actions), and coloured badges show each page's publication state without opening it.
:::

> **Tip:** if you need to restructure a whole section, move dozens of pages, or sketch out an area that doesn't exist yet, switch to the [Canvas](canvas.md). It lets you plan everything visually and apply it in one go.

## Creating a page

Click **New content**, or right-click a folder and choose *New sub item*. Then three questions:

1. **Which type of content is this?** You are only offered types that are allowed in this spot, because folders can restrict what may be created inside them. Many folders also pre-select the type you most likely want.
2. **Blank, or from a template?** A template is a pre-filled starting point your team has saved, so a "Product page" template already contains the right sections in the right order. *Blank* gives you the empty form with whatever default values your team defined.
3. **What is it called?** Type the name. From the name b10cks suggests a **slug**, which is the part of the web address that identifies this page: a page named "Winter Sale" gets the slug `winter-sale` and ends up at an address like `/campaigns/winter-sale`. You can change the suggestion if you want a shorter or different address.

The new page opens as a draft. Nothing is public yet.

## Editing a page

The editor is organized in four tabs across the top.

### The Edit tab

This is the content itself: all the fields that make up your page, grouped into the sections your team designed, often something like *Content*, *SEO*, and *Settings*.

**Sections you can add and rearrange.** Larger parts of a page, such as a hero image, an image gallery, or a text-and-image row, appear as a list you can sort. Click **+** to add one, either empty or from a template, then drag them into the order you want. Each one can be duplicated or removed. A newly added section already contains the default values your team set for it, so it is never completely blank.

**Fields that appear and disappear.** Some fields only show up once they are relevant. Choosing the layout "Video" may reveal a field for the video address that was hidden before. This is intentional, and nothing you typed earlier is lost when a field hides itself.

**Fields that do more than hold text.** A link field lets you point at another page in your project rather than typing an address, and the link keeps working even if that page's address changes later. An image field opens the media library. A reference field picks related content, such as the three products shown on a campaign page. An icon field opens a searchable icon picker.

**Help from AI.** If your space has AI features switched on, the assistant panel can draft, rewrite, shorten, or translate text for you. Nothing it produces is saved until you accept it.

**Out of schema.** If a field was removed from this content type after your page was written, the text you had entered does not disappear. It is shown in a section called *Out of schema* so you can copy it somewhere else or remove it deliberately.

**Checks while you type.** The editor validates your work as you go: required fields that are still empty, text that is too short or too long, and values that don't match the expected format. A counter shows how many issues are open, and if the problem sits inside one of the page sections, that section is marked so you can jump straight to it. You are still allowed to save a draft with open issues. You just won't be able to miss them.

### The Config tab

Settings for this one page and the pages below it.

| Setting | What it does |
| --- | --- |
| **Slug** | The page's own piece of the web address, for example `winter-sale` |
| **Child sorting** | The order of the pages underneath this one: by hand, by name, by date, or by a value inside the content itself, such as an event's start date. Your website receives them in this order too. |
| **Child content types** | Which types of content may be created below this page, and which one is pre-selected for *New sub item* |
| **Caching** | Two technical hints your developers may ask you to set. They control how long the page may be stored in a fast intermediate copy, and which label to use when that copy has to be refreshed. |
| **Preview** | Turn the preview panel off for entries that are not real pages, for example a container that only holds settings or shared data |

### The Info tab

The plain facts: when the page was created and last changed, who did it, and the technical identifiers your developers occasionally need.

### The Comments tab

Discussion about this page, described under [Comments](#comments).

## Working together, live

::: tip Highlight
Most content management systems solve "two people opened the same page" by locking one of them out. b10cks does not lock anything. Everyone edits at the same time, everyone sees everyone else's changes as they happen, and the editor keeps track of whose unsaved work is whose. It is the way a shared document behaves, applied to structured content.
:::

**Everybody gets a colour.** The moment a second person opens the page, both of you appear as small profile pictures at the top, each with an assigned colour that stays consistent throughout the session.

**You can see where the others are working.** A field somebody else has selected gets an outline in their colour. You do not have to guess whether your colleague is in the headline or the footer, so the usual "you take SEO, I'll take the body" conversation just happens on screen.

**Their typing arrives on your screen as they type it.** Not on save. Not on refresh. Two people can comfortably work on different sections of the same page, and reviewing something while somebody else is fixing it is a normal thing to do rather than a race.

**Structural changes travel too.** Adding a section, dragging one into a different position, duplicating one, or deleting one all appear for everybody, so the page you are looking at is the page your colleague is looking at.

**Unsaved changes are attributed.** A field that carries unsaved work is marked in the colour of whoever changed it. At a glance you can tell your own pending edits from the ones your colleague made two minutes ago.

**Joining late is not a problem.** Open a page where somebody has been working for ten minutes without saving, and you receive their current unsaved state, not the last saved version. Nobody has to save prematurely just so a colleague can see what they did.

**Saving asks about other people's work.** If a colleague has unsaved changes and you press save, the editor tells you **whose work would be included** and asks before saving it along with yours. Nobody overwrites anybody, and nobody publishes somebody else's half-finished paragraph by accident.

**Comments appear live** as well, so a discussion in the Comments tab does not need anybody to reload.

The same applies while translating: in localization mode translators see each other exactly the same way. And in the [content tree](#the-content-tree), small profile pictures on the entries show which page each of your colleagues is currently in, before you even open it.

## Saving, publishing, and scheduling

Saving stores a **draft**. Visitors see nothing until you publish. When you are ready, the save button's menu offers several ways to go live:

- **Publish** puts the page online immediately.
- **Publish with message** does the same and attaches a short note, such as "Updated pricing for Q3". The note lands in the page's history, which makes it much easier to understand later why something changed.
- **Schedule** asks for a date and time, and the page publishes itself then. Useful for embargoed announcements and for anything that should go out while you are asleep.
- **Add to a release** puts the page into a bundle that goes live together with other pages. See [Releases](releases.md).
- **Unpublish** takes the page offline again. Nothing is deleted, and every version stays in the history.

You can keep editing after publishing. The live page stays exactly as it is while your new draft grows next to it, and you publish again when the next round is ready.

## Version history

::: tip Highlight
Nothing you write is ever overwritten. Every save is kept as a full version with an author and a message, differences between two versions are shown field by field in readable form, and any version can be restored or published. Developers get this from version control. Here, editors get it without learning a single new concept.
:::

Every time you save, b10cks keeps a complete **version** of the page: a full snapshot with the author, the time, and the message if you wrote one. The history panel groups them by day and week, and marks which versions were published, scheduled, or belong to a release.

For any version in the list you can:

- **Compare it.** The *Changes* tab shows what differs from the version before it, field by field. Plain text is shown word by word, formatted text stays readable instead of turning into code, and sections that were moved are marked as *moved* with their own edits shown inside. The *Visual* tab renders the old version as an actual page.
- **Continue from it.** *Continue with this version as draft* brings the old content back so you can keep working from there. The line of work you are leaving behind stays in the history as well, so this is never destructive.
- **Publish it.** You can put a specific older version live without touching your newer drafts.

The idea behind all of this is explained in [Versions and publishing](../concepts/versions-and-publishing.md).

## Live preview

::: tip Highlight
The preview is not an approximation of your website. It is your website, running its own code, next to the form, updating as you type. Clicking a section in it takes you to the fields behind that section, which is the fastest way to find the right field on a long page.
:::

If somebody has configured a preview address for your space under **Settings → Editor**, your real website appears next to the form, with the same design and the same code your visitors get.

- **Click a section in the preview** to jump to its fields in the form.
- **Simple text can be edited directly on the page.** Click it and type.
- Teams that run several stages, such as a local machine, a staging server, and the live site, can switch between them. Everybody can also pick a personal favourite.

## Comments

::: tip Highlight
Comments can be pinned to a spot on the rendered page, not just to a field. Click anywhere in the preview and leave a note exactly where the problem is, the way you would in a design tool. Review feedback stops being "third paragraph, second sentence, the one about pricing".
:::

Feedback belongs with the content, not in a chat thread that scrolls away.

- Comment on the page as a whole, on **one specific field**, or click anywhere in the preview to pin a comment to that exact spot on the page.
- Type `@` followed by a colleague's name to **mention** them. They get a notification.
- Reply in threads, react with emojis, and **resolve** a discussion when it is settled. Resolved threads are hidden but can be brought back.

## Translating content

If your space has more than one language, switch the editor into **localization mode**. Original and translation then sit side by side, field by field, and anything still missing is clearly marked, including the SEO fields.

- A **copy from source** button pulls the original text over as a starting point.
- With AI switched on you can translate a single field or the whole page in one step, then refine the result.
- Everything you know from normal editing still applies. Translators see each other live, and edits made directly on the visual preview go into the translation, not into the original.
- Each language is published **on its own**. The German version can go live days after the English one.

**Working with an external translation agency.** *Export translations* produces a file in one of the common exchange formats: XLIFF (the standard format translation tools understand), CSV or Excel (spreadsheets), or JSON and YAML (formats developers prefer). Send it off, and when it comes back, *Import translations* applies it. You choose whether the translations arrive as drafts for review or are published immediately, and whether languages that don't exist yet should be created. A summary afterwards lists exactly what changed. The background is described in [Internationalization](../concepts/internationalization.md).

> **Tip:** translating the *same field* across many pages, or hunting down every missing meta description, is a job for [Mass Edit](mass-edit.md). It gives you one big table with a column per language, plus AI translation and export for the whole selection at once.
