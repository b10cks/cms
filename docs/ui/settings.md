---
description: "Every space setting explained in detail: what it does, what happens when you change it, and which choice to make."
---

# Space settings

**Settings** is where a space is configured. Most of it is decided once, at the start of a project, and then rarely touched again. This page walks through every screen and every single setting, says what it actually changes, and tells you which option to pick when the choice isn't obvious.

The settings area has its own menu:

| Screen | What lives there |
| --- | --- |
| **General** | Space name and icon, access tokens, the Get started guide, deleting the space |
| **Subscription** | Your plan and payment, on installations that bill ([guide](subscription.md)) |
| **Usage** | How much of your allowances the current and past periods used |
| **Configuration** | The four big configuration blocks: editor, languages, content, and media |
| **AI** | Whether AI is on, which model it uses, how it should write, and what it costs |
| **People** | Who has access to the space and with which role |
| **Backups** | Downloadable archives of the space |
| **Migrations** | Copying content into another space on the same installation |
| **Shares** | Every public download link in the space |
| **Plugins** | Custom field editors your developers built |

What you see depends on your role. A shorter menu means fewer permissions.

## General

### Space

**Name** is what the space is called throughout the app and in the space switcher. Changing it is safe and affects nothing but the label.

**Icon** is the small image next to the name. It exists so people who work in six spaces stop opening the wrong one. Upload any image, or remove it to fall back to the default.

**Space ID** is the technical identifier, shown read-only with a copy button. Developers need it for configuration, and support will ask for it. It never changes, not even when you rename the space.

**Server location** shows where the space's data is stored, either Europe or the United States. It is displayed for your records and cannot be changed after the space was created, because moving data between regions means moving a database. If you need a different region, create the space there and use a [migration](#migrations) to move the content over.

### Access tokens

::: tip Highlight
A token is what makes your content public in a controlled way. It is read-only by design: even if one leaks, nobody can change anything with it. And because a token can be revoked in one click, cutting off a compromised integration takes seconds, not a deployment.
:::

A **token** is a long password your website uses to read content from b10cks. Rather than a person signing in, the website presents its token with every request and gets content back. Without a token your site has nothing to display.

To create one, type a name that says where it will be used, for example "Website production" or "Staging preview", and click **Generate Token**. Naming matters more than it looks: in a year, "Token 3" tells nobody whether revoking it breaks the live site.

**Allow draft preview** is the one option on a new token. Normally a token only ever sees published content, which is exactly what you want for a public website. Switch this on and the token may also request unpublished drafts, which is what a preview environment needs to show work in progress. Only enable it for tokens that are used by preview environments, never for the token on your public site, or a draft could reach visitors.

The table below lists every token with its value abbreviated, how often it was used, when it was last used, and when it was created. The copy button puts the full value on your clipboard, so a token can be copied again later when you set up another environment. That also means anybody who can open this screen can read your tokens, which is deliberate but worth knowing. The delete button revokes a token immediately, and whatever was using it stops working at once. That is the point: if a token leaks, revoke it, then issue a new one.

The "last used" column is the quickest way to find tokens nobody needs any more. Background: [Access tokens and caching](../concepts/access-tokens.md).

### Onboarding

A switch that shows or hides the **Get started** guide in the main menu. It applies to the whole space rather than to you personally, so hiding it hides it for all your colleagues too. Bring it back here at any time.

### Danger zone

