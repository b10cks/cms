---
description: "Start AI tasks from where you work, see what gets written where, and handle plans, budgets and conflicts."
---

# Working with AI

AI in b10cks sits inside the screens you already use. It helps with a sentence, a page, or thousands of entries, and it never publishes anything. A person always does that.

An administrator switches AI on under **Settings → AI**. To teach it your voice and your terms, see [Skills and knowledge](ai-knowledge.md).

## Where to start a task

| You are | Do this | Good for |
| --- | --- | --- |
| Editing text | Select the text and choose an action | Rewrite, shorten, expand, fix grammar, translate |
| Anywhere | Open the command palette and ask, or pick a skill | Questions and one-off jobs |
| In a list of entries, assets or data entries | Select some and press **Ask AI** | The same job across many items |
| In the translation or meta tags tools | Use the AI button there | Filling in translations and search-engine text |
| On a canvas | Ask for a change to the plan | Restructuring a section |

Longer jobs run in the background in a **thread**, which opens in the dock so you can follow along, answer questions and keep working.

## What gets written where

The rule is that AI writes directly to anything with a draft, and everything else waits for you.

| Target | What happens | Who decides |
| --- | --- | --- |
| Content entries | Written straight into the draft | You, when you publish |
| Text selected in the editor | Shown as a proposal with **Accept** and **Discard** | You, on the spot |
| Assets, folders, tags, data entries and blocks | Proposed in the thread, because they have no draft | You, by accepting |

Publishing is the review for content. Read the draft as you would a colleague's, compare it in version history if you want to see what changed, and publish when you're happy. If you don't like a change, restore an earlier version. There is no separate undo for a whole AI task.

Proposals are grouped, so you can accept similar changes together after looking at examples, or open any single one. Conflicts are left out of a group accept and need their own look.

## Small tasks and big ones

When you press **Ask AI** on a selection, the dialog shows the count, an estimated cost and the amount the run stops at.

- **A small task just runs.**
- **A big one shows a plan first.** That happens above 500 entries, or when the estimate is more than one run may spend. The dialog says which.

For a big task you say what should be true when it's done, the AI drafts a plan from a sample, and you adjust the plan and set a budget. You approve once, and it then works through up to 10,000 entries in batches without further questions, unless it needs an answer only you can give.

The scope is fixed when you approve. Entries added later aren't included, and they are listed separately so you can start another task for them.

What a plan can set:

- **Translations:** target languages, and which work to do: missing translations, empty fields, outdated translations, or fields still holding the source text. Only the affected fields change. Other edits, markup and placeholders stay.
- **Assets:** by default the AI only fills empty metadata and adds tags. Replacing existing values, removing tags and moving files each have to be switched on.
- **Rules:** any extra rules for this job.

## Budgets

Every run has a spending limit. When a planned task reaches its budget it pauses and keeps its work. Set a new total to continue. Nothing spends more by itself.

The whole space also draws on the AI allowance of its plan, shown under [Settings → AI](settings.md#ai).

## Conflicts

Someone changed a field after the AI read it. Your colleague's text stays, the AI doesn't overwrite it, and it continues with the other fields. The field stays closed to AI tasks until a person decides. Conflicts show in the content tree and when you publish.

Nothing checks the AI's wording afterwards. Your style guides steer it, but a draft can still use a word you avoid or get a fact wrong, so read what you publish.

## Getting better results

- **Say what done looks like.** "Every product has a German description under 60 words" works better than "translate the products".
- **Try a small selection first.** Read those drafts before you run a thousand entries.
- **Fix the cause, not the output.** If you correct the same word or habit twice, add it to the style guide. [Which one?](ai-knowledge.md#when-to-use-what)
- **Use schedules for jobs that recur**, such as new entries with missing translations, under **Settings → AI → Schedules**.

## Things to keep in mind

- Publishing a draft publishes whatever is in it, including AI text nobody read. Be careful with **Publish** on a bulk selection after a large task.
- AI text is only as current as what the AI could see. Facts it needs belong in a [document](ai-knowledge.md#documents), and it should be told to leave out what it can't confirm.
- Text inside your content, files and images is treated as data, not as instructions. A sentence hidden in a page shouldn't redirect the AI, and a run can only write what its task allows either way. Still read what comes back from outside material.
- There are no confidence scores, because a percentage nobody has calibrated would only look reassuring. Uncertain items are flagged with a reason instead.

## Related

- [Skills and knowledge](ai-knowledge.md)
- [Versions and publishing](../concepts/versions-and-publishing.md)
- [MCP server](../guides/mcp-server.md), for using b10cks from your own AI assistant
