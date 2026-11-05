# W9 simple glossary

Implements [brief](brief.md) revision 4. Integration branch: `feat/ai-content-operations`. The columns involved have not shipped: they are not on main, so edit unreleased migrations in place. Earlier code stays in the tags `archive/w7-before-reduction` and `archive/w8-before-simplify`.

Sometimes less is more. A plain four-term glossary (term, translations, one banned word, one protected name, a short note) must feel natural, and it must be enough for a demanding one.

## Settled, do not reopen

**Row.** One meaning. Per language: Use, Avoid, Also written as. Shared: Keep exact, Note.

- A row with Avoid and no Use is a ban. Its findings have no replacement until someone adds one.
- A row's Use is the replacement for its Avoid wording in findings.
- Also written as counts as the term itself. There is no "context only" tier.
- `*` at the end of Avoid covers longer forms.
- Keep exact means protected and exact case, one flag. A language's own Use wins. The source wording stays only when the target language has none.
- One Note per row, at most 200 characters, always in the prompt and in lookup. Existing longer notes on old terms stay readable.
- Gone: status (required, banned, contextual), audience, category, per-language overrides, definition and notes split, the "internal" label pattern, the context group in the prompt.

**Sheet columns:** `concept, keep_exact, note`, then per language `term:<l>, avoid:<l>, also:<l>`. Lists separate on `;`. Only `concept` and one `term:` or `avoid:` column are required. Blank keeps, missing rows stay. The pre-W7 CSV header and TBX keep importing. In TBX, preferred and deprecated map to Use and Avoid.

**Starting.** One Start. Small tasks run. Above the run's entry limit or $2.00 estimated, the plan and budget show first, for one approval.

**Conflicts.** A field a person changed stays open for a person. The agent is told and does not retry that field.

**Flags.** Flagged wording and conflicts count as "needs you", and show in the content tree and at Publish, without blocking publish.

## Tasks

| Task | Outcome | Evidence | Depends on |
| --- | --- | --- | --- |
| S1 model | Row model, writer, sheet, prompt, checker, lookup, API, neutral fixtures | `w9-model.md` | |
| S2 editor | Editor rebuilt on the row: wording first, fewer filters, Try a text placement | `w9-editor.md` | S1 |
| F1 flow | One Start, correct copy, flags where people publish, no retry over people, data entry findings | `w9-flow.md` | |
| T1 prototype | Throwaway import of external terms, rendered check of the whole flow | `w9-prototype.md` | S1, S2, F1 |
