# Status

Updated 2026-11-10. Read by agents at the start of a thread; rewrite it before handing back.

## Branches

- `main` (local, 3 commits not pushed, 2026-11-10): `71cfe235` fixes the create dialog's language menu (the tooltip wrapped the dropdown trigger, so the menu had no anchor and opened off screen; `SimpleTooltip` now passes attributes to its trigger). `bd99387c` lets an original without translations switch `language_iso` (refused with translations, with the space setting off for a non-default target, or on a path taken in that language; the old URL redirects). `39a4ca79` makes it visible: an info notice and "Create in German only" in the create dialog, a Language row in the config pane that confirms and switches at once. Both UI rules are in DESIGN.md as not rated yet. Waiting on Mike's go to push and release. The language-only amendments (`feature/language-only-amendments`) are already in `main` and released as v2026.11.10-446038c7; the branch can be deleted.
- `security/round-4` (local, not pushed, worktree `../cms-security-r4`, 15 commits on `main` at v2026.11.10-446038c7, 2026-11-10), **waiting on Mike's go to merge and release**. Security review round 4: six read-only audits by area, every finding checked in the code before the fix. Fixed: team takeover by moving a team (`TeamPolicy::move`), unpublished translations in published delivery (`ContentI18nResolver`), second-factor lockout at sign-in and the login throttle keys, social link callback without step-up, invite verification by id, unsigned SAML logout, automation trigger payload reaching the action config, automation mail caps (20 recipients per mail, 200 per space and hour, a line naming the space), Markdown in invite and comment mails, mentions to any user, comment abilities, invoices by subscription instead of customer, collaboration channels need `content.manage`, Markdown rendered without images, icon SVG CDATA, SVG pixel cap, `replaceFile` paths, DOCX size, IPv6 forms in `OutboundUrlGuard`, future `rv` redirect, throttle for unknown delivery tokens, direct team members only from the organisation, `state` out of space updates, blueprint source needs `space.update`, MCP AI tools need `ai.view`, `b10cks:setup` refuses the public Reverb secret, tag names out of release scripts. Full PHPUnit run: 1958 passed, 1 failed (`OperationsMirrorTest`, compares against the `../sdk` checkout, unrelated). Draft advisories: GHSA-6943-p65c-w39w, GHSA-q2jh-8fc3-wqvg, GHSA-88h9-f552-4qx2, GHSA-3c87-7598-793c, GHSA-85j6-f879-j8g7, GHSA-98jg-329r-p3vq, GHSA-gq5q-x4ww-j5fg, GHSA-q959-fv75-fj25, GHSA-5xqv-33rr-xwm4, GHSA-m2q5-9vhv-mwq9, GHSA-85w3-x4ph-qwwx, GHSA-rg7v-4rx8-666x, GHSA-wvhq-5qq4-gjmc. After the release: icons uploaded before it keep their stored body, so re-save icons of spaces that take icons from untrusted members.
- `feature/language-only-amendments` (local, not pushed, 4 commits on `main`, 2026-11-10), **waiting on Mike's go to merge**. Space setting `language_only_entries` gates new single-language entries (off by default, existing ones stay; enable it on production spaces that already use them). Manual sort fix: positions are numbered across the rows the tree shows (`Content::scopeShownInTree`), and a tree move no longer re-parents a single-language entry under the parent's translation. Create dialog: the language is a ghost footer button with a tooltip (rated 8 in round 2, https://claude.ai/artifact/RMCufvShjoBJsPcBxaK2Xb); children of a single-language entry start in its language. Blocks field: delete all selected with one undo. Rules in DESIGN.md.
- `main` (pushed): released as v2026.11.10-9efad849 on 2026-11-10 with `release.sh`, deploy run 42836993956 green. Adds PR #71 (merge `9efad849`): entries that exist in one non-default language only, e.g. a German landing page with no English original. An original may be in any enabled language; such entries never get translations, on Mike's call, because about 20 places assume a default-language original. Tree badge, create-dialog language picker, switcher note, `include_fallback` delivery fix, menu cache format 4. Also the localization view fix (`1d02f622`): the source column read a private `localization-source-chain` query that saves and publishes never refreshed, so it showed pre-save values for up to 5 minutes; it now reads the shared content detail cache through `contentQueryOptions` in `useContent.ts` (`16f0130e`).
- Previous release v2026.11.10-191e959a, contains, in order: translation memory Management API and MCP (`ai.translationMemory.list|update|delete|bulk`, reads `ai.view`, writes `ai.manage`, a correction becomes `origin: human`; released alone first as v2026.11.10-b0883032), style guides capped at 20,000 characters (`AiKnowledgeDocument::MAX_STYLE_CHARS`, 422 on `draft`), knowledge documents with a required `description` and runs listing reference documents by description, and the AI glossary removed entirely (API, `/terms`, 22 MCP operations, `lookup_glossary`, checkers, suggestions, editor, docs; policy snapshots version 3). Migration `2026_11_10_120000_drop_ai_glossaries` archives each glossary as JSON to `glossary-archive/{timestamp}/` on the transfers disk, then drops the tables; `2026_11_10_110000` drops `ai_glossary_terms_legacy`. **Space migration `000029` (drops `ai_glossary_suggestions`, the findings columns, suggestions threads) needs `spaces:repair-databases` on production; Mike runs it.**
- SDK (`../sdk`, branch `feat/sync-mgmt-operations`, being prepared by a delegated agent on 2026-11-10): brings `@b10cks/mgmt-client` and `@b10cks/mcp-server` on par with the CMS, built on the uncommitted AI operations mirror that sat on `fix/preview-safety-and-interactions`. Until it is released, published SDK glossary calls hit removed endpoints and `OperationsMirrorTest` keeps its allow-lists.
- Earlier on `main`: #67, #69, #70 (asset reference repair, canonical ids, entrypoint command override), translated slug propagation (`content:repair-tree`, production run still open), localization preview merge. Released on 2026-11-09.
- All merged task branches and their worktrees were deleted locally on 2026-11-10. `feature/language-only-entries` deleted after the merge (remote already gone). Left: `feat/ai-content-operations` (retired) and `archive/ai-content-operations-history` (reference only).
- Dependabot #56, #57, #58, #59, #64 (Actions bumps, three touch `deploy.yml`): held for a release of their own.
- Publish the four draft advisories (GHSA-rhr5-hf7m-33jf, GHSA-fh7x-m2rf-6796, GHSA-7g4p-qm7c-468h, GHSA-x748-ffgj-w7gw).
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
  - Root elevation starts without a second factor (`POST /root/elevation`), and stopping an API impersonation mints a non-expiring `real-user` root token. Adding `2fa` needs a step-up prompt in the elevation dialog.
  - `coderscantina/laravel-filter` calls any public method of the filter object named by a request key (`?setMaxSortColumns=800`, `?apply=1` gives a 500). Fix belongs in the package: dispatch only to methods the concrete filter declares.
  - Team-less spaces: `POST /spaces` without `team_id` skips `team.spaces.create`, and `SpacePolicy::create` is never called. Unbounded on self-hosted, where the free plan is attached automatically.
  - Role assignment compares levels, not abilities: a custom role at level 200 or below with `space.delete` can be self-assigned by a space admin.
  - `PlanProposalController` lets the billing role invite an address with the billing role without `space.invites.manage`. Intended for the agency flow?
  - `automations.trigger` implies seeing the action config (webhook URL, headers) and execution results. Token `execution_limit` is only enforced when `TRACK_TOKEN_USAGE` is on.
  - Webhost package: `TRUSTED_PROXIES=*` in `.env.webhost.example` and the `public/storage -> storage/app/private` symlink (`build-webhost-package.sh`, `b10cks:setup`), which serves the default disk without ilum.
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
