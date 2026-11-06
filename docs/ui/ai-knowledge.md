---
description: "What skills, style guides, documents and glossaries are, when to use which, how to set them up, and the mistakes to avoid."
---

# Skills, knowledge and glossary

Out of the box the AI writes competent text that could come from any company. Three things in **Settings → AI** make it write like yours:

- A **skill** says how to do a task.
- **Knowledge** says what to know and how to sound. It comes in two kinds: a **style guide** and a **document**.
- A **glossary** says which words to use and which never to use.

Most spaces need one style guide and a short glossary, and nothing else. Add the rest when you catch yourself correcting the same thing twice.

## When to use what

Start from what you keep correcting.

| You want to say | Use | Why there |
| --- | --- | --- |
| "We write in active voice and never in the first person." | [Style guide](#style-guides) | Applies to every run |
| "It's always *Warenkorb*, never *Cart*." | [Glossary](#glossaries) | The only one that is checked afterwards |
| "*Acme Cloud* stays as written, in every language." | Glossary, with Keep exact | Same |
| "We never write *climate neutral*." | Glossary, a row with only Avoid | Same |
| "The bed is 180 cm wide. Returns are free for 30 days." | [Document](#documents) | Facts the AI looks up when a task needs them |
| "A product description has a hook, three benefits and a call to action." | [Skill](#skills) | A repeatable task, with its own button |
| "For this one batch, keep it under 50 words." | The prompt when you start the task | It only matters once |
| "The marketing team wants a livelier tone and a stronger model." | [AI configuration](settings.md#ai) | A model and its settings, picked per job |

Two questions settle most cases:

1. **Must it hold every time?** Only wording can be checked. If a sentence in your style guide is really about one word, move it to the glossary.
2. **Does every run need it?** A style guide is read on every run, whether it helps or not, and you pay for that. If only some tasks need it, it is a document or a skill.

## How they work together

When a run starts, it takes the published versions of everything that applies and keeps them to the end. Saving a change affects new runs. A run already in progress finishes with what it started with.

| | Reaches the AI | Checked afterwards? | Costs on every run? |
| --- | --- | --- | --- |
| Style guide | Always, in full | No | Yes, its full length |
| Document | When the AI searches for it, as short excerpts | No | No |
| Glossary | Only the rows the text touches, plus a short list of banned wording | Yes, for wording | Very little |
| Skill | Its instructions when it runs, its name and description otherwise | The tools enforce their own rules | Only when used |

## Space or team

Each of these can belong to a space or to a team. A team's skills and knowledge apply to all of its spaces and to the spaces of the teams below it.

The nearest one wins: space first, then the team, then its parent teams, then what ships with b10cks. A style guide or document with the same web-friendly name (slug) replaces the one further up. In a glossary, the nearest wording replaces per language.

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
- Give an example when a rule is hard to state.
- Say when a rule applies only to one language.

**Avoid**

- Word lists. They belong in the glossary, where they get checked.
- Product facts. They belong in a document, so they don't weigh on every run.
- "Be engaging and professional." It changes nothing the AI wasn't already doing.
- Several long style guides stacked up through team layers. All of them are read.

## Documents

A document is reference material the AI searches when a task needs it: specifications, policies, positioning, FAQs. Write Markdown directly, or upload a PDF, Word, Markdown or text file of up to 20 MB. After an upload, the text is extracted into the draft. Read it, fix what came out wrong, and press **Publish**.

The search matches words. It does not guess meaning, and each hit is a short excerpt under its heading.

**Do**

- Use clear headings and the words your editors would use. "Return policy" gets found when someone asks about returns only if the word appears.
- Keep one topic per document, with a title that says what it covers.
- Check the extracted text of a PDF. Tables and columns often come out scrambled.

**Avoid**

- Rules that must always apply. A document is read only if the AI looks. A rule belongs in the style guide or glossary.
- Copying your website in. The AI can already read entries in your space.
- Old versions next to new ones. The AI can't tell which is current. Replace the file instead, or switch the old one off.

Some skills only work from what they are given and don't search: **Describe images** and **Translate data entries** never read documents. Check the tools in the skill's raw view if a document seems to be ignored.

## Glossaries

A glossary holds the wording rules for your brand, one row per meaning. Each language in a row has up to three lists, and the row has two settings:

| Field | Meaning |
| --- | --- |
| **Use** | The wording to write in this language. |
| **Avoid** | Wording never to write. An entry ending in `*` also covers longer forms: `klimaneutral*` catches `klimaneutrale`. |
| **Also written as** | Other ways a text may spell this term, such as inflected forms. It counts as the term itself. |
| **Keep exact** | For names like *Acme Cloud*: stays as written, in the same upper and lower case. When translating, a language with its own Use gets that wording. |
| **Note** | One short sentence for the AI, at most 200 characters, read whenever the row comes up. |

A row with Avoid and no Use is a **ban**: the AI must not write that wording, and there is no replacement to suggest.

A regional language like `de-AT` replaces its base `de` entirely. If it has no wording of its own in a row, it uses the base language.

### Creating one

**Knowledge → New → New glossary**, give it a name, then add terms by hand or import a spreadsheet. To bring in an existing list, see [Glossary sheets](../guides/glossary-sheet.md). You can turn a glossary off with its switch without deleting it.

Saving a term applies right away to new runs, with no publish step. The coverage tiles above the table show how many terms each language has. Press a language with gaps to list them.

### What the AI is told and what gets checked

The AI isn't sent your whole glossary. It gets one line for each row the text touches, and a short list of banned wording so it doesn't use a word for the first time by accident. After it writes:

| Result | What happens |
| --- | --- |
| Avoid wording in the text | Reported back to the AI, which may fix it once. If it stays, it is flagged. |
| A Keep exact name that was changed | Same. |
| The Use wording is missing | A hint to the AI only. Nothing is flagged, because an inflected form or a rephrased sentence is often fine. |
| A Note wasn't followed | Nothing. Nobody checks notes. |

Flags show in the content tree and when you publish. They never stop you publishing, and they clear when the wording leaves the field or the draft is published.

### Try a text

From the glossary's menu, **Try a text** shows what the AI would be told about a text, and what would be flagged in a text you paste as its output. It calls no AI and costs nothing. Use it after adding terms, especially with `*` entries, to see what they catch. In a space, you can check this glossary alone or everything the space's runs use.

### Learning from your edits

When an editor changes a word the AI wrote and then publishes, a small model call looks for a deliberate choice of term. Candidates the glossary doesn't know yet wait in the suggestions thread with the evidence: "The AI wrote X, an editor changed it to Y." Someone who manages AI adds them to a glossary or dismisses them. Nothing enters a glossary by itself.

**Do**

- Start with the 20 terms people get wrong, not the 2000 you have. You can always add more.
- Use one row per meaning. If two wordings are both fine, the second one goes in Also written as, not in Avoid.
- Write a real replacement in Use where you can. A flag that says what to write instead is fixed quickly.
- Use `*` on the shortest stem that is still wrong, and test it with Try a text.

**Avoid**

- Dictionary words nobody gets wrong. Each row that matches adds to the prompt and to the noise.
- An Avoid entry that also matches your own Use, such as `Händler*` with `Händler`. The save is refused.
- Long explanations in Note. Over 200 characters is refused. Move the explanation to a document.
- Trusting the glossary on facts. A text can follow every wording rule and still make a claim you can't back. Wording checks say nothing about whether a sentence is true, so reading the draft stays your job.

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

- Customizing a built-in to change tone or terms. The style guide and glossary do that for every skill at once, and your copy no longer picks up improvements to the original.
- Repeating the glossary or style guide in the instructions. It costs tokens and goes out of date.
- Giving a skill every tool. Fewer tools mean faster, cheaper and more predictable runs.
- Relying on a skill for rules that must hold. Which languages, fields and entries may be written is enforced by the tools, and the instructions can't widen it.

## A sensible order

1. **Write a one-page style guide**, at the top team if you have several spaces.
2. **Add a glossary** with your product names and the words you always correct, then test it with [Try a text](#try-a-text).
3. **Run a real task** from [Ask AI](ai.md) and read the drafts.
4. **Sort each correction**: a word goes in the glossary, a habit in the style guide, a fact in a document.
5. **Write a skill** once you have explained the same task three times.

## Related

- [Working with AI](ai.md): starting tasks, what gets written where, flags and conflicts
- [Glossary sheets](../guides/glossary-sheet.md): the spreadsheet format
- [Space settings](settings.md#ai): models, configurations and usage
