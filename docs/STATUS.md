# Status

Updated 2026-11-11. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `feat/ilum-lean-derivatives` (local, not pushed, 3 commits on v2026.11.11-1e529ab0, checkout `/Users/mwallner/.t3/worktrees/cms/t3-593cdcb2-101b694feeb1f2ca`, 2026-11-11), **waiting on Mike's go to merge and release**. ilum derivatives carry no EXIF, XMP, IPTC, comments or colour profile; sources with a profile are converted to sRGB first (the ImageMagick driver keeps the profile instead). New defaults WebP 80, AVIF 65, JPEG 80, measured with SSIMULACRA2 in a container with the production libvips 8.18.2; AVIF encodes at effort 2, which takes about 0.5 s instead of 5 s for a 1920 px photo. `?format=jpeg` works. After the deploy the CDN still serves the old files for up to a year: **invalidate `/ilum/*` on the CloudFront distribution of api.b10cks.com, Mike decides when.** Every derivative then renders again on its first request. Not checked: whether production sets `IMAGE_*_QUALITY`, which would override the new defaults.
- `main` (pushed, plus this note as one local commit): released as v2026.11.11-1e529ab0 on 2026-11-11 with `release.sh`, deploy run 43036372850 green. Fixes 429s a customer SSR site got from the delivery API during a crawl. The CloudFront access logs of 2026-11-11 12:50 UTC showed the flat 600 per minute token budget used up by cache misses of `breadcrumbs/{slug}` and `contents/{slug}`, about 2.2 per rendered page, not by `spaces/me`, which CloudFront holds for 5 seconds. The limit is now `DATA_API_RATE_LIMIT` (default 3000 per token, twice that per address, not set in the task definition) and `spaces/me` is not rate limited. Checked live: `X-RateLimit-Limit: 3000`. The merged branch `fix/delivery-api-rate-limit` and its worktree `/Users/mwallner/.t3/worktrees/cms/fix-delivery-api-rate-l-3ebef0aa8fc86305` can be deleted. Open follow-ups: unauthenticated `api` routes share the key `api|ip|{ip}` with the delivery address bucket, CDN hits carry a stale `X-RateLimit-Remaining`, and the API distribution allows no POST, so `redirects/lookup` cannot pass CloudFront (seen in the config, not tested).
- Before that, `main` was released as v2026.11.10-bf446a04 on 2026-11-10 with `release.sh`, deploy run 42910362407 green, self-hosted release published. Contains security review round 4 (merge `c00b4b2f`, 15 fix commits: team move policy, unpublished translations in delivery, second-factor lockout at sign-in, social link and invite proof, unsigned SAML logout, automation trigger payload and mail caps, mail quotes and mentions, invoices per subscription, collaboration channels need `content.manage`, Markdown without images, icon SVG CDATA, SVG pixel cap, smaller authorization gaps) and the language switch commits `71cfe235`, `bd99387c`, `39a4ca79`. The release before it, v2026.11.10-d42176ea, was rolled back by the deploy: its redirect for a future `rv` failed the smoke check, which uses a unique `rv` to get past the CDN. `fb938559`'s parent commit serves such a revision with a 10 second cache lifetime instead. The self-hosted image of d42176ea exists and still redirects; bf446a04 supersedes it. All 21 advisories are published, the 13 of round 4 with patched version 2026.11.10-bf446a04. After this release: icons uploaded before it keep their stored body, so re-save icons of spaces that take icons from untrusted members.
- `security/round-4-followups` (local, not pushed, 8 commits on the round 4 fixes, checkout `/Users/mwallner/.t3/worktrees/cms/t3-fcc6276e-349f78e43d21974f`, 2026-11-10), **waiting on Mike's go to merge and release**. Seven fixes: second factor for root elevation with a step-up in `ElevateDialog.vue` and no more `real-user` token, `team_id` required for new spaces, role assignment checks abilities (a ceiling for space roles, strict subset for team roles), billing role needs `manageInvites` to invite through a plan proposal, automation action config and results hidden without the view abilities, token `execution_limit` counted without usage tracking, webhost package without `TRUSTED_PROXIES=*` and the `public/storage` link. 1057 tests passed. Not verified: the TOTP variant of the dialog, the new strings in de, fr, tr, es, ru.
- `coderscantina/laravel-filter`: a separate thread fixes the dispatch of request keys to any public filter method, releases a 2.x version and pulls it in on a local branch `chore/filter-update` (own worktree). Check that thread for its state.
- Worktree `../cms-security-r4` holds `main`, because the main checkout is on `film/teaser`. Remove it when the follow-ups are merged.
- `main` (pushed): released as v2026.11.10-9efad849 on 2026-11-10 with `release.sh`, deploy run 42836993956 green. Adds PR #71 (merge `9efad849`): entries that exist in one non-default language only, e.g. a German landing page with no English original. An original may be in any enabled language; such entries never get translations, on Mike's call, because about 20 places assume a default-language original. Tree badge, create-dialog language picker, switcher note, `include_fallback` delivery fix, menu cache format 4. Also the localization view fix (`1d02f622`): the source column read a private `localization-source-chain` query that saves and publishes never refreshed, so it showed pre-save values for up to 5 minutes; it now reads the shared content detail cache through `contentQueryOptions` in `useContent.ts` (`16f0130e`).
- Previous release v2026.11.10-191e959a, contains, in order: translation memory Management API and MCP (`ai.translationMemory.list|update|delete|bulk`, reads `ai.view`, writes `ai.manage`, a correction becomes `origin: human`; released alone first as v2026.11.10-b0883032), style guides capped at 20,000 characters (`AiKnowledgeDocument::MAX_STYLE_CHARS`, 422 on `draft`), knowledge documents with a required `description` and runs listing reference documents by description, and the AI glossary removed entirely (API, `/terms`, 22 MCP operations, `lookup_glossary`, checkers, suggestions, editor, docs; policy snapshots version 3). Migration `2026_11_10_120000_drop_ai_glossaries` archives each glossary as JSON to `glossary-archive/{timestamp}/` on the transfers disk, then drops the tables; `2026_11_10_110000` drops `ai_glossary_terms_legacy`. **Space migration `000029` (drops `ai_glossary_suggestions`, the findings columns, suggestions threads) needs `spaces:repair-databases` on production; Mike runs it.**
- SDK (`../sdk`, branch `feat/sync-mgmt-operations`, being prepared by a delegated agent on 2026-11-10): brings `@b10cks/mgmt-client` and `@b10cks/mcp-server` on par with the CMS, built on the uncommitted AI operations mirror that sat on `fix/preview-safety-and-interactions`. Until it is released, published SDK glossary calls hit removed endpoints and `OperationsMirrorTest` keeps its allow-lists.
- Earlier on `main`: #67, #69, #70 (asset reference repair, canonical ids, entrypoint command override), translated slug propagation (`content:repair-tree`, production run still open), localization preview merge. Released on 2026-11-09.
- All merged task branches and their worktrees were deleted locally on 2026-11-10. `feature/language-only-entries` deleted after the merge (remote already gone). Left: `feat/ai-content-operations` (retired) and `archive/ai-content-operations-history` (reference only).
- Dependabot #56, #57, #58, #59, #64 (Actions bumps, three touch `deploy.yml`): held for a release of their own.
- Tags `archive/w7-before-reduction`, `archive/w8-before-simplify`: what W8 and W9 removed.

