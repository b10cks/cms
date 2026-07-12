---
description: "Creating and importing redirects, and tracking their hits."
---

# Redirects

When a page moves, visitors and search engines still knock on the old address. **Redirects** is where you send them to the right place — no developer required for the everyday case. ([How they reach your site](../concepts/redirects.md))

## Creating a redirect

A rule consists of:

- **Source path** — the incoming path to match, e.g. `/old-blog/hello`
- **Target path** — the destination
- **Status code** — `301` Moved Permanently, `302` Found, `303` See Other, `307` Temporary Redirect, or `308` Permanent Redirect

## Managing rules

The table lists all rules with usage statistics — hit count and last-used time (*Never* for unused rules) — so stale rules are easy to spot. Available actions, individually or on a multi-selection:

- **Edit** target or status code
- **Reset stats** — zero the usage counters
- **Delete**

## Import

Bulk-import redirect lists (e.g. during a site migration) with a configurable import mode controlling how existing rules are treated.

## Applying redirects in your frontend

Redirects don't execute inside the CMS — your frontend fetches them (`getRedirects()` map or `lookupRedirect()` single lookup) and issues the actual HTTP redirects. See the [Nuxt middleware pattern](../guides/nuxt.md#7-redirects) and the [concept page](../concepts/redirects.md#delivery).
