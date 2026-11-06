# Status

Updated 2026-11-06. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `main` at `cc370104`.
- `feat/ux-refinements` (checked out, unpushed): UX work from 2026-11-05 and 11-06 on top of `feat/ai-content-operations-clean` (from `3a053ac8`). Every surface rated 8 to 10 by Mike, rules in DESIGN.md. Targeted checks pass: 1753 Vitest tests, AssetHandlerTest, typecheck, oxlint, pint. Merges with the clean branch.
- `feat/ai-content-operations-clean` (unpushed): the version to merge. Not ready to deploy, see "Merge review".
- `feat/ai-content-operations` (retired working branch) and `archive/ai-content-operations-history` (full 142-commit history): reference only.
- `feat/import-streaming-progress` (8 ahead of main, unpushed): streaming imports, fixes a live 504. The clean branch starts from its head, so it can be released first. Worktree `.claude/worktrees/import-streaming`, clean.
- `fix/security-review-round-2` (15 ahead of main, from another thread): security fixes from a review (SAML, MCP, invites, uploads, version ownership). Worktree `~/.t3/worktrees/cms/fix-security-review-round-2`, clean. Status of its review unknown to this thread.
- `feature/provider-activity`, `feature/verified-team-domains`: merged into main, can be deleted.
- Tags `archive/w7-before-reduction`, `archive/w8-before-simplify`: what W8 and W9 removed.

## Uncommitted work

None.

## Local data changed for screenshots

- Factory user `adell.witting@example.net` (password `password`) is an Admin of local space "B10cks Website2". Remove when no more rating rounds need it.
- B10cks Website2 Config entry, version `01kw6na6zw47jk75kgzhcwpbjq`: the Pricing menu link points at Home, section "Atomic Design Principles". It was `{"type":"internal","target":null,"content":"01kh3cvbj3qxfvv8pp9mwwwvt1"}`, a dangling id.
- SchlafTEQ space `01kkk9mqjw2kn06vknz28t7ncg` (another thread): glossary "schlafTEQ Glossar" with 188 rows, AI config, unpublished AI drafts left for review: meta on `/kontakt-und-termine`, translations `/produkte/beds` (en), `/produkte/bedden` (nl), `/produkte/lits` (fr, with meta) and `/produkte/letti` (it). The five Reference docs that duplicated the glossary were soft-deleted on 2026-11-06; live policy now holds the glossary only. Management migration `2026_11_05_100000_normalize_ai_glossary_terms` is applied locally.

## Running

None.

## Merge review of the clean branch, 2026-11-05

- Blocker: `AiOperations::settleBatch` charges an attempt to every unreached entry when a batch stops on `max_tokens`, `max_steps` or `max_seconds`; after two attempts they fail as `not_processed` and the operation still completes.
- Backend should-fix: `OperationReview` loads all items into memory; `TEXT` lineage columns overflow over 64 KB; `approve` triages inside the request; review endpoints skip the target view check; `startPlanning` takes no lock; `GlossaryMatcher` cache capped by count; style guides uncapped in the prompt; wording checks read raw HTML and JSON; `IconDataImportService` yields inside a transaction; unread glossary rows stored every run.
- UX should-fix: no confirm on "Reject all/selected"; the bulk dialog hands a promptless skill to a dialog that needs one; operation panels lack error states and lose unsaved plan edits; `?scope=` strands a leaf team.

## Waiting on Mike

- Merge review: which findings to fix before merge. Recommend the blocker and the backend items first.
- Import branch: approve merging and releasing it (live 504).
- AI content operations: merge, push and deploy not authorized.
- Autosave (changes the version model) and the `comments/mentionable` route (shows names and avatars to anyone who can read comments): confirm both.
- Whether the tooltip that reappears after Esc closes a dialog stays.
- `docs/milestones/reliable-ai-content-operations` and `docs/evidence`: keep on the merged version or archive only.
- "Used in" for the content info pane: parked by Mike on 2026-11-06. Half a day to a day by generalizing `AssetUsageService` to `link_ids` and `relation_ids`.

## Next

1. UI follow-ups from the 2026-11-05 audit (32 findings, team switcher and tree search rules already settled): solid blue menu highlights, hand-rolled `ContentSelect`, row action sizes, status text on `-ink` tokens, light-only alerts, confirm dialog and Cancel looks, sheet padding. Also: space blueprint radio cards, Mass Edit grid monospace 10px slugs, hard-coded dimension labels, the 500 on version filters `isCurrentVersion`/`isPublished`, the rich-text link dialog's old picker, missing `common.select`, "Automations" cut off in the rail.
2. Before deploying: `spaces:repair-databases` by hand for space migrations 000027 (after the import release) and 000028; management migration `2026_11_05_100000_normalize_ai_glossary_terms` during a controlled worker swap.
3. After the import release, rerun the 2026-11-03 17:52 metadata file on production.