## Uncommitted work

- `auto-imports.d.ts`: regenerated by Vite (adds `useAppUpdateNotice`, drops `useAiGlossaryConceptRows`). Generated drift, not part of any branch; commit it on its own or let the next Vite run settle it.

## Local data changed for screenshots and repros

- B10cks Website2: `language_only_entries` turned on for the rating round on 2026-11-10.
- B10cks Website2: German-only entry "Sommeraktion" `01m72ps2sbzhxm2dtdqjdw59j4` at the root, created through the new dialog on 2026-11-10 for the rating rounds. Delete when no longer needed.
- Factory user `adell.witting@example.net` (password `password`) is an Admin of "B10cks Website2" and "SchlafTEQ". Remove when no rating rounds need it.
- B10cks Website2: Config entry version `01kw6na6zw47jk75kgzhcwpbjq` Pricing link points at Home/"Atomic Design Principles" (was a dangling id); "Blocks testing" `01KK1S2RKFE50Q2NQZ29Q8VHPE` is overlay with a German translation `01m6rbqz13rcp2e4ggfg960hp2` and glossary "Website terms".
- SchlafTEQ `01kkk9mqjw2kn06vknz28t7ncg`: glossary "schlafTEQ Glossar" (188 rows), unpublished AI drafts on `/kontakt-und-termine`, `/produkte/beds|bedden|lits|letti`; locale-switch test edits on "Termin stornieren", "Impressum" and "Home" EN. Three completed `translate-fields` test runs for "Level 24 Pro" from 2026-11-06 (nothing saved).

