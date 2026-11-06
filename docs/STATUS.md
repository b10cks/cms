# Status

Updated 2026-11-06. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `main` (checked out): released as v2026.11.6-c74a9a65, then security round 3 merged on top (f7b327da, five 🔒 commits efdf3084..a6cc98e7, not released, not pushed). Full PHPUnit passes (2041 passed, 5 skipped). Draft advisories GHSA-rhr5-hf7m-33jf (mail injection), GHSA-fh7x-m2rf-6796 (SAML skips 2FA), GHSA-7g4p-qm7c-468h (step-up grace), GHSA-x748-ffgj-w7gw (breadcrumbs) say "fixed in the next release after v2026.11.6-c74a9a65"; publish them with that release. Then the entry editor polish on top: ten commits ee1215d4..a4baf9dc, then its rules in DESIGN.md (space switcher, blocks insert line, nested item heading, price, references, rich text, map, meta search and share preview), not pushed. Then the translation view upgrade, 0a9fdd38 (outdated status, scope menu and clear, hover copy, assist panel, glossary checks; `translation-status` and `translation-assist` endpoints) and e8f9ea08 (regenerated API specs), not pushed. Then the translation toolbar (401c142a, sticky status legend filter, rated 9; rule 228df35e) and a stale MetaBlock test mock (211c8102). Release prepared on 2026-11-06: full PHPUnit (2048 passed, 4 skipped), vitest (5794), lint and typecheck green locally; waiting on Mike's go to push main and tag. The 2027 entry editor directions (https://claude.ai/artifact/J1RLxqne22bpkjBVFf4Qse) are parked by Mike.
- Merged into main, can be deleted with their worktrees: `fix/security-review-round-2`, `feat/import-streaming-progress`, `feat/ai-content-operations-clean`, `feat/ux-refinements`, `feature/provider-activity`, `feature/verified-team-domains`, `delta/security-review`.
- `feat/ai-content-operations` (retired working branch) and `archive/ai-content-operations-history` (full 142-commit history): reference only.
- Dependabot #56, #57, #58, #59, #64 (Actions bumps, three touch `deploy.yml`): held for a release of their own.
- Tags `archive/w7-before-reduction`, `archive/w8-before-simplify`: what W8 and W9 removed.

## Uncommitted work

None.

## Local data changed for screenshots

- Factory user `adell.witting@example.net` (password `password`) is an Admin of local spaces "B10cks Website2" and, since 2026-11-06, "SchlafTEQ". Remove when no more rating rounds need it.
- B10cks Website2 Config entry, version `01kw6na6zw47jk75kgzhcwpbjq`: the Pricing menu link points at Home, section "Atomic Design Principles". It was `{"type":"internal","target":null,"content":"01kh3cvbj3qxfvv8pp9mwwwvt1"}`, a dangling id.
- SchlafTEQ space `01kkk9mqjw2kn06vknz28t7ncg` (another thread): glossary "schlafTEQ Glossar" with 188 rows, AI config, unpublished AI drafts left for review: meta on `/kontakt-und-termine`, translations `/produkte/beds` (en), `/produkte/bedden` (nl), `/produkte/lits` (fr, with meta) and `/produkte/letti` (it). The five Reference docs that duplicated the glossary were soft-deleted on 2026-11-06; live policy now holds the glossary only. Management migration `2026_11_05_100000_normalize_ai_glossary_terms` is applied locally.

- B10cks Website2 (local), for the translation view screenshots: "Blocks testing" `01KK1S2RKFE50Q2NQZ29Q8VHPE` has `i18n_mode_override: overlay` (was `inherit`) and a new current version `01m6rbr8ppkrv0qa0yberpw8ke` with a changed header and item-1 label (was `01kp1n314td35y8nef81fnz57q`); a German translation `01m6rbqz13rcp2e4ggfg960hp2` was created, published (4 memory pairs) and given a draft with "Bausteinen"; a space glossary "Website terms" `01m6rcj5eg9v4ndy3kf63ze9zd` (blocks, content model, b10cks). "For Agencies" was switched to overlay and back to `inherit`.

## Running

None.

## Merge review of the clean branch, 2026-11-05

Shipped on 2026-11-06 with these open, by Mike's call. Fix `settleBatch` first.

- Blocker, fix next: `AiOperations::settleBatch` charges an attempt to every unreached entry when a batch stops on `max_tokens`, `max_steps` or `max_seconds`; after two attempts they fail as `not_processed` and the operation still completes.
- Backend should-fix: `OperationReview` loads all items into memory; `TEXT` lineage columns overflow over 64 KB; `approve` triages inside the request; review endpoints skip the target view check; `startPlanning` takes no lock; `GlossaryMatcher` cache capped by count; style guides uncapped in the prompt; wording checks read raw HTML and JSON; `IconDataImportService` yields inside a transaction; unread glossary rows stored every run.
- UX should-fix: no confirm on "Reject all/selected"; the bulk dialog hands a promptless skill to a dialog that needs one; operation panels lack error states and lose unsaved plan edits; `?scope=` strands a leaf team.

## Waiting on Mike

- Push main and cut the release with security round 3, then publish its four draft advisories.
- Read-only production check for misuse of the LemonSqueezy `space_id` hole: unexpected subscriptions, or one cancelled locally when another was created.
- SAML trust model: a team's IdP can still sign in as members without 2FA, and that session reaches their other teams.

- Autosave (changes the version model) and the `comments/mentionable` route (shows names and avatars to anyone who can read comments): confirm both.
- Whether the tooltip that reappears after Esc closes a dialog stays.
- `docs/milestones/reliable-ai-content-operations` and `docs/evidence`: keep on the merged version or archive only.
- "Used in" for the content info pane: parked by Mike on 2026-11-06. Half a day to a day by generalizing `AssetUsageService` to `link_ids` and `relation_ids`.

## Next

1. Fix `AiOperations::settleBatch` (see "Merge review"), then the backend should-fix items.
2. UI follow-ups from the 2026-11-05 audit (32 findings, team switcher and tree search rules already settled): solid blue menu highlights, hand-rolled `ContentSelect`, row action sizes, status text on `-ink` tokens, light-only alerts, confirm dialog and Cancel looks, sheet padding. Also: space blueprint radio cards, Mass Edit grid monospace 10px slugs, hard-coded dimension labels, the 500 on version filters `isCurrentVersion`/`isPublished`, the rich-text link dialog's old picker, missing `common.select`, "Automations" cut off in the rail.
3. Right after the deploy: `php artisan migrate --force` (management migrations `2026_11_02_100000`, `2026_11_02_120000`, `2026_11_05_100000_normalize_ai_glossary_terms`), then `spaces:repair-databases --dry-run` and without the flag (space migrations 000021 to 000028).
4. After the import release, rerun the 2026-11-03 17:52 metadata file on production.
