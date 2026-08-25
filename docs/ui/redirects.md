---
description: "Redirects: sending visitors from an old web address to the new one, keeping track of which rules are actually used, and importing lists in bulk."
---

# Redirects

When a page moves to a new address, the old address doesn't stop existing. It sits in people's bookmarks, in old newsletters, in printed brochures, and in Google's index. Without a rule, everybody arriving there gets an error page.

A **redirect** is that rule: "anyone asking for the old address should be taken to this new one instead". Visitors barely notice, and search engines are told to move their record over to the new address. Setting one up needs no developer. [How the rules reach your website](../concepts/redirects.md).

## Creating a redirect

A rule has three parts:

- **Source path**, the old address you want to catch, for example `/old-blog/hello`. Write the part after your domain name, starting with a slash.
- **Target path**, where those visitors should end up.
- **Status code**, which tells browsers and search engines what kind of move this is:

| Code | Meaning | Use it when |
| --- | --- | --- |
| **301** Moved Permanently | The page has moved for good | The normal choice. Search engines transfer their ranking to the new address. |
| **308** Permanent Redirect | Same as 301, stricter about the technical details | A developer asked for it |
| **302** Found | The move is temporary | A campaign page, a maintenance detour |
| **307** Temporary Redirect | Same as 302, stricter about the technical details | A developer asked for it |
| **303** See Other | Send the visitor onwards after something was submitted | Rarely needed by hand |

If in doubt, use 301 for a real move and 302 for something you intend to undo.

## Managing rules

::: tip Highlight
Every rule counts its own hits. That turns a redirect list from write-only clutter into something you can actually clean up: the rules nobody has used in a year are visible, and so are the ones carrying real traffic that must never be deleted.
:::

The table lists every rule together with how often it was used and when it was last used. Rules that were never used show *Never*, which makes stale entries easy to spot during a clean-up. For a single rule or a whole selection you can:

- **Edit** its target or status code,
- **Reset the statistics**, which sets the counters back to zero,
- and **Delete** it.

## Importing many rules at once

During a site migration you rarely have three redirects. You have four hundred, usually in a spreadsheet from the agency. The import reads such a list in one go, and you decide how existing rules should be treated.

## How redirects actually take effect

Worth knowing so nobody debugs the wrong thing: the redirect does not happen inside b10cks. b10cks stores the rules, and your website fetches them and performs the actual forwarding. That means a new rule takes effect as soon as your website picks up the change, which is usually immediate but depends on how your site was built. Your developers will find the details in the [Nuxt example](../guides/nuxt.md#7-redirects) and on the [concept page](../concepts/redirects.md#delivery).
