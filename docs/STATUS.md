# Status

Updated 2026-11-09. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `main` (pushed): released as v2026.11.9-7c0af061 and deployed. Contains the large-page AI translation fix, plus the asset delivery work: every content change gets its own delivery revision (`touchContentUpdatedAt` never reuses a second), `spaces/me` cached 5s, asset texts follow the requested language for untranslated entries, `content_published` fires with `reason: asset_changed` for live entries (and their translations) showing a changed asset, and a used/unused asset library filter. Dependencies bumped within their ranges. Verified on production with a probe in space `01k119ag…87zr`; report: https://claude.ai/artifact/1ixh4NkWSUrAuiURyqAdeZ. Publish the four draft advisories (GHSA-rhr5-hf7m-33jf, GHSA-fh7x-m2rf-6796, GHSA-7g4p-qm7c-468h, GHSA-x748-ffgj-w7gw).
- `main`: translated slug propagation and parent filing. A translation now always sits under its parent's translation (`ContentI18nService::parentForLanguage()`, used by create, update, the slug rule and the slug preview); creating a parent's translation adopts waiting children; renaming a translated parent reaches every translation below it. New `content:repair-tree [--space] [--dry-run]` re-homes existing translations and rebuilds full slugs with 301s. Released with #68; the repair run on production is still open (one-off ECS `run-task` with a command override, service has exec disabled).
- `fix/overlay-canonical-structure`: merged as #68 (overlay translations keep the canonical nested block type; saving aligns items to the canonical block). Can be deleted.
- `fix/ai-translate-large-pages`: merged and released, can be deleted.
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

- `content:repair-tree` on production after this release: dry run first via ECS `run-task`, then for real.
- b10cks.com caches pages 7 days and nothing purges them: shorter `s-maxage` in `website/nuxt.config.ts`, a `content_published` automation calling `/api/webhooks/cloudfront/invalidate` with locale-prefixed `paths`, or both. Also upgrade the website's `@b10cks/nuxt` 3.5.0 (does not await the revision sync).
- Delivery revision now runs ahead of the clock during bursts (one second per extra change in the same second; a bulk publish of 500 entries can push it minutes ahead). Dashboards show it as "edited …", so they can briefly read "in 5 minutes". Option: a separate integer revision column.
- Read-only production check for misuse of the LemonSqueezy `space_id` hole.
- SAML trust model: a team's IdP can sign in as members without 2FA, reaching their other teams.
- Autosave (changes the version model) and the `comments/mentionable` route: confirm both.
- Whether the tooltip that reappears after Esc closes a dialog stays.
- `docs/milestones/reliable-ai-content-operations` and `docs/evidence`: keep or archive.
- "Used in" for the content info pane: parked 2026-11-06 (half a day to a day via `AssetUsageService`).

## Next

0. Test entries `zz-*` from the 2026-11-09 delivery probes sit in the trash of B10cks Website; purge when convenient. The generated OpenAPI spec has no request body or filter parameters for the asset routes (generator gap, predates this work).

1. Check a big AI translation on app.b10cks.com streams live (released 2026-11-09). Bedrock streaming still goes through the AWS SDK's Guzzle handler and buffers the same way in production.
2. Fix `AiOperations::settleBatch`: it charges an attempt to every unreached entry when a batch stops on `max_tokens`, `max_steps` or `max_seconds`. Then the backend should-fix list from the 2026-11-05 merge review (`OperationReview` memory, 64 KB `TEXT` lineage, approve triage in request, review view checks, `startPlanning` lock, glossary cache cap, uncapped style guides, wording checks on raw HTML, `IconDataImportService` yield in transaction).
3. UI follow-ups from the 2026-11-05 audit (32 findings) and UX should-fix (confirm on reject all, promptless bulk skill, operation panel error states, `?scope=` leaf team).
4. After each deploy: `php artisan migrate --force`, then `spaces:repair-databases --dry-run` and without the flag.
