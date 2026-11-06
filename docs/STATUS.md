# Status

Updated 2026-11-06. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `main` (pushed): released as v2026.11.6-91d4320d, including the AI Translate stale-form fix. Publish the four draft advisories (GHSA-rhr5-hf7m-33jf, GHSA-fh7x-m2rf-6796, GHSA-7g4p-qm7c-468h, GHSA-x748-ffgj-w7gw).
- `fix/ai-translate-large-pages` (local, 4 commits, not pushed): large pages failed to AI-translate in production and small ones arrived all at once. Cause: the image sets `allow_url_fopen=Off`, so Guzzle buffered every streamed provider call over curl and CloudFront (30s origin read timeout) dropped silent streams. Streamed turns now use Symfony's curl client (`StreamingTransport`, `ChunkStream`), pings keep the SSE stream alive while the provider is silent, the inline-to-background handover now dispatches its job, and the localization page applies streamed fields in 500ms batches. All 500 AI tests, typecheck and lint pass; verified locally with `-d allow_url_fopen=0` (first token 36s → 7s). Needs Mike's review, then merge and release.
- Merged into main, can be deleted with their worktrees: `fix/security-review-round-2`, `feat/import-streaming-progress` (worktree `.claude/worktrees/import-streaming`), `feat/ai-content-operations-clean`, `feat/ux-refinements`, `feature/provider-activity`, `feature/verified-team-domains`, `delta/security-review`.
- `feat/ai-content-operations` (retired) and `archive/ai-content-operations-history`: reference only.
- Dependabot #56, #57, #58, #59, #64 (Actions bumps, three touch `deploy.yml`): held for a release of their own.
- Tags `archive/w7-before-reduction`, `archive/w8-before-simplify`: what W8 and W9 removed.

## Uncommitted work

None.

## Local data changed for screenshots and repros

- Factory user `adell.witting@example.net` (password `password`) is an Admin of "B10cks Website2" and "SchlafTEQ". Remove when no rating rounds need it.
- B10cks Website2: Config entry version `01kw6na6zw47jk75kgzhcwpbjq` Pricing link points at Home/"Atomic Design Principles" (was a dangling id); "Blocks testing" `01KK1S2RKFE50Q2NQZ29Q8VHPE` is overlay with a German translation `01m6rbqz13rcp2e4ggfg960hp2` and glossary "Website terms".
- SchlafTEQ `01kkk9mqjw2kn06vknz28t7ncg`: glossary "schlafTEQ Glossar" (188 rows), unpublished AI drafts on `/kontakt-und-termine`, `/produkte/beds|bedden|lits|letti`; locale-switch test edits on "Termin stornieren", "Impressum" and "Home" EN. Three completed `translate-fields` test runs for "Level 24 Pro" from 2026-11-06 (nothing saved).

## Running

None.

## Waiting on Mike

- Review and merge `fix/ai-translate-large-pages`, then `./release.sh`. Recommend releasing soon: every AI call over 30s fails in production today.
- Read-only production check for misuse of the LemonSqueezy `space_id` hole.
- SAML trust model: a team's IdP can sign in as members without 2FA, reaching their other teams.
- Autosave (changes the version model) and the `comments/mentionable` route: confirm both.
- Whether the tooltip that reappears after Esc closes a dialog stays.
- `docs/milestones/reliable-ai-content-operations` and `docs/evidence`: keep or archive.
- "Used in" for the content info pane: parked 2026-11-06 (half a day to a day via `AssetUsageService`).

## Next

1. After the large-pages release, check a big AI translation on app.b10cks.com streams live. Bedrock streaming still goes through the AWS SDK's Guzzle handler and buffers the same way in production.
2. Fix `AiOperations::settleBatch`: it charges an attempt to every unreached entry when a batch stops on `max_tokens`, `max_steps` or `max_seconds`. Then the backend should-fix list from the 2026-11-05 merge review (`OperationReview` memory, 64 KB `TEXT` lineage, approve triage in request, review view checks, `startPlanning` lock, glossary cache cap, uncapped style guides, wording checks on raw HTML, `IconDataImportService` yield in transaction).
3. UI follow-ups from the 2026-11-05 audit (32 findings) and UX should-fix (confirm on reject all, promptless bulk skill, operation panel error states, `?scope=` leaf team).
4. After each deploy: `php artisan migrate --force`, then `spaces:repair-databases --dry-run` and without the flag.
