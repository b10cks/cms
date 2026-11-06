# Status

Updated 2026-11-06. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `main` (checked out, pushed): released as v2026.11.6-04136717 on 2026-11-06 (security round 3, entry editor polish, translation view upgrade and status toolbar); deploy, self-hosted release and tests all green. Publish the four draft advisories (GHSA-rhr5-hf7m-33jf, GHSA-fh7x-m2rf-6796, GHSA-7g4p-qm7c-468h, GHSA-x748-ffgj-w7gw). Open: Mike reports that after publishing a translation, switching to another language keeps showing the old one; not reproduced locally yet (see Next). The 2027 entry editor directions (https://claude.ai/artifact/J1RLxqne22bpkjBVFf4Qse) are parked by Mike.
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

- SchlafTEQ, for the locale-switch repro (2026-11-06): "Termin stornieren" EN published with its first body field ending in " x"; "Impressum" got a new published EN translation ("Legal notice EN" in one field).

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

0. Locale switch after publishing keeps the old language (Mike, prod, 2026-11-06). Ruled out locally: localization page EN publish then switch to an existing (nl) or missing (nl, it) language works headless; cache writes after publish/createAndPublish only touch the published row; own commit whispers are filtered. Suspects left: the regular editor's `isIncomingDifferentDocument && isDirty` guard if something writes to the document after `markSaved()`; prod-only Reverb events; the AI Translate path. Needs Mike's exact steps.
1. Fix `AiOperations::settleBatch` (see "Merge review"), then the backend should-fix items.
2. UI follow-ups from the 2026-11-05 audit (32 findings, team switcher and tree search rules already settled): solid blue menu highlights, hand-rolled `ContentSelect`, row action sizes, status text on `-ink` tokens, light-only alerts, confirm dialog and Cancel looks, sheet padding. Also: space blueprint radio cards, Mass Edit grid monospace 10px slugs, hard-coded dimension labels, the 500 on version filters `isCurrentVersion`/`isPublished`, the rich-text link dialog's old picker, missing `common.select`, "Automations" cut off in the rail.
3. Right after the deploy: `php artisan migrate --force` (management migrations `2026_11_02_100000`, `2026_11_02_120000`, `2026_11_05_100000_normalize_ai_glossary_terms`), then `spaces:repair-databases --dry-run` and without the flag (space migrations 000021 to 000028).
4. After the import release, rerun the 2026-11-03 17:52 metadata file on production.