## Running

- Vite dev server, PID 36018, port 3000 (`public/hot` present), started 2026-11-10 12:50 by an agent. Serves whatever branch is checked out (now `main`). Stop with `kill 36018` when done.

## Waiting on Mike

- Security round 4, found and not fixed, each needs a decision or UI work:
  - Automation email recipients are still any address. Recommendation: only verified members of the space, since anyone can sign up, create a space and mail from the platform address (now capped, not closed). Breaks automations that mail external addresses.
  - Public asset shares return asset id, filename and thumbnail paths, enough to build the permanent `/ilum` URL of the original. That bypasses `allow_individual_downloads`, limits, expiry and revocation. Fix: opaque per-share handles and thumbnails through the share route, needs the share page changed.
  - `breadcrumbs?ancestors=all` shows names and slugs of unpublished ancestors to any delivery token, by design for structural folders. Gate it on `preview` or a space setting?
  - `bun audit`: 12 advisories, all in dev tooling (vite 5 through vitepress, esbuild, glob, minimatch). `composer audit` is clean.

- Space backups and space-to-space migrations carry no skills or knowledge documents. Parked on 2026-11-09 as its own project; decide how inherited team items are handled.

- Production repairs after this release (#67, #69, #70), dry run first each time. Since #69 the entrypoint runs a command override instead of the server, so the regular task definition works:

  ```sh
  NET=$(aws ecs describe-services --cluster nb-clients01 --services b10cks-cms --query 'services[0].networkConfiguration' --output json)
  run() { aws ecs run-task --cluster nb-clients01 --task-definition b10cks-cms --network-configuration "$NET" \
    --overrides "{\"containerOverrides\":[{\"name\":\"b10cks-cms\",\"command\":$1}]}"; }
  run '["php","/app/artisan","content:repair-tree","--dry-run"]'
  run '["php","/app/artisan","content:repair-tree"]'
  run '["php","/app/artisan","content:reindex-references","--space=01kkk9mqjw2kn06vknz28t7ncg","--dry-run"]'   # read the counts in the task log
  run '["php","/app/artisan","content:reindex-references","--space=01kkk9mqjw2kn06vknz28t7ncg"]'
  run '["php","/app/artisan","content:reindex-references","--dry-run"]'   # every space
  run '["php","/app/artisan","content:reindex-references"]'
  ```

  Add the service's launch type or capacity provider to `run-task`. The local SchlafTEQ copy has 1,647 versions to rewrite (75 assets). The local copy of "Übersicht Content-Elemente Organismen" (`/uebersicht-content-elemente-organismen`, published) holds two pasted asset ids with no `full_path`; nothing can repair those, and that entry now fails to save or publish until someone picks the two images again. B10cks Website2 "Blocks testing" references an asset of another space (`01kh6he1…`), same consequence.
- Overlay merge, non-translatable fields: a null or missing canonical value lets a translation's stored copy through (and table non-text cells the same). Kept on purpose for fallback layers when the canonical has no published version; fixing it means tracking whether the base came from the canonical and changes delivery for existing content.
- Decide: cross-space block paste. The editor refuses it today (`checkBlockPaste` returns `reason: 'space'`), and the content tree clipboard refuses another space too. A Management API client can still send another space's asset ids, which now gets a 422.
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
