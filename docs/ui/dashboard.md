---
description: "Your first tour of b10cks: signing in, what the menu items mean, and the small helpers that make everyday work faster."
---

# Finding your way around

This page is the guided tour. It explains what you see after signing in, what each item in the menu is for, and which small helpers are worth knowing on day one. No technical background needed.

A few words come up again and again, so here they are up front:

| Word | What it means |
| --- | --- |
| **Space** | One website or project, with its own pages, images, team, and settings. Most people work in a single space. |
| **Entry** | One piece of content. Usually a page, but it can also be a blog post, a product, or a container for settings. |
| **Draft** | Content you have saved but not made public yet. Only your team can see it. |
| **Publish** | Make content visible to the visitors of your website. |
| **Block** | A type of content your team has designed, such as "Page", "Hero image", or "Testimonial". Blocks are the building bricks your pages are made of. |

## Signing in and choosing a space

Sign in with your email address and password. If your organization uses extra login security, you are also asked for a **two-factor code**, the six-digit number from an app on your phone that changes every 30 seconds. Some organizations allow signing in with a Google or GitHub account instead, and larger companies may send you through their own company login.

After signing in you land on the **spaces overview**: a list of every space you are allowed to open. You may see one, or a dozen, depending on how many projects you work on. A space appears here either because someone invited you to it directly, or because you belong to a team that owns it.

Click a space to open it. The web address in your browser always contains the space you are in, so you can bookmark a page or send a link to a colleague and it takes them exactly where you were.

## The menu on the left

Once you are inside a space, the bar on the left is your main navigation. Here is what each entry is for.

| Menu item | What you find there |
| --- | --- |
| **Get started** | A short checklist for brand new spaces. It disappears once somebody hides it ([more below](#the-get-started-checklist)) |
| **Home** | An overview of the space with recent activity and usage numbers |
| **Content** | Your pages and the editor. This is where most daily work happens ([guide](content.md)) |
| **Mass Edit** | A spreadsheet-style view for changing one field across many pages at once ([guide](mass-edit.md)) |
| **Canvas** | A whiteboard for planning and rearranging whole sections of the site ([guide](canvas.md)) |
| **Blocks** | The building bricks your pages are made of, and the fields each one has ([guide](blocks.md)) |
| **Assets** | Your media library: images, videos, PDFs, and other files ([guide](assets.md)) |
| **Data sets** | Reusable lists such as countries, categories, or departments ([guide](data-sources.md)) |
| **Icons** | Your own set of icons, uploaded once and used everywhere ([guide](icons.md)) |
| **Redirects** | Forwarding rules for pages that moved to a new address ([guide](redirects.md)) |
| **Releases** | Groups of changes that should go live together, at one moment ([guide](releases.md)) |
| **Automations** | Rules in the style of "when this happens, do that" ([guide](automations.md)) |
| **Audit Log** | A record of who changed what, and when ([guide](audit-logs.md)) |
| **Settings** | Everything that configures the space ([guide](settings.md)) |

You will not necessarily see all of these. The menu only shows what your role allows you to open, so a shorter list means fewer permissions, not a broken installation. If you need something that isn't there, ask whoever administers your space.

The menu can be switched between a wide version with labels and a narrow version with icons only, whichever you prefer.

## The Get started checklist

A brand new space opens with **Get started**, a six-step checklist that leads from an empty space to content appearing on a real website. The steps are: create the space, create an access token so your website is allowed to read the content, choose the technology your website is built with, copy the setup command for it, define your first blocks, and invite your colleagues.

The steps tick themselves off by looking at the space itself, rather than by you clicking "done". If a token already exists, if blocks are already defined, or if a colleague invited somebody last week, those steps count as finished. That way the checklist stays truthful even when the work was done by someone else, or somewhere else.

Hiding the checklist removes it from the menu for **everyone** in the space, so it needs the same permission as changing the space itself. It can be brought back later under **Settings → General**.

## The search box that jumps anywhere

Press <kbd>⌘K</kbd> on a Mac, or <kbd>Ctrl K</kbd> on Windows and Linux, to open the command palette. It is a search box that finds pages, blocks, images, and settings screens, and it can run common actions directly. Start typing what you are looking for and press <kbd>Enter</kbd> on the result you want. If you prefer the keyboard over the mouse, this is the fastest way to move around.

## Notifications

The bell icon collects messages meant for you: somebody mentioned you in a comment, a long-running job such as an import, an export, or a backup has finished, or something happened in a workflow you are part of. Depending on the type, a notification may also arrive by email. **Mark all read** clears the counter without deleting anything.

## Working at the same time as your colleagues

::: tip Highlight
b10cks is built to be used by several people at once, everywhere, not just in one clever screen. Nothing is ever locked because somebody else opened it first.
:::

The whole app keeps itself up to date. Lists refresh on their own when a colleague changes something, so you rarely need to reload a page, and the number on the notification bell arrives without a refresh too.

Where it goes furthest is in the two places people work together most:

- **In the content editor**, colleagues appear as coloured profile pictures, the field somebody is in is outlined in their colour, and their typing shows up on your screen as they type it. See [working together, live](content.md#working-together-live).
- **On the [Canvas](canvas.md)**, colleagues appear as named cursors moving across the board, like a shared whiteboard.

Two people can safely work on the same page at the same time. The editor keeps track of whose unsaved changes are whose, and asks before saving somebody else's work along with yours.