**Delete entire space** removes the space and everything in it: all content and its history, all files, all settings, all tokens. There is no undo and no support recovery. You have to type the space name to confirm. If you only want to stop working in a space, consider taking a [backup](#backups) first.

## Configuration

Four blocks on one screen, in this order.

### Editor

**Environments** are the addresses of your website that the editor uses for its live preview. Give each one a name and its address, for example *Local* pointing at a developer's own machine, *Staging* at the test server, and *Production* at the live site. Only `http` and `https` addresses are accepted.

Set up at least one and the editor gains a preview panel that shows your real website next to the form, with your real design. Set up none and the editor still works, just without the preview.

**Default environment** picks which one is used for people who never chose their own. Every person can override it with a personal preference, so developers can sit on *Local* while the editorial team sees *Staging*.

**Visual editor** switches on the click-on-the-page experience: clicking a section in the preview jumps to its fields, and simple text can be edited directly on the page. Switch it off and the preview becomes a read-only view. Some websites are not wired up for visual editing, and this is how you hide a feature that would otherwise look broken.

### Internationalization

This block is the one to plan before you write content, because it shapes your web addresses.

**Default language** is the original your content is written in, given as a code such as `en`. It is the language new content is created in, the one everything else falls back to, and the source AI translates from. Pick the language of your main audience.

**Languages** is the list of additional languages, each with a code such as `de` and a readable label such as "German". Every language you add here appears in the editor's language switcher and in [Mass Edit](mass-edit.md). Each one carries three settings of its own:

- **Mode** decides how a translation relates to the original. *Overlay* means the translation only overrides the fields somebody actually translated, and everything else keeps showing the original. That is what you want for a site where the German version is the English one with translated text. *Independent* means the translation is maintained as its own page, free to differ in structure and not inheriting anything. Choose that when your markets genuinely publish different content.
- **Fallback** is the language used when a field has no translation. Usually the default language, but a chain such as Swiss German falling back to German rather than English is often more useful.
- **Hidden** keeps a language out of the pickers in the app. Useful for a language somebody is preparing but nobody should be filling in yet.

**Site locales** map an address segment to a language, which decouples the shape of your URLs from the languages in the CMS. One language can be served under several segments, so German content can appear under `at-de`, `ch-de`, and `de-de` while being maintained exactly once. Each entry has the segment, the language it renders, and an optional display name like "Austria (DE)". Leave the list empty and the slug strategy below decides instead.

**Slug strategy** decides whether a language code is put in front of your addresses:

| Strategy | Result |
| --- | --- |
| **Prepend translations** | The default language stays at `/pricing`, translations get `/de/pricing`. The usual choice. |
| **Always prepend** | Every language is prefixed, so English lives at `/en/pricing` too. Pick this when no language should look privileged. |
| **Never** | No prefix at all. For sites that separate languages by domain, or run a single language. |

Concepts and the delivery behaviour: [Internationalization](../concepts/internationalization.md).

### Content

**Default content block** is the content type pre-selected when somebody creates a new page. Set it to whatever you create most, usually "Page", and everybody saves a click hundreds of times.

**Filter hidden blocks from the API** controls whether sections marked as hidden are stripped out of what your website receives. Off by default, which means a hidden section is still delivered and your website decides what to do with it. Switch it on when "hidden" should mean "invisible to the outside world" rather than "hidden in the editor".

**Manual content sorting** decides whether pages can be dragged into an order at all. Off by default, and pages are then listed alphabetically by name everywhere, including in what your website receives. Switch it on when the order carries meaning, for example a navigation menu or a curated list. Individual pages can still define their own sorting rule for their children in the editor's [Config tab](content.md#the-config-tab).

**Numbering after deletion** matters only if your content types use automatically numbered fields, for example invoice or ticket numbers.

| Option | Behaviour |
| --- | --- |
| **Never reuse numbers** | Deleting an entry leaves a permanent gap. Number 42 is never handed out again, and restoring the deleted entry always gives it 42 back. |
| **Reuse freed numbers** | The next new entry fills the gap. If a restored entry finds its number taken meanwhile, it is renumbered. |

Pick *never reuse* whenever the number is a reference somebody may quote outside the system, such as an invoice number. Pick *reuse* when the numbers are cosmetic and you dislike gaps.

**Sitemap extraction** is how b10cks builds the machine-readable list of your public pages that search engines read.

> **Two things to know first.** A *sitemap* is a file listing your public addresses so search engines can discover them. And *noindex* is a marker on a page that asks search engines to leave it out.

For each content type that belongs in the sitemap, add a mapping with two values: the **block type**, meaning which kind of content it is, and the **meta path**, meaning where inside that content the search-engine information lives. The path is usually just `meta`, matching the meta field your team put on the block. b10cks reads the title, description, and noindex marker from there, and pages marked noindex are left out.

**Named sitemaps** go one step further. Instead of a single list, you define several, each with its own address and its own selection of content types. One for pages and one for news, for example, which is what large sites do so that news can be submitted separately. Each named sitemap gets a slug, which becomes part of its address, and its own list of mappings.

### Asset library

This block decides what information every uploaded file should carry.

**Metadata fields** are yours to define. Each has a key, which is the technical name such as `alt`, and a label, which is what people read, such as "Alt Text". Mark a field **required** and files cannot be saved without it. Required applies to the main language only, so translated values stay optional and nobody is blocked from uploading because the Italian description is missing. The default fields cannot be removed.

**Rights and licensing** is an optional group you add with one click. It covers the copyright holder, the licence type, licence notes, usage restrictions, and an expiry date. Add it and every file gains a rights status you can filter by, which turns "are we still allowed to use this photo" from an email thread into a filter.

Everything here is the space-wide default. Individual folders in the media library can require more, relax what they inherit, switch fields off, and add fields that only exist inside them, which is described under [folder rules](../concepts/assets.md#per-folder-metadata-rules).

## AI

**Enable AI features** is the master switch. Off, and none of the AI buttons appear anywhere in the space.

**AI model** picks which model does the work. The list shows each model's provider, how much text it can consider at once, and a token multiplier that tells you how expensive it is relative to the others, along with tags such as *Free*, *New*, or *Premium*. A cheap fast model is fine for translations. A stronger one earns its cost on long-form drafting.

**AI instructions** is a free-text field where you describe how the AI should behave in this space: tone of voice, style rules, domain vocabulary, things it must never do. It is applied to every AI request in the space, which makes it the single most effective setting on this page. "Professional but approachable. Active voice. Never write in the first person. Always give a concrete example." beats correcting the same thing by hand fifty times.

**Temperature** is a slider from precise to creative. Low values make the model predictable and repetitive, which is what you want for translations and rewrites. Higher values make it more inventive, which suits brainstorming and headlines.

**Max tokens** caps how long a single answer may get. Tokens are roughly word fragments, so this is a length limit expressed in the model's own units. Raise it if answers get cut off mid-sentence.

**AI configurations** let you save all of the above as named profiles, for example "Technical editor" with low temperature and strict instructions, next to "Marketing copy" with a livelier setting. One is marked as the default. Wherever AI can be triggered, you can pick a configuration for that specific job, which is how one space serves a documentation team and a campaign team without either compromising.

**AI usage** shows what the current billing period has spent against your plan's allowance, updating live, with the date it resets. This is the one allowance that genuinely stops when it is used up, so it is worth glancing at.

## People

**Members** lists everybody with access to the space and their role. You can search the list, change somebody's role, and remove people, individually or several at once.

**Add member** invites somebody by name, email address, and role. If they have no account yet, the invitation creates one when they accept. The invitation is sent in the language you had selected when creating it, and somebody who signs up through it starts the app in that language, which matters when you invite a translator who does not read English.

**Pending invites** lists invitations that have been sent but not accepted. Resend one if it got lost, or revoke it if it went to the wrong address.

Roles here apply to this space. Where the space belongs to a team, team members may already have access through the team, and teams can define their own additional roles. Both are covered in [Account, teams, and spaces](account.md).

## Backups

A backup is a single archive containing the space's database content and the files from its media library. It is built in the background, so you can start one and close the tab.

When creating one you set:

- **Name and description**, so a list of twenty backups is still readable in six months.
- **Password protection**, optional. Set a password and the archive is encrypted with AES-256, which is what you want before a backup leaves your building. Lose the password and the archive is unreadable, with no way around it.
- **Expiration date**, after which the backup is cleaned up automatically. Backups take storage, and storage counts against your plan.
- **Recipients**, optional email addresses that receive the finished backup.

The history lists every backup with its state (pending, active, expired, or failed), its progress while it builds, its size, and a download link.

::: warning A backup is an archive, not a restore button
There is no "restore" button in the app. The archive is your safety copy to keep somewhere else, and putting it back is a job for whoever operates your installation. If you are running b10cks yourself, [Backup and restore](../self-hosting/backup.md) covers the operator's side.
:::

## Migrations

A **migration** copies content from this space into another space on the same installation, directly, with no export and import by hand. The usual case is a staging space where a new structure was built and now needs to reach the live space.

**Scope** decides what travels: content types, block templates, pages, file information, data sets and their entries, and redirects. Copying pages without their content types risks landing in a space that doesn't know what those pages are, so the dialog warns you about that combination.

**Conflict strategy** decides what happens when something already exists in the target:

| Strategy | Behaviour |
| --- | --- |
| **Skip existing** | Only create what is missing. The safest option, and the right one for a first run. |
| **Overwrite** | The source always wins. Use it when this space is the single source of truth. |
| **Merge newer** | Overwrite only where the source version is more recent, leaving newer work in the target alone. |

::: tip Highlight
Running a migration twice is safe. Records are matched across spaces by a stable identifier, so a second run updates what it created the first time instead of producing duplicates. That makes a migration something you can repeat as a routine sync, not a one-shot with held breath.
:::

Automatic numbering travels too: counters behind numbered fields continue in the target space instead of restarting at one, and a number that collides with one the target already uses is reported as an error rather than stopping the whole run.

Each migration shows its progress, from pending through processing to completed or failed.

## Shares

Every public download link in the space, in one table: what it points at (a collection, a folder, or a selection of files), whether it is password protected, how often it was viewed and downloaded, when it expires, and whether it is still active. From here you can copy a link, open it, edit it, revoke it, or delete it.

Links are created over in the media library ([sharing files](assets.md#sharing-files-with-people-outside-your-team)). This screen is where you review them later, which matters because a link you created for one campaign keeps working until somebody stops it. Background: [sharing and downloads](../concepts/assets.md#sharing--downloads).

## Plugins

A **field plugin** is a custom editing widget your developers build when none of the built-in field types fits: a colour picker limited to your brand palette, a seat-map editor, a lookup into another system. It runs inside the editor in a sealed frame, so a plugin cannot reach your session or the rest of the app.

- **Create** a plugin with a name and a **handle**, its permanent technical name. It stays permanent on purpose, so content types referring to it never break.
- **Publish a bundle**, meaning the plugin's code file, up to 1.5 MB. Publishing puts a new version into use immediately. *Save* changes only the name and description and leaves the running version alone.
- **Dev mode** points the editor at a developer's own machine while they build. Only local addresses are accepted, so nobody can point a space at code on the open internet.
- The table shows each plugin's status, which is *draft*, *dev*, or *published*, and how large its code is.

Developers will want the [field plugins guide](../guides/field-plugins.md).
