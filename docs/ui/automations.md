---
description: "Automations: rules that react to what happens in your space, the actions they trigger, and the history of every run."
---

# Automations

**Automations** are rules of the form "when this happens, do that". When a page is published, rebuild the website. Every night at three, send a digest email. When somebody deletes a product, tell the shop system.

They are what connects b10cks to the rest of your tools, so nobody has to remember to press a button after publishing, and no other system has to keep asking "anything new yet?".

An automation is built from two separate pieces, and it helps to know why: the **action** describes *where* something is sent and holds the credentials for it, and the **automation** describes *when* it happens. Keeping them apart means the address and password of your website's rebuild service are stored once, not copied into every rule that needs them.

> Building the receiving end? The technical details of what gets sent, how the `{{ }}` placeholders work, and how delivery and retries behave are in [Automations and webhooks](../concepts/automations.md).

## Actions, under Settings → Actions

An **action** is a reusable delivery step.

| Type | What it does |
| --- | --- |
| **Webhook** | Sends a message to a web address that another system is listening on. This is the usual way to tell a website to rebuild itself, or a cache to clear. |
| **Email** | Sends an email to a list of recipients |
| **Void** | Does nothing at all, on purpose. Useful for checking that a rule fires when you expect it to, without anybody receiving anything. |

Actions store their **secrets**, such as API keys and signing secrets, on the action itself. Once saved, a secret is never shown again, and several automations can safely reuse the same destination.

The actions table shows which automations use each action, how the last runs went, and when the last one was. An action that is still used by an automation cannot be deleted until you detach it, which prevents a rule from quietly losing its destination.

## Automations, the "when" part

An automation picks a **trigger**, links an **action**, and sets some guardrails.

### Triggers

| Trigger | Fires when |
| --- | --- |
| **Content published** | A page goes live. The message includes the full page, its previous state, and the timestamps. |
| **Content unpublished** | A live page is taken offline |
| **On insert, update, or delete** | Something of a chosen type is created, changed, or removed. For changes you can watch specific fields only, so a rule fires on a price change but not on a typo fix. |
| **Time based** | On a schedule, written as a cron expression, which is the standard notation for "every night at 3:00" or "every Monday at 9:00". Your developers will recognize it. |
| **Manual** | Only when a person runs it, from the automations page, from the content tree, or through the API |

Triggers can carry **conditions**, so the rule only fires when the page matches your criteria, and **payload values**, which are extra pieces of information sent along. Payload values can contain placeholders that are filled in from whatever triggered the rule, such as the page's address or its [cache tags](content.md#the-config-tab), which is how a cache is told to refresh exactly one page instead of everything.

### Content actions

A manual automation can be offered directly in the [content tree](content.md#the-content-tree). Switch on **Content action** in the trigger, and it appears in the right-click menu under **Actions**, ready to run against that one page. This is how "send this page to translation", "re-index this article", or "tell the team about this entry" become one click for the whole editorial team.

- **Limit to block types**, optionally, so a "Request translation" action is offered on articles but not on folders. Leave it empty to offer it everywhere. The limit is enforced on the server as well, not just hidden in the menu.
- The run receives **the full page as context**, exactly like the content-published trigger, so placeholders such as `{{ record.full_slug }}`{v-pre} and conditions on the page work unchanged.
- The **Actions** submenu appears for everyone with the *Trigger automations* permission, which the built-in owner, admin, and editor roles include. It is greyed out when no action fits the selected page. Being able to run an automation does not allow editing it.

### Guardrails

Automations can be switched off without being deleted, and they can carry an **execution limit** showing how many runs of an allowance are used and how many remain. That is your safety net against a misconfigured rule hammering somebody else's system a thousand times.

## Execution history

The **Execution History** lists every run across all automations, whether it is queued, running, completed, or failed. For each run you see where it came from (a trigger, a schedule, a person, or a replay), when it started, how long it took, what was sent, what came back, and the error details when something went wrong.

::: tip Highlight
**Replay** is the feature you will be grateful for at some point. Every run is stored with the exact data it was given, so after fixing a broken receiving system you can re-run what failed with its original payload, instead of recreating the situation that triggered it.
:::

**Replay** runs a past execution again with its original data. After fixing the receiving end, that is the quickest way to repair what was missed, without recreating the original situation.
