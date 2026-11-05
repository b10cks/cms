# W8 reduce and direct writes

Implements [brief](brief.md) revision 3. Integration branch: `feat/ai-content-operations`. Nothing being removed has shipped: none of these migrations is on main, so delete them instead of adding drop migrations. Everything removed stays in the tag `archive/w7-before-reduction`.

## Settled, do not reopen

- Publication by a human is the review. AI writes content directly to drafts, in every flow.
- Direct writes apply to content only (`TARGET_CONTENT`, updates and new locale entries). Assets, folders, tags, data entries and blocks have no draft layer, so they stay proposals.
- Plan and budget approval stays, once, at the start. Budgets never extend automatically. Nothing else in a content task waits for a person.
- A field a human changed since the agent read it is never overwritten. It stays open as a conflict and the rest continues.
- Removed entirely: semantic review, Markdown glossary import and draft review, glossary revisions, glossary document rules, knowledge-impact discovery and maintenance. Style documents already cover document-wide rules.
- Kept: concept editor, sheet, Try a text, policy snapshots for terms and documents, translation memory, operations, ledger, write fencing, recovery.
- Translation memory learns only from what a human published.
- D10 is closed.

## Tasks

| Task | Outcome | Evidence | Depends on |
| --- | --- | --- | --- |
| R1 semantic | Semantic review gone from backend, UI, skills, MCP, docs | `w8-remove-semantic.md` | |
| R2 glossary stack | Markdown import, revisions, document rules, impact and maintenance gone | `w8-remove-glossary-stack.md` | |
| D1 direct writes | Content flows write to drafts, and tasks run unattended | `w8-direct-writes.md` | R1, R2 |
| P1 prompt and loop | Short prompt, terms on read, findings to the agent and on buttons, `*` in banned | `w8-prompt-loop.md` | D1 |

Integration owner regenerates `docs/public/specs/mgmt_v1.json` after each merge if it conflicts.
