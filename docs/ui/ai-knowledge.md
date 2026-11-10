---
description: "What skills, style guides and documents are, when to use which, how to set them up, and the mistakes to avoid."
---

# Skills and knowledge

Out of the box the AI writes competent text that could come from any company. Two things in **Settings → AI** make it write like yours:

- A **skill** says how to do a task.
- **Knowledge** says what to know and how to sound. It comes in two kinds: a **style guide** and a **document**.

Most spaces need one style guide per language, and nothing else. Add the rest when you catch yourself correcting the same thing twice.

## When to use what

Start from what you keep correcting.

| You want to say | Use | Why there |
| --- | --- | --- |
| "We write in active voice and never in the first person." | [Style guide](#style-guides) | Applies to every run |
| "It's always *Warenkorb*, never *Cart*." | Style guide of that language | Read on every run in that language |
| "*Acme Cloud* stays as written, in every language." | Style guide without a language | Read on every run |
| "The bed is 180 cm wide. Returns are free for 30 days." | [Document](#documents) | Facts the AI looks up when a task needs them |
| "A product description has a hook, three benefits and a call to action." | [Skill](#skills) | A repeatable task, with its own button |
| "For this one batch, keep it under 50 words." | The prompt when you start the task | It only matters once |
| "The marketing team wants a livelier tone and a stronger model." | [AI configuration](settings.md#ai) | A model and its settings, picked per job |

Two questions settle most cases:

1. **Must it hold every time?** Nothing checks the AI's text afterwards. A rule in a style guide is followed most of the time, so read the drafts.
2. **Does every run need it?** A style guide is read on every run, whether it helps or not, and you pay for that. If only some tasks need it, it is a document or a skill.

## How they work together

When a run starts, it takes the published versions of everything that applies and keeps them to the end. Saving a change affects new runs. A run already in progress finishes with what it started with.

| | Reaches the AI | Costs on every run? |
| --- | --- | --- |
| Style guide | Always, in full | Yes, its full length |
| Document | Its title and description on every run, its text when the AI reads it | One line |
| Skill | Its instructions when it runs, its name and description otherwise | Only when used |

## Space or team

Each of these can belong to a space or to a team. A team's skills and knowledge apply to all of its spaces and to the spaces of the teams below it.

The nearest one wins: space first, then the team, then its parent teams, then what ships with b10cks. A style guide or document with the same web-friendly name (slug) replaces the one further up.

Put your brand voice and company-wide terms in the top team, so a new space starts right. Keep what belongs to one site in that space.

Changing any of this needs permission to manage AI. Inherited items open read-only, and their source column says who owns them.

## Style guides

A style guide is a page of Markdown that the AI reads on every run in its scope. Create one with **Knowledge → New → New style guide**, write it, and press **Publish**. A draft does nothing until it is published.

Good rules are ones someone could check by reading the text:

```markdown
# How we write

- Address the reader as "you". Never write "we" about features.
- Sentences under 20 words where possible.
- Lead with what the reader gets, then how it works.
- No exclamation marks. No "easy" or "simple".
```

**Do**

- Keep it to about a page. Each extra paragraph is paid for on every run and dilutes the rest.
- Stay under 20,000 characters. A style guide reaches the AI whole on every run, so a longer one cannot be saved or published.
- Give an example when a rule is hard to state.
- Say when a rule applies only to one language, or give the guide a language. A guide for `nl` holds the Dutch wording and is read on every run that writes `nl` or a region of it, such as `nl-NL`. Name the word to use and the word never to use: "*samenstelling* for layers, never *opbouw*".

**Avoid**

- Long word lists. Keep the terms people actually get wrong, with what to write instead.
- Product facts. They belong in a document, so they don't weigh on every run.
- "Be engaging and professional." It changes nothing the AI wasn't already doing.
- Several long style guides stacked up through team layers. All of them are read.

## Documents

A document is reference material the AI searches when a task needs it: specifications, policies, positioning, FAQs. Write Markdown directly, or upload a PDF, Word, Markdown or text file of up to 20 MB. After an upload, the text is extracted into the draft. Read it, fix what came out wrong, and press **Publish**.

Every run lists the documents in its scope by title and description, so the AI knows what exists before it reads one. Write the description as one sentence on what the document covers and when to read it, like "Warranty periods per product line and how to claim them." It is what the AI decides by. Documents from before descriptions existed start with their title as description, so give them a real one.

To find a section inside a long document, the AI can also search. The search matches words. It does not guess meaning, and each hit is a short excerpt under its heading.

**Do**

- Use clear headings and the words your editors would use. "Return policy" gets found when someone asks about returns only if the word appears.
- Keep one topic per document, with a title that says what it covers.
- Check the extracted text of a PDF. Tables and columns often come out scrambled.

**Avoid**

- Rules that must always apply. A document is read only if the AI looks. A rule belongs in the style guide.
- Copying your website in. The AI can already read entries in your space.
- Old versions next to new ones. The AI can't tell which is current. Replace the file instead, or switch the old one off.

Some skills only work from what they are given and don't search: **Describe images** and **Translate data entries** never read documents. Check the tools in the skill's raw view if a document seems to be ignored.

## Skills

A skill is a set of instructions for one task. b10cks ships with skills for rewriting, shortening, translating, meta tags, describing images and more. Those are the entries you see under **Ask AI**.

A skill has a name, a description the AI uses to pick it, instructions, and **where it appears**: text selection, the command palette, bulk actions, the Canvas and so on. Add a skill to a place, publish it, and it shows up there with no developer involved.

There are two ways to get your own:

- **Customize** a built-in or team skill. You get a copy for this space, which replaces the original once published. To go back, delete your copy.
- **New skill** for a task of your own.

```markdown
---
name: product-description
description: Writes a product description from specs and bullet points.
---

Write a product description from the specs in the context.

1. Start with one sentence on what the reader gets.
2. Three benefits as short paragraphs, each backed by one spec.
3. End with a call to action.

Under 120 words. Never invent a spec. If one is missing, leave that benefit out.
```

The form covers everyday editing. The raw `SKILL.md` view also offers settings such as which tools the skill may use, which block types it applies to, and which AI configuration it runs on. A skill can hold supporting files that the AI reads when needed: up to 20 files, 64 KB each and 256 KB in total. Files are only ever read as text, and scripts in them never run.

To run a skill on a timetable, add it under **Schedules**. It must be a space or team skill that appears in bulk actions for that kind of item and may propose updates. A schedule acts as the person who last saved it and stops at its budget.

**Do**

- Describe the steps and what "done" looks like, not the personality of the writer.
- Write the description for the task it does. The AI reads it to choose a skill, and people read it in the list.
- Tell it what to do when information is missing, so it doesn't make things up.
- Try it on real content before you add it to every menu.

**Avoid**

- Customizing a built-in to change tone or terms. The style guide does that for every skill at once, and your copy no longer picks up improvements to the original.
- Repeating the style guide in the instructions. It costs tokens and goes out of date.
- Giving a skill every tool. Fewer tools mean faster, cheaper and more predictable runs.
- Relying on a skill for rules that must hold. Which languages, fields and entries may be written is enforced by the tools, and the instructions can't widen it.

## A sensible order

1. **Write a one-page style guide**, at the top team if you have several spaces, plus one per language for its wording.
2. **Run a real task** from [Ask AI](ai.md) and read the drafts.
3. **Sort each correction**: a word or a habit goes in the style guide, a fact in a document.
4. **Write a skill** once you have explained the same task three times.

## Related

- [Working with AI](ai.md): starting tasks, what gets written where, conflicts
- [Space settings](settings.md#ai): models, configurations and usage
