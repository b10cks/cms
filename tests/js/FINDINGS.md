# Frontend test sweep — findings

**5,006 tests across 161 files, all passing.** Lint and typecheck clean; `bun run test` wired
into CI. (Coverage at audit time: 95% statement / 89% branch / 95% function of `lib/`,
`utils/` and `composables/`.)

**This document is now a historical record plus a short open list.** The sweep originally
landed each finding with a pinning test asserting the *broken* behaviour. A follow-up fix pass
on this branch has since fixed the findings and retargeted each pinning test to assert the
**corrected** behaviour — so for anything marked FIXED, the cited code no longer behaves as
described: the text and line numbers record what was wrong at audit time, and the named test
now guards the fix.

Counts at audit time: 37 HIGH, ~106 MED, ~97 LOW (the original header said 36 HIGH; 37 is the
tally of HIGH entries below). **Nothing remains open.** The last two — `findItemById`'s
ancestor-index leak (#4) and `broadcastBlockOperation`'s missing `isConnected` guard (#5) —
were fixed in the follow-up pass along with two decisions the sweep surfaced but could not
make on its own:

- `clearClipboard` reads the system clipboard before overwriting it and only clears our own
  serialized item, so clearing an editor selection cannot destroy what the user copied in
  another application.
- The wizard's slug input re-seats itself from the store on blur. `updateSlug` already
  slugified every keystroke; the card's local ref just never showed it, so raw input that
  normalized to the same slug left the field disagreeing with what would be saved.

Severity is triage, not ceremony: **HIGH** = user-visible bug or security consequence,
**MED** = wrong-but-contained or a trap for the next change, **LOW** = cosmetic or dead surface.

---

## Start here — the ten that were worth fixing first (all now FIXED)

Ordered by consequence at audit time. **Every row below has been fixed** and its pinning test
retargeted; the table is kept as the record of what was worst.

| # | Status | What was wrong | Where (at audit time) |
|---|---|---|---|
| 1 | FIXED | `?filter={...}` serialized to `[object Object]`, so **content children were fetched unfiltered** — a live caller. Now: nested objects use bracket notation, arrays repeat `key[]`, and the children query sends `parent_id` top-level | `api/client.ts:58` + `useContent.ts:86` |
| 2 | FIXED | A successful content save showed *"Failed to update content"* and skipped invalidation whenever the response omitted `language_versions` — affected create, update, publish, schedule, unpublish, move. Now optional-chained | `useContent.ts:37` |
| 3 | FIXED | `usePresence` never enforced its reconnect cap (the scheduled timer reset the counter) and stacked overlapping timers → **endless join/leave loop** against a dead Reverb server | `usePresence.ts` |
| 4 | FIXED | `onWhisper`'s unsubscribe never called `stopListeningForWhisper`, so handlers accumulated → **duplicate whisper deliveries** | `usePresence.ts:210` |
| 5 | FIXED | Two unbounded parent-walks with no visited set — a `pid`/`parent_id` cycle **froze the tab**. Both walks now carry visited sets, and (unlike at audit time) both are pinned by terminating tests | `useContentMenu.ts:192`, `useAssetFolders.ts:132-161` |
| 6 | FIXED | `await alert.confirm(…)` **never settled** when dismissed by Escape or overlay click. Now settles `false` via an `isSettled` latch | `useAlertDialog.ts:231` |
| 7 | FIXED | Deleting an unsaved wizard node silently erased *saved* descendants and emitted **no delete operation**. Now salvages persisted descendants by re-parenting them, emitting a real move operation | `useContentWizardTree.ts:863` |
| 8 | FIXED | Stale cross-space permission window: while space B loaded, ability checks answered from **space A's** abilities with `spaceId` already reporting B. Now scoped via `resolveScopedAuthorization` | `useAuthorization.ts:90` |
| 9 | FIXED | Logout cleared the query cache but not `localStorage`, so on a shared browser the next account started in the **previous user's language with their team selected**. Now `clearPersistedUserState()` | `useAuth.ts:70-77` |
| 10 | FIXED | `formatFileSize` rendered the literal string **`undefined`** for negative, sub-byte, `NaN` or ≥1 EiB sizes. Now clamps the unit index and reports unrepresentable input as `0 B` | `useFormat.ts` |

### Runners-up, grouped — all FIXED

Each item below was spot-checked against the current source during this status update and is
fixed; descriptions are as of audit time.

- **Silently wrong data (FIXED):** two i18n keys that did not exist and rendered raw — now
  `forgotPasswordFailed`/`resetPasswordFailed`, present in `en.json` and `de.json`
  (`useAuth.ts:282,306`) · `useUserSettings` discarded one of two settings changed in the same
  tick — now composes onto live state · `useSpaceSettings.reset()` could not undo anything and
  its shallow `mergeDefaults` left every newly-nested default `undefined` — now a
  `createDefaults()` factory plus a recursive `mergeStoredSettings` · an option whose value was
  `0` was dropped and unselectable (`useFieldOptionChoices.ts:41`) · `SearchFilter` dropped the
  free-text `q` key from everything it emitted.
- **Stale cache after a write (FIXED):** deleting or re-roling a team never invalidated
  `authorization.all()` — every mutation in `useTeams.ts` now does · publishing a release never
  invalidated content — now invalidates `contents.lists()` (`useReleases.ts:147`) ·
  `useSpaceUsage`'s key lived outside `queryKeys` — now `queryKeys.spaceUsage(...)` · the `ai`
  namespace sat outside the space tree — now under `queryKeys.ai(spaceId)`
  (`useQueryClient.ts:289-294`) · accepting an invite nuked every space's cache via
  `spaces.all()` — now `spaces.lists()` (`useInvites.ts:206`).
- **Security posture (FIXED):** `plugin-bridge` accepted a source-less message when the iframe
  had no `contentWindow` — now guards `!this.iframeElement?.contentWindow`
  (`plugin-bridge.ts:80`) · `csrf.ts` logged every non-HttpOnly cookie **name and value** on any
  token miss — now logs names only (`csrf.ts:35`) · the public share page sent session cookies
  and primed the CSRF cookie — now `credentials: 'omit'` + `skipCsrf`
  (`public-share.ts:35-36`) · markdown sanitization permitted `<form>`/`<input>` — now
  `FORBID_TAGS` (`sanitize.ts:20`) · `GradientText` spliced unescaped colours into a `v-html`
  string — now `escapeHtml` on both inputs.
- **Wrong AI output (FIXED):** the streaming operations regex captured to end-of-string, so
  objects *after* the array became **phantom operations** — now bounded by `balancedSpans`
  (`useAiContentTree.ts:149-158`) · a `done` event did not stop the SSE read loop, so a second
  `done` applied operations twice — the loop now breaks on the first terminal event
  (`sse.ts:109-116`) · with ≥50 contents **no block could ever be mentioned** — the cap is now
  split between contents and blocks (`useAiMentions.ts:76-77`).
- **Accessibility (FIXED):** renaming was mouse-only across all six trees — `RenamableTitle`
  now has `role="button"`, `tabindex` and Enter/Space handlers (`:132-136`) · filter editing
  was mouse-only — `SearchFilter` badges are now keyboard-operable (`:609-616`) ·
  `PerPageSelect`'s sr-only label labelled nothing — `aria-labelledby` now lands on the
  trigger (`:43`) · `ColorSelect` had no accessible name — the trigger now carries
  `:aria-label="selectedLabel"` (`:20`).

---

Status legend for the detail sections: **OPEN** = needs a decision/fix · **FIXED** = already
fixed in this branch · **NOTED** = deliberate, documented, no action needed. FIXED findings
keep their original present-tense description and pre-fix line numbers as the historical
record; the cited pinning test now asserts the corrected behaviour.

---

## Fixed earlier in this sweep

### 1. `buildContentRouteLocation` collapsed every language to the default — FIXED
`resources/js/lib/content-i18n.ts:149`

It called `resolveContentLanguage(languageIso, defaultLanguage, undefined)`. That function
needs the content's language versions to tell a real language from a bogus one; given
`undefined` it fell through to the default every time, so the localization route was
unreachable and `lang=` was never emitted. The function had **no callers**, so nothing was
broken in production.

Fixed by normalizing instead of resolving (`normalizeLanguageIso(languageIso) || defaultLanguage`),
with a comment telling callers that hold the version list to resolve before calling in.

### 2. Table field defaults reached the editor unnormalized — FIXED
`resources/js/composables/useSchemaDefaults.ts:48`

The non-null `default` shortcut at the top of `resolveFieldInitialValue` returned before
the `case 'table'` branch, so a configured table default was handed to the editor raw —
stray columns, untyped cells and all. Fixed by hoisting the table branch above the
shortcut. No behaviour change for any other field type, or for tables without a default.

### 3. `fuzzyMatch` gave index 0 a spurious +8 — FIXED
`resources/js/lib/fuzzy-match.ts:64`

`previousIndex` seeds at `-1`, so at `textIndex === 0` the run check read `-1 === -1` as a
continued run and awarded the first character a bonus it had not earned. Guarded with
`previousIndex >= 0`. This one is a **live behaviour change**: command-palette scores for
index-0 matches drop by 8, and in a close call an anchored-at-0 result that previously
outranked a better mid-string match now loses. Verified ranking still behaves sensibly
(`con` → Content 29, My content 28, Collections 16).

---

## Formerly open — all now fixed

This section is kept as the record of what was tracked here longest. #4 and #5 were closed in
the follow-up pass; #6, #7 and #8 were closed by the sweep itself.

### 4. `findItemById` leaked an ancestor array index onto an object-slot result — FIXED
`resources/js/composables/useContentTree.ts:74` (the `?? index` merge)

The `?? index` merge in `findNestedItem` filled `index` from whichever array frame the
recursion passed through, so it could describe a **different level** than `parent`/`parentKey`.
For an item at `section.items[1].nested`, the result was
`{parent: card-2, parentKey: 'nested', index: 1}` — where `1` is `card-2`'s position in
`section.items`, not a position inside `nested`, so `{parent, parentKey, index}` was not a
safe splice target and a caller that trusted it would have mutated the wrong array. Latent:
`EditorComponent.vue:142` is the only caller and reads `item` plus the breadcrumbs.

Fixed by making the slot atomic: the first frame to return claims `parentKey` **and** `index`
together, so `index` is non-null only when the slot really is an array.
*Pinned by:* `tests/js/composables/useContentTree.test.ts` → "reports no index for an
object-slot result".

### 5. `broadcastBlockOperation` had no `isConnected` guard — FIXED (minor)
`resources/js/composables/useContentLiveCollaboration.ts:666`

The field-update path bails on `!presence.isConnected.value` before whispering; the block
operation path did not. Harmless in production because `usePresence.whisper` is a no-op
without a channel, but the two paths guarded differently for no stated reason.

Fixed by guarding the whisper only — the local bookkeeping above it (the structure-version
bump and the draft-field record) still runs offline, because the trail index depends on it.
*Pinned by:* `tests/js/composables/useContentLiveCollaboration.test.ts` → "does not whisper
while disconnected" plus "still records the local draft while disconnected".

### 6. `useAssetLibraryMoves`' cycle guard was only as good as the cached folder list — FIXED
`resources/js/composables/useAssetLibraryMoves.ts:25-37`

`isDescendantOf` walks the folder list from the asset-folders query. With a cold or empty
cache it had no lineage to walk, so `canMoveItems` **allowed** moving a folder into its own
descendant; a drag-drop performed before the folder list resolved could create a cycle, with
the server as the only remaining guard.

Fixed by failing closed: a `lineageReady` gate (`!isLoading && folders.length > 0`) refuses
every folder-into-folder move until the list has resolved. Moves to the root and asset-only
moves cannot cycle and stay allowed. (Earlier revisions of this file described the same bug
twice — once here and once as a separate "cold/empty folder cache" item; this entry is the
single canonical record.)
*Pinned by:* `tests/js/composables/useAssetLibraryMoves.test.ts` → the fail-closed group
("rejects moving a folder into its own child", "allows the same legal move once the folder
list has resolved").

### 7. Two schema-type normalizers disagreed — FIXED (merged)
`resources/js/lib/tableField.ts:16` + `resources/js/composables/useContentSchemaState.ts:70`

`normalizeTableSchemaType` was a narrower copy of `normalizeSchemaType`: it omitted `geo`,
`price`, `icon`, `serial` and `plugin`, returning `''` for them, so
`mergeLocalizedContentForSchema` silently skipped those field types — a second list that had
to be updated whenever a field type was added.

Fixed by merging the two into a single implementation: `tableField.ts` exports
`normalizeSchemaTypeName`, and `useContentSchemaState.ts` re-exports it as
`normalizeSchemaType`. There is no second switch to drift any more.

### 8. `api.ai` is constructed without a space id — FIXED (by narrowing the accessor)
`resources/js/api/index.ts:73` (`new Ai(this.client)`), guarded at `:97`

The instance really is built without a space id, so the original observation stands: calling a
space-scoped method on it *would* request `/spaces/undefined/...`. But the accessor no longer
exposes those methods —

    // Space-less: every other Ai method interpolates the space id into its path,
    // so those are only reachable through `forSpace(id).ai`.
    public get ai(): Pick<Ai, 'getStreamUrl'> {

`Pick<Ai, 'getStreamUrl'>` means `api.ai.getAiConfigs()` is a **compile error**, so the audit's
"TypeScript cannot catch it because the id is optional" is true of the *class* but not of the
*accessor* that callers actually reach. The one method left exposed handles an absent id by
design (`ai.ts:51` — `const query = this.spaceId ? …  : ''`). Not reachable, not a live bug.

Worth knowing: the guarantee is the return type, not a runtime check. `(api.ai as Ai)` or a
direct `new Ai(client)` still bypasses it, so the space-scoped methods remain unsafe to call
without an id — use `forSpace(id).ai`.

---

## Noted (no action)

### 8. `cn()` is plain `clsx` — no `tailwind-merge`
`resources/js/lib/utils.ts:3`. Conflicting utilities both survive and stylesheet order
decides. Tests must not assume de-duplication. Matches existing project convention.

### 9. happy-dom silently breaks DOMPurify
Not product code, but worth recording: the suite runs on **jsdom** deliberately.
happy-dom's parser mangles the fragments `~/lib/sanitize` passes to DOMPurify enough that
sanitization silently no-ops — `<script>` survived and `<p>` was stripped. A security
helper must be tested on a faithful DOM. See the comment in `vitest.config.ts`.

### 10. Stale ad-hoc test deleted
`resources/js/composables/useHandlebars.test.mjs` was a `node:assert` script no runner
invoked, and it had rotted: it asserted `{{image}}` returns a bare URL, while the code now
emits an `<img>` tag. Ported to `tests/js/composables/useHandlebars.test.ts` and removed.

---

# Reported by the test sweep

Grouped by area. Severity is my triage: **HIGH** = user-visible bug or security
consequence · **MED** = wrong-but-contained, or a trap for the next change
· **LOW** = cosmetic / dead surface.

**Status:** every finding below is **FIXED unless explicitly marked OPEN or NOTED.** The fix
pass on this branch resolved them and retargeted each pinning test to assert the corrected
behaviour; all HIGH findings and a broad sample of MED/LOW were individually re-verified
against the current source during this status update. Descriptions and line numbers are as of
audit time — treat cited line numbers in fixed findings as historical, not current.

## `utils/svg.ts` — icon normalization (46 tests)

- **HIGH — colour normalization only understands double-quoted attributes** (`:37,43`).
  `<path fill='#f00'/>`, `style='fill:#f00'` and colours declared in an inner `<style>`
  block all keep their baked-in colour. "Icon inherits currentColor" is a best-effort
  string rewrite, not a guarantee — single-quoted or stylesheet-driven icons ignore
  theming. Most likely real visual bug in this batch; uploaded SVGs run through it.
- **MED — `\b(fill|stroke)="…"` also matches attributes *ending* in fill/stroke** (`:37`).
  The `\b` sits after the hyphen, so `data-fill="#abc"` → `data-fill="currentColor"`.
  Silently corrupts custom/data attributes on uploaded icons.
- **MED — `parseFloat` strips CSS units instead of rejecting them** (`:18`).
  `width="100%"` → `100`, `3em` → `3`, so a percent-sized icon records a nonsense pixel
  size rather than falling back to 24×24. The icon registry stores these dimensions.
- **LOW — `fill=""` is treated as a colour** (`:30`) and becomes `currentColor`.
- **LOW — the `try/catch` at `:21` is dead.** `DOMParser` never throws for
  `image/svg+xml`; malformed XML yields a `<parsererror>` document, caught by the
  `tagName !== 'svg'` check instead. Gives false confidence.

## `lib/csrf.ts` (22 tests)

- **HIGH — `console.warn` dumps the entire cookie jar** (`:10`): the message logs every
  non-HttpOnly cookie **name and value** on every token miss. Reached from
  `getXsrfHeaders`, which runs on every export/import request, so this fires in
  production and lands in console logs, log forwarders and session-replay tools.
- **MED — `startsWith('XSRF-TOKEN=')` is a prefix match, not an exact name compare**
  (`:7`). A server-emitted cookie whose name contains `=` can be accepted, yielding the
  wrong token; first match wins with no duplicate detection. Split on the first `=` and
  compare the name instead.
- **MED — `ensureCsrfToken` waits a hardcoded 100 ms, then gives up** (`:60`). Not a
  retry loop, and it cannot distinguish "server failed" from "we didn't wait long
  enough". Callers (`~/lib/import-export`, AI composables) treat it as a hard failure.

## `lib/iconify.ts` (35 tests)

- **MED — the collection cache is unbounded, un-invalidatable, and shared by reference**
  (`:39,63`). Three problems: `[]` is truthy so an empty/failed result is memoised for
  the whole session with no TTL; `get()` hands every caller the *same array instance*, so
  a consumer that sorts or splices it mutates the cache (pinned by a test); concurrent
  requests for one prefix both miss and both fetch — exactly the icon picker's
  chip-switching pattern.
- **MED — `fetchIconifyCollection` and `searchIconifyIcons` never check `response.ok`**
  (`:49-64`), unlike `fetchIconifyCollections` (`:18`) which does. A 5xx HTML page
  surfaces as an opaque JSON parse error; a JSON error body is read as data and yields an
  empty list.

## `lib/ilum.ts` — image transform URLs (22 tests)

- **MED — only `undefined` is filtered, so `null` becomes an operation** (`:29`).
  `{width: null}` → `w_null` in the path, i.e. a broken transform URL. Reachable from any
  caller spreading a nullable asset field into modifiers.
- **LOW — operation values are never encoded.** `gravity: 'a b/c'` lands raw in a path
  segment. All current callers pass numbers or enum-ish strings.
- **LOW — only one trailing slash is stripped from the base URL** (`:44`).

## `lib/runtime-config.ts` (25 tests)

- **MED — a truthy-but-empty `echo: {}` turns realtime on with no key** (`:66,69`).
  `realtime: … ?? Boolean(appConfig?.echo)` plus the object spread means Echo is handed
  `key: undefined` and tries to connect. Disabling realtime requires an explicit `null`.
- **LOW — `echo.enabledTransports` is declared in the config type but read nowhere**
  (`:37` vs `:77`, which hardcodes `['ws', 'wss']`). A ws-only self-hosted deployment
  still advertises `wss`. Dead config surface that looks live.
- **LOW — `ilum.baseURL` has no default** (`:81`) while every other URL does, so images
  silently resolve against the current origin instead of failing loudly.

## `lib/aiErrors.ts` (26 tests)

- **MED — `KNOWN_REASONS` hand-duplicates the `AiErrorReason` union** (`:15-23`) and omits
  three keys that exist in `en.json` (`csrfUnavailable`, `noSpace`,
  `noSpaceOrDataSource`), so the backend cannot select those messages by `reason`. Adding
  a union member without editing the Set makes the new reason untranslatable with **no
  type error**.

## `utils/text-diff.ts` (19 tests)

- **MED — `toDisplayText` has no guard around `JSON.stringify`** (`:10`). A circular
  object throws a `TypeError` out of what reads like a display formatter. `JSON.stringify`
  also drops `undefined` values, so two objects differing only in an `undefined` key
  render identically and the version diff shows "no change". Input here is arbitrary
  block content from the RTE/version-history diff renderers.

## `api/client.ts` + `api/index.ts` + `api/resources/base-resource.ts` (143 tests)

The single choke point every request goes through.

- **HIGH — nested query objects are stringified to `[object Object]`, and there is a live
  caller.** `client.ts:57` does `params.set(key, String(value))`.
  `useContent.ts:86` calls `contents.index({ filter: { parent_id } })`, which goes out as
  `?filter=%5Bobject+Object%5D`. So `useContentChildrenQuery` requests the **unfiltered**
  content list and relies on whatever the backend happens to default to — the parent
  filter never reaches the server. `ContentsQueryParams.filter`
  (`api/resources/contents.ts:25`) advertises this shape as supported.
  *Pinned by:* `tests/js/api/client.test.ts` → "stringifies a nested object".
- **HIGH — `api.ai` is constructed without a space id, so its space-scoped methods would request
  `/spaces/undefined/...`.** `index.ts:73` does `new Ai(this.client)` while
  `api/resources/ai.ts:44` types `spaceId?: string`. Everything but `getStreamUrl()` needs
  `forSpace(id).ai`. **Not reachable** — the accessor is narrowed to
  `Pick<Ai, 'getStreamUrl'>` (`index.ts:97`), so `api.ai.getAiConfigs()` is a compile error, and
  the one exposed method handles an absent id (`ai.ts:51`). See Open #8 for the caveat: the
  guarantee is the return type, not a runtime check.
- **MED — `error.message` can be the empty string.** `client.ts:101` uses
  `errorData.message || response.statusText`, and `statusText` is empty over HTTP/2. A 500
  with a message-less JSON body yields `Error` with `message === ''`, so every
  `toast.error(error.message)` path renders an empty toast. No `HTTP ${status}` fallback.
- **MED — non-JSON error bodies are discarded entirely.** `client.ts:92-100` reads the body
  only when the content type includes `application/json`, so a 502 HTML page or a
  `text/plain` Laravel error yields `error.data === {}` and no message. Debug info is
  unrecoverable at the call site. `application/problem+json` also fails the check.
- **MED — arrays are comma-joined rather than repeated as `key[]=`.** `{tags: ['a','b']}` →
  `?tags=a%2Cb`. `AssetsQueryParams.tags` and `rights_status` are typed `string | string[]`,
  so the backend must split the list itself, and a value containing a comma is
  unrecoverable.
- **MED — `request()` never sends the auth token.** `getAuthHeaders()` exists
  (`client.ts:47`) but `makeRequest` (`:129`) merges only default + XSRF + caller headers,
  so `api.setAuthToken(x)` has no effect on any resource call. Only the hand-rolled helpers
  (`~/lib/import-export`, ilum) read it. Nothing is broken today — the app authenticates by
  cookie — but `setAuthToken` is a no-op trap for whoever adds token auth.
- **MED — `ensureCsrfCookie` fetches a relative URL and never verifies the cookie landed.**
  `client.ts:70` calls `fetch('/auth/v1/csrf-cookie')`, ignoring `baseURL`, so a
  cross-origin client primes the wrong host; and `csrfReady` is set from `response.ok`
  alone without checking `hasXsrfToken()` — unlike `~/lib/csrf.ts:54`, which does.
  Failures are only `console.warn`ed.
- **MED — successful responses without a JSON content type are returned as raw text.**
  `client.ts:108`. A JSON payload served with the wrong content type reaches callers as an
  unparsed string, and `delete()` — typed `Promise<void>` — actually resolves with `''` on
  a 204.
- **LOW — the "retry once" budget can reach three attempts.** `client.ts:144-166` retries a
  419 exactly once (verified), but that retry's failure falls into `handleAuthError`
  (`:161`), which can fire a third request when the handler returns `{retry: true}`. Safe
  today: `useAuth.handleUnauthorized` (`useAuth.ts:343`) always returns `{retry: false}`,
  which also makes the `retryInfo?.retry` branch at `client.ts:183` dead code.
- **LOW — a 419 on a safe method skips the CSRF refresh but still reaches the auth
  handler.** `client.ts:153` guards on `!isSafeMethod`; `:175` treats 419 as an auth error
  regardless of method. So a 419 on a GET goes to session-expiry/logout handling instead of
  a cookie refresh. Theoretical (Laravel only 419s on stateful writes) but the two
  conditions disagree.
- **LOW — `forSpace()` memoizes nothing.** `index.ts:142-179` allocates ~34 resource
  objects per call, and it is typically wrapped in a `computed`, so every re-evaluation
  churns them. No resource instance identity is stable across renders.
- **LOW — the exported singleton has an empty base URL.** `index.ts:182` is `new API()`, so
  `window.__APP_CONFIG__.apiBaseUrl` is never applied and `getBaseUrl()` returns `''`.
  `Ai.getStreamUrl()` and the import/export helpers build URLs from it, so a decoupled
  frontend deployment would need this wired up.
- **LOW — a query whose every value is dropped still emits a bare `?`** (`client.ts:53`),
  so URL-keyed HTTP caches see two URLs for one request.
- **LOW — ids are interpolated into paths unencoded** (`base-resource.ts:34,45,49,53`).
  Safe for ULIDs; `get('a b/c')` silently addresses a different route, `get('')` produces a
  trailing slash.
- **LOW — `custom()` silently drops the payload on GET and DELETE**
  (`base-resource.ts:63-74`), and offers no way to pass a query string except embedding it
  in `endpoint`, which bypasses `resolveUrl`'s encoding.
- **LOW — `getPath()` is used only by `custom()`** (`base-resource.ts:19`) while every other
  method inlines `${this.basePath}/...`. Two joining conventions in one 76-line file; a
  subclass overriding `getPath` would affect only `custom`.

## `utils/plugin-bridge.ts` — sandboxed field-plugin iframe (58 tests)

- **HIGH (security) — the source guard has a hole `PreviewBridge` does not.**
  `plugin-bridge.ts:77` checks `!this.iframeElement || event.source !== this.iframeElement.contentWindow`.
  `preview-bridge.ts:99` additionally guards `!this.iframeElement?.contentWindow`. Without
  that, when the frame has **no** `contentWindow` (not yet loaded, detached, navigated
  away) a message with `event.source === null` compares `null !== null → false` and
  **passes the source check**. Only the handshake token then stands between an unsourced
  message (worker/port relay, extension injection, detached document) and the host's
  `VALUE_CHANGE` / `ASSET_SELECT_REQUEST` handlers. Adding `?.contentWindow` is
  behaviour-preserving for real plugins.
  *Pinned by:* "accepts a source-less message while the iframe has no content window".
- **MED (security) — the "immutable handle" invariant is compile-time only.**
  `plugin-bridge.ts:67,92`: `public readonly token` and `private iframeElement` are plain
  own properties at runtime (`Object.keys(bridge)` includes `iframeElement`). Anything
  holding the bridge can swap the token or re-point the frame — exactly what the invariant
  exists to prevent. `#private` / `Object.freeze` would make it real.
- **LOW (by design, worth stating) — no origin allowlist in either direction**
  (`:76-90`, `postMessage(…, '*')` at `:103`). Intentional for `sandbox`ed frames with an
  opaque origin and the docblock says so, but any origin loaded into that iframe can talk
  to the host once it learns the fragment token.
- **LOW — `data.type` is only checked to be a string** (`:86`), never against
  `PluginMessageType`, so a plugin can trigger off-protocol event names. Harmless today
  (no listener), but the type union gives no runtime guarantee.

## `lib/sse.ts` — server-sent event streaming (54 tests)

- **HIGH — a `done` event does not stop the read loop.** `dispatchSseEvent` returning
  `true` only records `receivedDone` (`:87-114`); the reader is drained to the end. So
  anything the server writes after `done` (or after `error`) still reaches the callbacks,
  and a **second `done` fires `onDone` twice** — which would make `useAiContentTree` apply
  its tree operations twice. Pinned by two tests.
- **MED — no `reader.cancel()` / `releaseLock()` on any exit path** and no `try/finally`
  (`:79-119`). On a thrown read the lock is never released and the tab keeps the body
  stream locked until GC. `useAiStream`'s abort masks it in practice.
- **MED — the `data: [DONE]` convention is not handled** (`:60-77`). Only a JSON frame with
  `type: 'done'`/`'error'` completes a stream; a `[DONE]`-terminated stream ends with
  `onError('Stream ended without completion event')`. Fine for b10cks' own backend, a trap
  for anyone pointing these endpoints at a raw provider stream.
- **LOW — `parseSseEvent` validates JSON syntax only, never shape** (`:48-58`):
  `data: [1,2]` returns `[1,2]` and `data: 7` returns `7`, both typed `SseEvent`. The
  `switch` in `dispatchSseEvent` is what actually filters them; the return type lies.
- **LOW — dead clauses at `:49`.** In
  `!line.startsWith('data: ') || line.startsWith(':') || !line.trim()` the 2nd and 3rd are
  unreachable — a line starting with `data: ` can never start with `:` nor be blank. Reads
  as protection that isn't doing anything.

## `useAiStream` / `useAiContentTree` / `useAiMentions` (88 tests)

- **HIGH — `useAiContentTree.ts:135` captures to the end of the string, not to the closing
  `]`.** `/"operations"\s*:\s*\[([\s\S]*)$/` means `balancedSpans` also walks sibling keys
  *after* the array, so
  `{"operations":[{"type":"create"}], "summary":{"type":"rename","id":"x"}}` yields **two**
  operations — the summary object is applied as a rename. Anything the model emits after
  the array carrying an operation-shaped `type` becomes a phantom operation. The streaming
  tree UI consumes `extractStreamingTreeOperations` directly.
  *Pinned by:* "also picks up objects that follow the closed operations array".
- **MED — `useAiMentions.ts:63` slices the concatenated list, contents first.**
  `items.slice(0, 50)` with contents before blocks means that in a space with ≥50 contents
  **no block can ever be mentioned** until the search narrows the tree.
  *Pinned by:* "starves the blocks when the content tree already fills the cap".
- **MED — `useAiMentions.ts:20` traverses on the parent's `children` boolean flag, not on
  actual `pid` links.** A folder whose flag is stale/false hides every descendant from the
  mention list even though they are present in `menuData`.
- **MED — `useAiStream.ts:54` does `catch (error: any)` then reads `error.name` unguarded.**
  A rejection of `null`/`undefined` throws a `TypeError` *inside* the catch, so `stream()`
  rejects instead of calling `onError` — and every caller `await`s it without a catch, so
  that becomes an unhandled rejection. Also violates the project's no-`any` rule.
- **MED — `useAiStream.ts:29,58` overwrites `abortController` unconditionally.** Two
  concurrent `stream()` calls on one instance: the second clobbers the first's controller
  so `cancelStream()` can no longer abort it, and the first's `finally` nulls the second's
  controller mid-flight, making `isStreaming` false while a stream is live. No caller does
  this today, but nothing prevents it.
- **LOW — `parseTreeOperations` (`useAiContentTree.ts:120`) only strips fences**, with no
  balanced-extraction fallback, so prose-wrapped JSON returns `null` even though the
  sibling `parseAiJson` (`lib/aiJson.ts:14`) would parse it. The streaming path is very
  tolerant and the final path is strict — inconsistent.
- **LOW — `isTreeOperation` (`useAiContentTree.ts:87`) accepts `{type: 'create'}` with no
  other field**; every operation field is optional, so a `create` with no `name`/`parent_id`
  passes validation and reaches the applier.
- **LOW — `useAiMentions.ts:31`**: the search term is lower-cased but not trimmed, and only
  `''` short-circuits, so a one-space query matches every multi-word label. Content matches
  on label only while blocks match on label *or* slug — asymmetric.
- **LOW — `useAiMentions.ts:11`** silently truncates at `per_page: 1000` blocks; and a
  block's mention `id` is its **slug** while content's is its **id** (deliberate, but easy
  to misread).

## Content wizard — slug, layout, keyboard, viewport, apply, collaboration (222 tests)

- **HIGH — the keyboard guard sits below the shortcuts it should suppress.**
  `useContentWizardKeyboard.ts:161`: the editing-field / block-select guard is placed
  *after* Tab, Enter, Delete, Escape, F2 and the arrows. So with the block-select popover
  open, `Enter` creates a sibling node on the canvas instead of choosing the highlighted
  block, and `Escape` clears all transient state rather than just closing the popover. The
  guard only protects type-to-edit. **Most likely user-visible bug in this batch.**
- **MED — `resolveEffectiveSlug` never normalizes an explicit slug**
  (`useContentWizardSlug.ts:41`). `resolveEffectiveSlug('Hello World', 'Not A Slug!')`
  returns `'Not A Slug!'` verbatim, and `useContentWizardApply.ts:107,133` feeds exactly
  that into the create/update payload — front-end slug hygiene applies to auto slugs only,
  whatever the server accepts becomes the slug. *Depends on it:* yes —
  `useContentWizardTree`'s dirty check and `apply`'s `shouldUpdate` both call it.
- **MED — the success path never invalidates** (`useContentWizardApply.ts:246`).
  `invalidateContentQueries` runs only in the `catch`. Success relies on each mutation's own
  `onSuccess`, which covers contents + contentMenu but **not** `blocks(...).lists()` — which
  the error path *does* invalidate. With no structural operations (field updates only),
  block lists stay stale.
- **MED — field updates are a sequential loop with no rollback**
  (`useContentWizardApply.ts:230-244`). A failure on the Nth update leaves 1..N-1 applied
  *and* the whole structural batch applied, yet returns `success: false`; the caller cannot
  tell how far it got. The error path invalidates so the UI resyncs, but partial
  application is real.
- **MED — `apply` is not idempotent** (`:45-261`). The plan is read from the tree, which
  `apply` never resets or reconciles, so a second call re-sends the identical batch
  (verified: two identical `mutateAsync` calls). Safe only because `canvas.vue` reloads the
  tree from the server afterwards.
- **MED — `ß` is not folded while every other umlaut is**
  (`useContentWizardSlug.ts:5-12`). `'Über Größe'` → `uber-große`: NFKD plus
  combining-mark stripping cannot reach `ß`, so slugs mix ASCII-folded and non-ASCII
  output. `resolveSlugMode` still compares consistently, so nothing breaks — URL quality
  only.
- **MED — braces and slashes are stripped, not converted** (`useContentWizardSlug.ts:10`).
  `'{lang}/about'` → `langabout`. Nothing in the wizard emits `{lang}` today (it lives in
  `SerialBlock.vue`), but a pasted pattern is destroyed with no warning.
- **LOW — `onOperation` subscriptions are not torn down on unmount**
  (`useContentWizardCollaboration.ts:198-203`). Only the focus and cursor listeners are
  stopped. `canvas.vue:740,2052` stores the unsubscribe itself so nothing leaks today, but
  the asymmetry is a trap for the next caller.
- **LOW — Alt is ignored for `Tab` on the virtual root**
  (`useContentWizardKeyboard.ts:73-80`): the root branch returns before the `altKey` check,
  so Alt+Tab on the root cannot force the block picker while Alt+Enter on the root can.
- **LOW — the 240px floor on usable height exceeds short containers**
  (`useContentWizardViewport.ts:78,142`). For a 200px-tall container `fitToView` fits to
  240px, so fitted content cannot actually fit; the same floor centres
  `getViewportCenterOffset` below the visible area.
- **LOW — two different size sources** (`useContentWizardViewport.ts:46-48` vs `:76-79`):
  padding/origin use the ResizeObserver-measured size, centring uses
  `clientWidth/clientHeight`. Before the first observation they disagree — the only path on
  which the `Math.max(0, …)` clamp at `:103` is reachable. One-frame mis-centre on first paint.
- **LOW — `CANVAS_MIN_HEIGHT = 4200` is dead** (`useContentWizardViewport.ts:12`):
  `canvasSize.height` always includes `2 * padY + 220` with `padY ≥ 2000`, so ≥ 4220. The
  constant can never be the maximum.
- **LOW — pan deltas are not divided by `viewport.scale`**
  (`useContentWizardViewport.ts:261`). Raw client-pixel deltas go into `scrollLeft/Top`,
  which is already scaled space, so panning tracks the cursor 1:1 at every zoom. That is
  the desired feel, but it is scale-independent by accident rather than by intent.
- **LOW — no visited set in `place`** (`useContentWizardLayout.ts:28-48`): a node reachable
  from two parents is placed twice, the later row wins and the earlier is left as a blank
  gap, inflating `bounds.height`. Needs a malformed tree — latent.
- **LOW — `node.isRootVirtual ? 1 : depth + 1` keys child depth off the *parent's* virtual
  flag** (`useContentWizardLayout.ts:46`). Only the real root carries the flag, where
  `1 === depth + 1`, so the branch is dead; a nested node with the flag would send its whole
  subtree back to column 1.
- **LOW — remote focus/cursor state is stored for unknown users**
  (`useContentWizardCollaboration.ts:169-192`) and only filtered at render time;
  `cleanupAbsentUsers` prunes on the next presence change, so it is unbounded only between
  two presence events.
- **NOTED — `useContentWizardKeyboard` registers no global listeners**, so the leaked-listener
  bug class does not exist here (asserted by a test). `canvas.vue:2045,2049` owns the one
  `window` keydown listener and removes it correctly.

## Leaf components — search, markdown, tables, selects, titles (260 tests)

- **HIGH — `SearchFilter` silently drops the free-text `q` key from everything it emits.**
  `SearchFilter.vue:138-150,212`: `parseModelValue` skips `q` and `serializeFilters` never
  re-adds it, so the first filter change after a search replaces `{q: 'hello'}` with
  `{status: 'draft'}`, and `clearAllFilters` emits `{}` — wiping `q` too. An active text
  search is lost the moment the user adds or removes a filter, unless the parent merges `q`
  back in itself.
  *Pinned by:* "drops the free-text q key from what it emits back".
- **HIGH (a11y) — renaming is mouse-only across every tree in the app.**
  `RenamableTitle.vue:129-134`: the display element has `@dblclick` but no `role`, no
  `tabindex` and no keydown handler (`type="button"` on a `<span>` is meaningless). Keyboard-only
  users cannot start a rename in the content tree, asset folder tree, tag tree or collection
  tree — six call sites. The parent-exposed `startEdit()` is the only other route.
- **MED (security) — `<form>` and `<input>` survive markdown sanitization.**
  `Markdown.vue:10` + `lib/sanitize.ts:14`: DOMPurify's `USE_PROFILES: {html: true}` allows
  them, so markdown can render `<form action="https://evil.test"><input name="password">`
  inside the CMS origin. Scripts, `on*` handlers, `javascript:` URLs, `iframe` and `style`
  *are* correctly stripped (16 assertions cover that). This is a phishing surface, not
  script execution — worth `FORBID_TAGS: ['form','input','button']` on `sanitizeHtml`.
- **MED (security, latent) — `GradientText` splices unescaped colours into a `v-html`
  string.** `GradientText.vue:20-28` escapes `content` but interpolates the colour values raw
  into `style="background-image: linear-gradient(to right, ${colors})"`. A colour like
  `blue"><img src=x onerror=…>` breaks out of the attribute and injects live HTML. All call
  sites pass literals today, so not exploitable — but it is a `v-html` sink with one
  unescaped input.
- **MED — value-less filter operators serialize with a trailing colon.**
  `SearchFilter.vue:143`: `null`/`!null`/`empty`/`!empty` commit with `value: ''` and still
  emit `` `${operator}:${value}` `` → `views=null:`. Given the project's own
  `advanced-filter-operator-gotcha` note about strict operator:value parsing, the backend
  may not tolerate the empty tail.
- **MED — `BlockType`'s unknown-type fallback contradicts its own comment.**
  `BlockType.vue:60-67`: the comment promises "fall back to rendering the raw type name",
  but `return known ?? { cls: props.type }` puts the type into `cls`, so an unrecognised
  type renders an **empty** badge with the type name as a CSS class. Any new field type not
  added to the map shows as a blank grey box in the schema editor.
- **MED (a11y) — `ColorSelect` has no accessible name and colour names are `title`-only.**
  `ColorSelect.vue:13-35`: the trigger holds only a decorative swatch — no text, no
  `aria-label`. Each option is a bare coloured `<div>` with its name in `title`. Keyboard
  and screen-reader users cannot tell what is selected or what any option is. Also
  `background-color: ${null}` renders as no style, so "no colour" and "none selected" look
  identical.
- **MED (a11y) — `PerPageSelect`'s sr-only label labels nothing.**
  `PerPageSelect.vue:30-39` puts `id` and `aria-labelledby` on `<Select>`, which is
  reka-ui's renderless `SelectRoot`; both attributes are dropped, so no element carries that
  id and the trigger gets neither. Screen readers announce an unnamed combobox whose only
  content is a bare number. `TablePaginationFooter` inherits this.
- **MED (a11y) — the descriptive filter-removal label lands on a non-interactive div.**
  `SearchFilter.vue:572-584` + `SplitBadge.vue:70-74`: `aria-label="Remove: {field}
  {operator} {value}"` falls through to `SplitBadge`'s root `<div>`, while the real remove
  `<button>` is hardcoded `aria-label="Remove"`. A screen-reader user hears "Remove" with no
  idea which of several filters goes.
- **MED — editing a filter is mouse-only** (`SearchFilter.vue:580`): the badge has
  `@click="editFilter(index)"` but no `role`, `tabindex` or key handler, so filters can be
  removed by keyboard but never edited.
- **MED — `Markdown` link interception is shallow and lossy** (`Markdown.vue:14,30`):
  `e.target.tagName === 'A'` misses clicks landing on a child element, so `[*deep*](/docs)`
  triggers a full page navigation; and `router.push(url.pathname)` discards query and hash,
  so `/docs?tab=2#anchor` navigates to `/docs`.
- **MED — a one- or two-row `TableLoadingRow` is nearly invisible.**
  `TableLoadingRow.vue:24-28`: `half = rows / 2` is `0.5` for `rows: 1`, so index 1 is past
  halfway and hits the 0.15 opacity floor.
- **LOW — `RenamableTitle`'s `<slot :name="name">` is a dynamic slot *name*, not a slot
  prop** (`:142-145`). A caller writing `<template #default>` is silently ignored, and the
  slot identity would change on every rename. All six call sites use the self-closing form,
  so latent — but the slot is unusable as written.
- **LOW — `RenamableTitle`'s `update` emit declares an `itemId` it never sends** (`:22-24,55`);
  same for `cancel`/`edit-start`. Misleading for consumers destructuring a second arg.
- **LOW — `RenamableTitle`'s `fallback` prop is unreachable** (`:145`): `name` is a required
  `string`, so `name ?? fallback` never takes the fallback.
- **LOW — `TableLoadingRow` accepts `icon` and `label` and renders neither** (`:8-18`),
  declared "for backwards compatibility" — a call site still passing `label` gets silence.
- **LOW — `TableEmptyRow` treats a string `icon` as a tag name** (`:33-36`). The prop is
  typed `string | Component` and `<Component :is="icon">` resolves a string as an element
  name, so `icon="lucide:box"` renders an unknown element. Only a real component works;
  the default `''` renders nothing without warning.
- **LOW — `SortSelect` discards the direction when repairing a blank column** (`:43-52`):
  `{column: '', direction: 'asc'}` becomes `{column: 'created_at', direction: 'desc'}`.
  Also a `column` not present in `options` renders the *placeholder*, so the UI claims
  nothing is sorted while the list is in fact sorted.
- **LOW (a11y) — `BlockType` badges have no accessible name** (`:71`): field type is conveyed
  by colour plus glyph only, and the colour grouping is the sole distinction between
  `number`/`date`/`text`.

*Test-infrastructure note from this batch:* reka-ui's portalled listbox **is** reachable in
jsdom once `Element.prototype.hasPointerCapture/setPointerCapture/releasePointerCapture/scrollIntoView`
are stubbed. Done locally in the three select test files; worth promoting into
`tests/js/setup.ts`.

## Formatters and small utility composables (303 tests)

- **HIGH — `formatFileSize` renders the literal string `undefined`.**
  `useFormat.ts:144-154`: the unit index `Math.floor(log(bytes)/log(1024))` is never clamped.
  `≥ 1 EiB` → `'1.0 undefined'`; `0 < bytes < 1` → index `-1` → `'512.0 undefined'` for
  `0.5`; negative / `NaN` / `Infinity` → `'NaN undefined'`. Any asset whose `size` is
  negative, sub-byte or ≥ 1 EiB shows `undefined` in the asset library, size columns and
  usage panels — and `NaN` is reachable in practice from an absent `size` coerced by a caller.
- **HIGH — a string `titleTemplate` leaks past the component that set it.**
  `useSeoMeta.ts:67-71` vs `:86`: the dispose check compares
  `currentTitleTemplate === unref(scopedTitleTemplate)`, but a string template was wrapped
  into a closure at `:86`, so the identity never matches and the module-level template
  survives. A page setting `titleTemplate: '%s · b10cks'` keeps decorating **every later
  page's title** after you navigate away. Function templates clean up correctly.
  *Pinned by a test.*
- **MED — `useSeoMeta` never removes meta tags on dispose** (`:28-58,67-71`).
  `onScopeDispose` only touches the title template, so `description` / `og:*` from a previous
  route persist in `<head>` on any page that sets none. All state is module-global and
  last-writer-wins, with no ownership tracking.
- **MED — `useFormat` snapshots the locale and never re-reads it** (`:11`).
  `const locale = ref(getLocale())`, so an instance created before a language switch keeps
  formatting in the old locale for its whole lifetime. Long-lived components that call
  `useFormat()` in `setup` do not follow the language switcher. *Pinned.*
- **MED — `useFormat()` writes English calendar strings onto whatever locale is active**
  (`:19-28`). `dayjs.updateLocale(locale.value, { calendar: … })` runs on every call with
  hardcoded "Yesterday at" / "Last dddd", so `formatCalendarTime` is untranslated in German —
  and it is global cross-module state.
- **MED — `formatRelativeTime` renders an invalid date as `'a month ago'`** (`:34-36`).
  dayjs pushes a `NaN` diff through its thresholds, so an unparsable or absent timestamp
  displays as plausible copy instead of `Invalid Date` — bad data becomes invisible.
  (`formatDateTime` correctly shows `Invalid Date`.)
- **MED — `useUsageFormatters` ignores the app locale** (`:18,25`): `new Intl.NumberFormat()`
  and `formatUnit('usd')` with `undefined` locale use the *browser* default while
  `useFormat` uses the app locale, so German users see mixed grouping and decimal styles on
  the subscription and usage pages.
- **MED — negative byte values always report as MB** (`useUsageFormatters.ts:11-13`):
  `gb >= 1` is false for negatives, so `-2 GiB` renders `'-2048 MB'`. Reachable wherever a
  delta or remaining-quota figure goes negative. It also never scales past GB — `5 TiB`
  renders `'5120 GB'`.
- **MED — a synchronous throw in `prefetch` escapes and still poisons the dedupe.**
  `useHoverPrefetch.ts:29-33`: `Promise.resolve(prefetch(payload))` evaluates the call before
  the promise wrapper, so a sync throw becomes an uncaught exception inside the `setTimeout`
  callback — and because `done.add(payload)` runs *first*, the payload is marked complete and
  never retried. Only rejections are handled. *Pinned.*
- **MED — legacy Office mime types classify as `other`** (`useFileUtils.ts:7-13`). The
  substring checks look for `document`/`spreadsheet`/`presentation`, so `application/msword`
  (.doc), `application/vnd.ms-excel` (.xls) and `…macroEnabled.12` (.xlsm) get the generic
  icon and fall outside the `document` filter in the asset library. Also case-sensitive
  (`:3-6`): `IMAGE/PNG` → `other`, though mime types are case-insensitive per RFC 2045.
- **MED — `formatCurrency` throws `RangeError` on an unknown currency code**
  (`useFormat.ts:74-80`). An unexpected `currency` from a plan/subscription payload breaks
  rendering rather than degrading.
- **LOW — unknown enum values leak raw i18n keys into the UI.**
  `useTeamTypes.ts:23` returns `'labels.teams.types.ghost'`; and
  `useNotificationPresentation.ts:39` renders "Acme is nearing its
  notifications.metrics.seats limit" — `notifications.metrics` only has
  `storage`/`traffic`/`ai`, so any new backend quota metric surfaces the key mid-sentence.
- **LOW — USD precision is asymmetric** (`useUsageFormatters.ts:29`):
  `value > 0 && value < 1 ? 4 : 2`, so a sub-cent *credit* renders `-$0.00` while the same
  magnitude of spend renders `$0.0123`. Relevant to AI-spend refunds.
- **LOW — `usePlanPricing` treats `yearly_price: '0.00'` as a yearly plan** (`:8,11`) because
  the check is string truthiness. A yearly-only plan under a monthly interval also shows
  "/ year" while `checkoutInterval` returns `'month'`.
- **LOW — missing actors substitute empty strings**
  (`useNotificationPresentation.ts:44-54`): `' mentioned you'` / `'Invitation to '` when
  `data.author` / `data.space` are absent. No "Someone" fallback.
- **LOW — `formatFileSize` boundary rounding** (`useFormat.ts:147`): the unit comes from the
  raw byte count while the mantissa rounds independently, so `1023.6` renders `'1,024 B'`.
- **LOW — `useHoverPrefetch`'s `done` set grows unbounded and is not cleared on dispose**
  (`:16,53`), and dedupe is identity-keyed (`:15`) so fresh object payloads never dedupe.
  `useRoutePreload` works around this by keying on `fullPath`.
- **LOW — `useRoutePreload` resolves each location twice per hover** (`:43` + `:21`).
- **LOW — `useUlid` is not monotonic across out-of-order timestamps** (`:12`): the check is
  `nowStr === lastTime.value` (equality only), so an earlier `Date` after a later one yields
  a smaller ULID. Only affects callers passing explicit dates. The carry/overflow logic is
  correct; `padStart(10,'0')` at `:45` is dead.
- **NOTED — cleanup verified clean** in `useHoverPrefetch` and `useRoutePreload`:
  `onScopeDispose(() => cancel())` clears every pending timer (`vi.getTimerCount() === 0`
  after `scope.stop()`). No leaked timers.

*Test-infrastructure note:* `localStorage` is unavailable in this setup — Node installs its
own (undefined without `--localstorage-file`) and, because jsdom's `window === globalThis`,
it shadows jsdom's implementation. `useStorage`-based composables therefore get no
persistence backend. To be fixed in `tests/js/setup.ts` at integration.

## Content schema state, field options, versions (379 tests)

The editor's normalization, condition-evaluation and validation core.

- **HIGH — `focusFirstInvalidField` does nothing for 300 ms after the last edit.**
  `useContentSchemaState.ts:874-880` reads `getFirstInvalidFieldPath()` **before** calling
  `revealValidationState()`, which is what refreshes the 300 ms-debounced `clientErrors`. Submit
  or focus within that window and `path === null`, so it silently no-ops. Every editor submit
  path that focuses the first error depends on this.
  *Pinned by:* "does nothing while the debounced errors are still stale".
- **HIGH — validation and sanitization pair translated blocks differently, so required-field
  errors land on the wrong block.** `useContentSchemaState.ts:693-696` (`validateScope`) pairs a
  block item with its source item **by index only**, while `:333-400` (`pruneScope`) pairs **by
  `id` first, index second**. With a reordered translation the two passes disagree and validation
  reads a different source item than sanitization did.
- **HIGH — an option whose value is `0` is dropped and can never be selected.**
  `useFieldOptionChoices.ts:37-38` does `String(option?.value || '')`, so `0` (and `false`)
  becomes `''` and is filtered out. Any numeric option list starting at zero loses that choice.
  *Pinned.*
- **MED — an unknown condition operator hides the field.**
  `useContentSchemaState.ts:269-298`: `matchesCondition` has no `default` branch, so an
  unrecognised operator returns `undefined` → field hidden. Same function: `not_in` against a
  non-array expected value returns `false` (hides) rather than vacuously true; `contains`
  flattens a numeric `0` to `''` via `String(actual || '')` and matches everything when the
  expected value is empty; `equals`/`not_equals` use `==`, so `equals: null` matches a
  never-set field.
- **MED — pruning does not cascade.** `useContentSchemaState.ts:315-322,344`: a field deleted
  from the pruned scope is still visible to downstream conditions, because the "local key
  absent" fallback reads its old value from `effectiveScope`. A dependency chain `a → b → c`
  therefore does not collapse.
- **MED — `validateScope` and visibility read different data**
  (`useContentSchemaState.ts:462`): values come from `effectiveScope` (the source document)
  while visibility is decided from the pruned local values.
- **MED — `max_length` falls back to `maximum` but `min_length` has no `minimum` fallback**
  (`:199-201`), so a `maximum: 8` on a text field silently becomes a max **length** of 8.
- **MED — `useContentVersions` ignores a caller-supplied `sort` but still keys on it**
  (`:45-49`): `sort: '-created_at'` is spread *after* the caller params, so the caller's value is
  overwritten — yet it is part of the query key, so two cache entries issue the identical
  request.
- **MED — `useContentVersions` mutations are not gated on `hasContentId`** the way its queries
  are (`:18`). Without a content id they still fire against `/contents//versions/{id}/publish`
  and invalidate `contents.detail('')`.
- **MED — `isFieldVisible`'s catch handler can throw a second time**
  (`useContentSchemaState.ts:327-330`): it dereferences `field.key`, so a null `field` escapes the
  catch. Related, `:315-320`: an undefined `effectiveScope` makes
  `hasOwnProperty.call(effectiveScope, …)` throw, which is then swallowed into "visible".
- **MED — a non-array `options` value produces the same error message twice**
  (`:524-525` + `:664-665`): both the option branch and the generic list branch push
  `"… must be a list."` into the same path.
- **LOW — `allowed_values` is derived for every self-sourced field, not just option-like ones**
  (`:202-209`), so text, number and blocks fields all get `validation.allowed_values = []`.
- **LOW — an explicitly declared allow-list on a self-sourced option field is ignored**
  (`:20-30`): `resolveAllowedOptionValues` re-derives from `field.options` and never consults
  `validation.allowed_values`, even though `normalizeSchemaField:204` honours it.
- **LOW — legacy `dependencies` are not filtered for a missing `field`** (`:114-116` vs
  `:121-142`), unlike `conditions.rules`, so `{operator: '='}` becomes a live rule against
  field `''`.
- **LOW — `icon` `source` is passed through unvalidated** (`:159-163`) while option `source` is
  coerced to `self`/`datasource` — `'weird'` survives.
- **LOW — nested block errors are folded with `Object.assign`** (`:698-708`), overwriting
  same-key arrays instead of merging. Safe only because indexed paths happen to be unique today.
- **LOW — the datasource option branch does no value filtering**
  (`useFieldOptionChoices.ts:72-77`), so an entry with an empty `key` becomes a choice with
  `value: ''` — asymmetric with the self-sourced branch, which drops it.
- **LOW — `useContentJson` resolves `spaceId` once** (`:12` vs `:10`):
  `useSpaceQuery(toValue(spaceId))` means a reactive space id never re-queries (and `rv` goes
  stale) while `useTokens(spaceId)` keeps the ref. Also `:23,27`: the guard includes `!rv.value`
  so a space whose `updated_at` is the Unix epoch yields no URL, and `rv` is `ms / 1000`
  untruncated, leaking fractional seconds into the cache-buster.
- **LOW — `useFieldClipboard` accepts `field: []` as a schema field** (`:20-26`, the guard is
  `typeof parsed.field === 'object'`), and `:11-12` runs `useLocalStorage`/`useClipboard` at
  module import, registering a window listener outside any effect scope.
- **CONFIRMED the earlier normalizer divergence (#7) — since FIXED:** at audit time a test
  asserted the two switches agreed on all 19 shared types and diverged on exactly `icon`,
  `geo`, `price`, `plugin`, `serial`. The fix pass merged them into one implementation
  (`normalizeSchemaTypeName` in `tableField.ts`, re-exported by `useContentSchemaState.ts`),
  so there is no second switch left to drift — see Open #7.

*Test-infrastructure note:* `useContentVersions`' sub-hooks must be **called inside** the
`withSetup` callback, since they call `useQuery`/`useMutation` themselves. Worth adding to the
README.

## Auth, authorization, settings (203 tests)

The permission gate the whole UI relies on, plus persisted user/space state.

- **HIGH (security) — stale cross-space permission window.**
  `useAuthorization.ts:105`: `placeholderData: keepPreviousData` means that while space B's
  authorization context loads, `hasAbility` / `canAccessRoute` / `filterVisibleItems` are still
  answered from **space A's** ability set while `context.spaceId` already reports B. Verified:
  after switching to `space-2`, `hasAbility('content.manage')` still returns `true` from
  space-1's payload. For one round-trip, actions and nav for a space the user has fewer rights
  in render as permitted. Client-side gate only — the API still enforces. The comment at `:103`
  shows the behaviour is deliberate to avoid blanking the nav; the **space-scoped** case looks
  unintended.
  *Pinned by:* "keeps the previous space's abilities while the next space loads".
- **HIGH — logout clears the query cache but nothing in `localStorage`.**
  `useAuth.ts:315-341`: `user-settings` (locale, sidebar) and `global-team` (selected team id)
  survive, so on a shared browser the next account starts in the previous user's language with
  their team pre-selected. *Pinned twice.*
- **HIGH — changing two settings in the same tick silently discards one.**
  `useUserSettings.ts:72-84`: `persistSetting`'s optimistic `assignSettings(optimisticSettings)`
  rewrites *both* keys from the user's stored settings, reverting the sibling change before its
  own watcher runs (`value === oldValue` → early return at `:144,152`). Verified: setting
  `extendedSidebar=false` and `languageIso='de'` together leaves the language at `'en'` and
  sends only `{extendedSidebar: false}`. Any UI batching two setting writes loses one.
- **HIGH — `useSpaceSettings.reset()` cannot undo anything changed in the session.**
  `:44-46`: `useStorage` holds the very `defaults` object literal, so the first write mutates
  `defaults` through the reactive proxy, and `settings.value = defaults` re-assigns the
  already-dirty object. Verified: after `gridSize='lg'` then `reset()`, it stays `'lg'` — and is
  written back to storage. `reset()` only works for keys that came from stored JSON. Fix:
  `structuredClone(defaults)` or a factory.
- **HIGH — `mergeDefaults: true` is a shallow merge, which is a live migration hazard.**
  `useSpaceSettings.ts:35`: a stored `content` section written before a nested key existed keeps
  overriding the whole section. Verified that `content.showPreview` and `content.history` read
  back `undefined` for such a user. Every nested key added to `defaults` (`history`,
  `assets.visibleFields`, `assets.autoSave`, …) is `undefined` rather than defaulted for
  existing users.
- **HIGH — two i18n keys that do not exist.** `useAuth.ts:239` and `:266-267` reference
  `composables.auth.passwordResetLinkFailed` and `composables.auth.passwordResetFailed`, absent
  from both `en.json` and `de.json` (which define `forgotPasswordFailed` /
  `resetPasswordFailed`). A failed forgot/reset password with no server message shows the user
  the literal key string. Both branches of the 422 ternary at `:266-267` are also identical —
  dead code. *Pinned by assertion.*
- **MED (security) — a 401 on a guest/public route clears `user` but never clears the query
  cache** (`useAuth.ts:352-355`, no `logout()`), so the expired session's cached authenticated
  responses stay in memory for the life of the tab. *Pinned.*
- **MED (security) — `setUser(null)` is exported and performs none of logout's cleanup**
  (`useAuth.ts:79-81`): no `queryClient.clear()`, no posthog reset, no redirect.
  `useUserSettings` already calls `setUser` on every settings write, so any caller passing `null`
  produces a half-logged-out state. *Pinned.*
- **MED — the router guard and the UI resolve the selected team from two different cache
  entries.** `useAuthorization.ts:59-67` seeds
  `queryKeys.teams.list({include_space_context: true})`; `useGlobalTeam.ts:48` uses
  `{include_space_context: true, per_page: 1000}`. Different keys → two requests and two team
  lists, and the guard falls back to `teams[0]` of the *default-paginated* response, so with
  enough teams the guard's `canCreateSpace` can disagree with the visibly selected team.
- **MED — `useAbility` bypasses `canAccessRequirement`** (`useAuthorization.ts:117`), reading the
  merged set directly, so it ignores `check()` predicates and the selected-team context that
  `hasAbility` honours. Two ability APIs on one composable answer differently for e.g.
  `team.spaces.create`.
- **MED — `login()` / `register()` return `true` and navigate even when the follow-up
  `/users/me` fails** (`useAuth.ts:128-132,153`), leaving `isAuthenticated === false`; the router
  guard bounces straight back to `/login` with no error shown. *Pinned.*
- **MED — the "survive reloads" settings cache is never populated on the normal path.**
  `useUserSettings.ts:116-130`: the persistence watcher is registered *after* the initial
  `assignSettings(user.value?.settings)`, so when the user is already loaded at first call nothing
  is written until a setting changes. *Pinned both ways.*
- **MED — a settings change with no signed-in user is silently dropped**
  (`useUserSettings.ts:61-64`): the toggle moves and persists to `localStorage` but is never
  sent. *Pinned.*
- **MED — `useUserSettings.dispose()` stops only the user-sync watcher** (`:175-179`); the
  storage and two persist watchers are never stopped, so a re-init stacks a second set. The
  duplicate API call is swallowed only by accident.
- **LOW — the *disable* 2FA success toast uses the status label**
  (`useTwoFactor.ts:79`, `labels.twoFactor.disabled` = "2FA is not enabled") as a confirmation.
- **LOW — a non-401 `/users/me` failure does not latch `sessionExpired`**
  (`useAuth.ts:122`), so `initAuth` re-probes on every navigation. *Pinned (2 calls).*
- **LOW — for `register`, the 409 status check wins over any server message**
  (`useAuth.ts:169,291`), so a 409 meaning "invite already accepted" still reads "An account
  with this email already exists."
- **LOW — dead code in `useAuth`**: `handleUnauthorized` (`:343`) is typed
  `Promise<{retry?: boolean}>` but every path returns `{retry: false}`, so the retry contract —
  and `client.ts:183`'s branch on it — is dead; and `initAuth`'s `try/catch` (`:389`) is
  unreachable because `loadUser` swallows everything.
- **LOW — dead/unreachable state in `useGlobalTeam`**: `isLoadingSelectedTeam` and
  `selectedTeamError` are hardcoded `false`/`null` (`:121-122`), and `autoSelectFirstTeam`
  (`:138-142`) is unreachable because the `immediate` watcher at `:90` already auto-selects.
  `clearSelection()` (`:134`) leaves a user *with* teams and no selection until the team list
  changes, and `selectTeam('unknown-id')` (`:59`) is never validated. *Both pinned.*
- **LOW — `getStoredSelectedTeamId` re-implements `useGlobalTeam`'s storage contract**
  (`useAuthorization.ts:29-46`) with a second copy of the `'global-team'` key and shape.
- **LOW — `useUserSettings.ts:59` uses `useI18n() as any`**, and the locale is set through two
  paths (`setLocale` from the plugin plus `i18n.setLocale`), only one of which updates
  `document.documentElement.lang`.

**Production behaviour worth knowing:** vueuse's `useStorage` suspends its persist watcher for
one tick after each write (it re-enters `update()` from its own dispatched event), so a second
write in the same tick updates memory but never reaches `localStorage`. This affects
`useGlobalTeam` and `useSpaceSettings` in production, not only tests — low impact at
human-paced interaction, but it is real.

## Teams, invites, subscription, ops (255 tests)

- **HIGH — deleting or re-roling a team never invalidates the authorization context.**
  `useTeams.ts:162-166` (`useDeleteTeamMutation`) invalidates lists + hierarchy and removes
  `teams.detail(id)`, but **never** `queryKeys.authorization.all()` and never
  `queryKeys.teamPeople(id)` — so after deleting a team the cached permission context still
  grants team-scoped rights and the stale people list is still served. Same omission in
  `useUpdateTeamUserMutation` (`:193-195`, promoting/demoting — including yourself to/from
  owner — leaves owner-only UI wrong until reload), `useRemoveTeamUserMutation` (`:213-216`,
  removing *yourself* leaves your cached permissions intact) and `useCreateTeamMutation`
  (`:94-98`, a team you just created is absent from the cached permission context).
  `useCreateTeamSpaceRoleMutation` *does* invalidate `authorization.all()`, so the pattern
  exists — these paths just skip it.
  *Pinned by:* "leaves the team people list and the authorization context in place".
- **HIGH — `usePublicInviteQuery` keys on the invite id only, not the token.**
  `useInvites.ts:18`: the token is a queryFn input but not part of the key, so two different
  tokens for one invite id share a cache entry and a **wrong or expired token silently serves
  the previously fetched invite**. This is the unauthenticated public-invite path.
  *Pinned by:* "keys the public invite by id only, so a second token reuses the first result".
- **MED — `useSpaceUsage`'s query key lives outside `queryKeys` entirely.**
  `useSpaceUsage.ts:9` uses the ad-hoc literal `['space-usage', id]`. `grep 'space-usage'`
  matches only that line, so **nothing** can invalidate it, and it is not covered by any
  `['spaces', id, …]` invalidation. After checkout/cancel/content changes the usage panel
  serves data up to `staleTime: 60_000` old. Consumers: `settings/subscription.vue`,
  `settings/usage.vue`.
- **MED — checkout does not invalidate the plan, space or usage caches.**
  `useSubscription.ts:99-113`: `useCheckoutMutation` invalidates only the subscription
  namespace, not `queryKeys.plans.*`, not `spaces.detail(spaceId)` (which carries plan +
  limits), not usage. Immediately after an upgrade the plan/quota UI can still show the old
  plan's limits.
- **MED — `useReinitPaymentMutation` invalidates nothing and can toast an error on success.**
  `useSubscription.ts:136-154`: when the response carries no `checkout_url` it still takes the
  success path and fires `toast.error(...)`, so the mutation is `success` while the user sees
  an error — and the stale "payment pending" notice survives. *Pinned twice.*
- **MED — all six backup toasts are hardcoded English.**
  `useBackups.ts:67,70,86,89,102,105` use template strings like
  `` `Backup "${data.name}" created successfully` ``; `en.json` has no `composables.backups.*`
  keys at all. The only module in this set that skips i18n.
- **MED — delete does not remove the detail entry, so a deleted row can keep polling a 404.**
  `useBackups.ts:100-103` and `useMigrations.ts:87-90` invalidate `lists()` but never
  `removeQueries` the `detail(id)` entry (unlike `useDeleteTeamMutation`). Since
  `useBackupQuery`/`useMigrationQuery` poll while `state === 'pending'`, a
  deleted-while-pending record keeps polling.
- **MED — invite creation does not refresh the team/space detail**
  (`useInvites.ts:128-130`), only the people list, so any cached member/seat count next to the
  team goes stale.
- **MED — SAML save and SAML delete disagree.** `useTeams.ts:317-320` invalidates
  `authorization.all()` on upsert; `:338-340` does not on delete. Deleting a provider is at
  least as permission-relevant.
- **MED — `getTeamAncestors` cannot distinguish "is a root" from "not found".**
  `useTeams.ts:386-404`: both return `[]` and the recursion's own sentinel is
  `found.length > 0`, so a breadcrumb built from a stale or deleted id silently renders as a
  root-level team. *Pinned.*
- **LOW — no destructive-action guard anywhere in this set.**
  `useSubscription.ts:175-190`: `useCancelMutation` takes no argument and performs no
  confirmation; the entire guard for cancelling a paid subscription lives in whichever
  component calls it. Same for `useDiscardPendingMutation`. None of the last-owner /
  membership-ceiling / owner-only rules are enforced client-side in these composables — all
  server-side only, surfaced as a generic `toast.error('Failed to …')`.
- **LOW — untranslated fallback inside a translated toast.** `useInvites.ts:196`:
  `invite.space?.name || invite.team?.name || 'resource'`, so a German user reads
  "…resource". *Pinned.*
- **LOW — `useSpaceMembers.ts:51`** renders "Member role updated to " (trailing space, no role)
  when the payload omits `role`.
- **LOW — `composables.subscriptions.reinitSuccess`** ("Redirecting to payment…") in `en.json`
  is dead copy, referenced nowhere in `resources/js`.
- **LOW — `useAuditLogs.ts:25-28`** sets `staleTime: 0`, `gcTime: 0` *and*
  `placeholderData: keepPreviousData`; with `gcTime: 0` the previous page is evicted the moment
  it is unobserved, so `keepPreviousData` largely cannot work and page transitions still flash
  empty.
- **LOW — `useSubscription.ts:110-112`** invalidates `subscriptions(id).all()` and then
  `subscriptions(id).current()`, but `all()` is a strict prefix of `current()`, so the second
  call is dead weight — a hint the author was unsure the prefix matched.
- **LOW — `useSubscription.ts:116`** uses `const apiError = error as any` (the only `any` in
  this set; project convention forbids it) and reads `apiError.data?.use_reinit` untyped.
- **LOW — `getTeamDescendants` allocates a throwaway `computed` per evaluation**
  (`useTeams.ts:419` calls `findTeamInHierarchy`, which creates a `computed`, inside its own
  `computed` body) and computes an unused `hierarchyValue` at `:415`.
- **LOW — key-factory signature drift in `useQueryClient.ts`**: `migrations`, `backups`,
  `subscriptions`, `invoices`, `usageHistory` and `plans.forSpace` take a bare `spaceId: string`
  while every other factory takes `MaybeRef<string>`. Callers compensate with `spaceId.value`,
  so nothing is broken; passing a ref to one of these would embed the ref object in the key.
  (Verified vue-query's `cloneDeepUnref` unwraps refs at both registration and invalidation, so
  the `MaybeRef` factories are safe — pinned by a test.)

*Test-infrastructure note:* every factory these composables return calls
`useQuery`/`useMutation`, so the factory itself must be invoked **inside** the `withSetup`
callback — `useTeams().useCreateTeamMutation()` from a test body throws "vue-query hooks can
only be used inside setup()". Same note as the `useContentVersions` batch; belongs in the README.

## Presence, content menu, global clipboard (196 tests)

`usePresence` was at 0% coverage — everything else mocked it. Tested directly, it has the
densest cluster of real bugs in the sweep.

- **HIGH — the reconnect attempt cap does not survive a reconnect, so a dead server means an
  endless join/leave loop.** `usePresence.ts:146-154`: `handleReconnect` increments
  `reconnectAttempts`, but the timer it schedules calls `disconnect()`, which resets
  `reconnectAttempts.value = 0` (`:143`). So `maxReconnectAttempts` only caps a *burst* of
  errors — a channel that keeps failing reconnects forever at `reconnectDelay` intervals.
  Verified with `maxReconnectAttempts: 1`: four failures produce five joins.
  *Pinned by:* "does not enforce the cap across reconnects".
- **HIGH — overlapping reconnect timers cause reconnect storms.** `usePresence.ts:149`:
  `reconnectTimer = setTimeout(...)` never clears the timer it replaces and only remembers the
  newest, so a burst of N channel errors leaves N pending timers; the first to fire calls
  `disconnect()`, which cancels only the newest — the rest fire anyway. Verified: three errors →
  three joins. With the cap at 2, the *newest* timer is the one cancelled, so the cap silently
  eats the last attempt rather than the extras.
- **HIGH — `onWhisper`'s unsubscribe leaks the channel-level listener.**
  `usePresence.ts:174-179` only splices `whisperListeners` (which governs *replay* onto future
  channels); `stopListeningForWhisper` is never called, so the callback keeps firing on the
  channel it was attached to. Consumers that unsubscribe and resubscribe on the same channel —
  `useContentMenuPresence`'s `onBeforeUnmount`, or `useContentLiveCollaboration` remounting
  inside a live space channel — accumulate handlers and get **duplicate deliveries**. A route
  change within the same content id is the realistic trigger.
  *Pinned by:* "keeps delivering to an unsubscribed listener on the live channel".
- **HIGH — `buildBreadcrumbs` hangs the tab on a `pid` cycle.**
  `useContentMenu.ts:186-195`: `while (currentItem && currentItem.pid)` had no visited set and
  no depth bound, so `a.pid = b, b.pid = a` (or a self-parent) was an infinite loop that also
  grew `breadcrumbs` unboundedly — a frozen tab, not an exception. At audit time this was
  deliberately left untested (a pinning test would have hung the suite); the fix — a visited
  set, now at `:192-194` — **is** pinned by a terminating test. The backend should still
  prevent cycles, but the UI no longer goes down with them.
- **MED — the i18n branch of `.content:updated` is a no-op write that discards the broadcast.**
  `useContentMenu.ts:218-231`: `item = contentTree[i18nParentId]`, then
  `setQueryData(..., {...contentTree, [i18nParentId]: item})` writes the *unchanged existing*
  object back — the translated data is dropped entirely. Publishing or renaming a translation
  broadcasts but the tree shows nothing until a refetch. The only real effect is a new top-level
  identity that busts the `childrenIndexCache` WeakMap, forcing a full re-sort of every bucket —
  and because that identity change *does* re-render the tree, the bug has gone unnoticed.
  *Pinned by:* "re-writes the canonical parent unchanged for a translation update".
- **MED — `useGlobalClipboard` runs module-scope side effects at import with no teardown.**
  `:97-136`: `useIntervalFn` starts a 1 s poll and `onMounted` is registered outside any
  component instance (Vue logs "onMounted is called when there is no active component
  instance"), so the one-time permission priming never runs. Nothing can stop the interval — a
  permanent timer per page load, plus dead initialisation code.
- **MED — `clearClipboard` does not clear the system clipboard**
  (`useGlobalClipboard.ts:269-272`): it nulls only `localStorageClipboard` and the flag, while
  `getClipboardItem` prefers `clipboardText`. After clearing, `hasClipboardItem` is `false` but
  `pasteItem()` still returns the old item — UI and behaviour disagree. *Pinned.*
- **MED — paste buttons stay disabled outside Chromium.**
  `useGlobalClipboard.ts:66,96-136`: the poll and both watchers gate on
  `clipboardPermission.value === 'granted'`, never true in Firefox/Safari, so an item written by
  another tab (or already in localStorage at load) leaves `hasClipboardItem` `false` even though
  `getClipboardItem()` returns it. *Pinned.*
- **MED — `getChildren` hands out the internal cached array**
  (`useContentMenu.ts:155-162`), by reference (`getChildren(d,'p') === getChildren(d,'p')`). Any
  caller sorting or splicing it in place corrupts the cache for every other consumer of the same
  `menuData` identity.
- **MED — inconsistent root ordering** (`useContentMenu.ts:143-153` vs `:155-162`):
  `getRootItems` pushes `type === 'single'` items last, while `getChildren(data, null)` returns
  the same bucket in plain position order. Two components asking "what is at the root" get
  different answers. *Pinned.*
- **LOW — `useSpacePresencePeek`'s `error` ref can never be set** (`:14-41`):
  `fetchSpacePresence` catches everything and returns `null`, so the outer `try/catch` is dead
  code and `error` is permanently `null` while still being exported and consumed as a failure
  signal. A failing presence endpoint is invisible except as a `console.error`. *Pinned.*
- **LOW — the channel-error handler does not null `presenceChannel`**
  (`usePresence.ts:106-111`), so `whisper()` keeps writing into the dead channel object until
  the reconnect timer fires (`reconnectDelay` ms of silently dropped whispers).
- **LOW — asymmetric `_isCut` validation** (`useGlobalClipboard.ts:76-94`): the singular branch
  rejects a non-boolean `_isCut`, the plural branch never checks it, so truthy garbage on a
  multi-item clipboard is treated as a cut. *Pinned both ways.*

**Regression coverage requested and confirmed:** old-channel-leave on switch is pinned for both
`usePresence` and `useSpaceBroadcasts` (the ghost-presence bug in project memory), and
`useContentMenu`'s Echo ref-counting is pinned — two subscribers, one unmounts, channel stays
alive.

*Test-infrastructure finding:* `vi.mock('~/api')` is **unreliable** for modules that
`await import('~/api')` concurrently. In `useSpacePresencePeek.peekMultipleSpaces` the second
concurrent import resolved to the **real** `ApiClient` and hit real `fetch`, and every later
import in that file then got the real module. Anyone testing the other dynamic-import
composables should mock `fetch` instead, or a test will silently exercise the real network layer.

## CRUD resource composables + the query-key factory (334 tests)

- **HIGH — an unguarded `language_versions.forEach` turns a successful save into an error toast.**
  `useContent.ts:35`: `invalidateContentFamily` dereferences `content.language_versions` with no
  guard. If any write response omits it, `onSuccess` throws a `TypeError` **after** the server
  has already committed, TanStack routes into `onError`, and the user sees "Failed to update
  content: Cannot read properties of undefined…" for an entry that saved fine — while the
  detail and history keys are never invalidated. Affects **create, update, publish, schedule,
  unpublish and move** (all six call it). `useDuplicateContentMutation` does not, and is
  verifiably immune.
  *Pinned by:* "throws when the response omits language_versions".
- **HIGH — two different cache shapes share one key prefix, and the key does not match the
  request.** `useContent.ts:84` vs `:53`: `useContentChildrenQuery` caches the *unwrapped array*
  under `contents(space).list({parent: id})` while `useContentsQuery` caches the *full envelope*
  under `contents(space).list(params)` — both under `contents.lists()`. Anything reading or
  patching generically over that prefix hits `data.data` on one and `[0]` on the other. And the
  key says `parent` while the request sends `filter.parent_id`, so the identical row set fetched
  via `useContentsQuery({filter: {parent_id}})` occupies a **second independent cache entry**
  that no invalidation keeps in sync. (Compounds the `[object Object]` bug in the API-layer
  section — that filter never reaches the server at all.)
- **HIGH — `getBreadcrumbs` and `isDescendantOf` loop forever on a folder parent cycle.**
  `useAssetFolders.ts:116-151`: neither kept a visited set, so a self-parent or an a→b→a pair
  hung the tab, and `getBreadcrumbs` grew its array unboundedly. Same class as the
  `useContentMenu` cycle. At audit time this was deliberately left untested (the test would
  never have terminated); both walks now carry visited sets (`:132-161`) and **are** pinned by
  terminating tests ("terminates on a parent cycle instead of building an endless trail",
  "terminates isDescendantOf on a parent cycle"). This is the same `isDescendantOf` that backs
  the folder-move cycle guard — finding #6, also fixed.
- **MED — `queryKeys.spaces.all()` is `['spaces']`, a prefix of every space-scoped key.**
  `useQueryClient.ts:5` + `useInvites.ts:198`: accepting an invite invalidates it, which
  invalidates the **entire per-space cache of every space** in the client at once — contents,
  assets, blocks, audit logs. Almost certainly not intended as a global nuke.
- **MED — the `ai` namespace lives outside the space tree.** `useQueryClient.ts:285`: keys are
  `['ai-config', spaceId]`, `['ai-models', spaceId]`, … so **no** `['spaces', id, …]`
  invalidation can reach them and there is no single prefix that clears all four. `publicShare`
  has the same shape deliberately; the `ai` split looks accidental.
- **MED — collection assets are cached in the wrong namespace.** `useAssets.ts:23-36`: when
  `params.collection` is set the data comes from `assetCollections.getAssets()` but is stored
  under `queryKeys.assets(space).list({collection, …})`. So
  `queryKeys.assetCollections(space).assets(id)` — the key a collection mutation would naturally
  invalidate — never matches the cached grid, while any asset-list invalidation refetches every
  open collection view.
- **MED — the upload debounce is created *inside* `uploadAsset`.** `useAssets.ts:87`: each call
  builds its own `useDebounceFn`, so a multi-file drop never coalesces — N files produce N
  invalidations and N "Assets uploaded successfully" toasts. It also means
  `await uploadAsset(...)` resolves *before* the list is invalidated, so a caller that
  immediately reads the cache sees the stale page.
- **MED — `uploadAsset` swallows every failure** (`useAssets.ts:178-182`): 422, 500, unparsable
  body, network and abort all resolve to `null` with a message parked on `error.value`, and no
  error toast is raised — unlike every mutation in the file. A caller that only checks the
  outcome shows nothing at all on failure.
- **MED — a null/empty response is treated as silent success**
  (`useAssets.ts:248,268`): `useReplaceAssetFileMutation` and `useUploadAssetPosterMutation`
  guard on `if (data)`, so when the API resolves with nothing the mutation succeeds with no
  invalidation and no toast — the UI reports neither success nor failure.
- **MED — `getReleaseState` disagrees with itself on an empty publish date.**
  `useReleases.ts:258-261`: `new Date(null)` is the epoch, so a committed release with
  `publish_at: null` reads as **pending** (ready to publish now); `new Date(undefined)` is an
  Invalid Date whose comparison is always false, so the same release with `publish_at: undefined`
  reads as **scheduled forever**. Two empty values, two opposite states. *Both pinned.*
- **MED — publishing a release does not invalidate content.** `useReleases.ts:141-147`
  publishes every content version in the release server-side but invalidates only
  `releases.lists()` and `releases.detail(id)`; `contents.lists()` and the content menu stay
  stale.
- **MED — tree operations and translation import never invalidate entry details.**
  `useContent.ts:371,414` invalidate only `contents.lists()` + `contentMenu`, yet a tree batch
  reparents entries and an import rewrites their content — an open editor keeps serving the
  pre-change `contents(space).detail(id)`. `useDeleteContentMutation` (`:246`) likewise never
  touches `contentVersions(space, id)`, so a deleted entry's version history lingers.
- **MED — deleting a block leaves its versions and templates cached.**
  `useBlocks.ts:111-114`: `blockVersions(space, id)` and `blockTemplates(space, id)` sit under
  `blocks.all()` but *outside* `blocks.detail(id)`, so the single `removeQueries` misses both.
  `useUpdateBlockMutation` (`:88`) invalidates versions but never templates, even though a schema
  change can invalidate a template.
- **MED — a folder move never invalidates the asset lists** (`useAssetFolders.ts:63-66`), though
  reparenting a folder changes which folder its assets are browsed under.
- **LOW — `personalAccessTokens.all()` is nested under `users.me()`**
  (`useQueryClient.ts:277` + `useUser.ts:35,73`), so any `invalidateQueries({queryKey:
  queryKeys.users.me()})` — every profile update and the post-2FA refresh — also invalidates the
  token list and social links.
- **LOW — inconsistent root detection** (`useAssetFolders.ts:106` vs `:111`): `rootFolders` uses
  `!folder.parent_id` while `getChildrenOfFolder(null)` compares `=== null`, so a folder whose
  payload omits `parent_id` appears as a root but is not returned as a child of the root.
  *Pinned.*
- **LOW — `useAssetFolderQuery` has no `enabled` guard** (`:27-35`), unlike `useAssetQuery`, so a
  component rendering before its id resolves issues `GET …/asset-folders/` with an empty id.
- **LOW — `useReleases.ts:199`**: `(data as any).versions?.length || 1` cannot express zero, so
  assigning zero versions announces "1 version(s) added". Also the only `as any` in the file —
  `Release` has no `versions` field, so the count is unchecked.
- **LOW — `getBlockBySlug`/`getBlockById` use `==`** (`useBlocks.ts:135,146`), so `0 == ''`
  matches, and they return two different "not found" values (`null` when the list is missing,
  `undefined` from `Array.find`) — a caller checking `=== null` handles only one.
- **LOW — deleting a comment thread parent evicts only that id**
  (`useComments.ts:113`); replies cascade server-side but their `detail(replyId)` entries stay
  cached. Low impact, the list is invalidated.
- **LOW — redundant `removeQueries`** (`useAssets.ts:218`): `linkedContents(id)` is already
  inside the `detail(id)` subtree removed on the previous line — suggests the author believed the
  keys were siblings.
- **NOTED — there is no optimistic update or rollback anywhere in these seven modules.**
  `useComments.ts:181-228` reactions only invalidate, so the emoji does not appear until the
  refetch returns. Deliberate-looking, but it means "optimistic update and rollback" had nothing
  to cover.

## Asset distribution — collections, packages, shares, tags, versions, upload, plugins (290 tests)

- **HIGH — the public share page sent session cookies and primed the CSRF cookie — FIXED
  (landed while this status update was being written).** `PublicShare.unlock()` is a POST, so
  `request` used to call `ensureCsrfCookie()` (`fetch('/auth/v1/csrf-cookie')`) and attach
  `X-XSRF-TOKEN`; every share GET went out with `credentials: 'include'`. An anonymous visitor
  on `/share/:space/:token` therefore hit an auth endpoint, and a **logged-in** visitor's
  session cookie rode along on someone else's share request. Same-origin, so not exfiltration —
  but the share endpoints must not derive authorization from the session. Fixed: the share
  resource now passes `credentials: 'omit'` and `skipCsrf: true` on every request
  (`public-share.ts:35-36`, honoured by `client.ts`), so share traffic is genuinely anonymous.
  *Pinned by:* `tests/js/composables/usePublicShare.test.ts` → "sends every share request
  without credentials, keeping the session cookie at home".
  *Still mitigating on top:* `useAuth.handleUnauthorized` short-circuits on `meta.public`, so a
  401 does not bounce the visitor to login.
- **HIGH — `useFileUpload` rejects with a bare string, so every upload error reads "Unknown
  error".** `useFileUpload.ts:39,43,48,53`: `reject(error.value)` hands callers a string, so
  `catch (e) { e.message }` is `undefined` — and every toast helper in this codebase reads
  `error.message || 'Unknown error'`. It also discards the server's validation body entirely
  (`:41-44` uses only `xhr.statusText`), so "file too large / max 2MB" never reaches the user.
  *Pinned.*
- **MED — `useFileUpload` state is shared across concurrent uploads and cannot be aborted.**
  `:12-13`: `isUploading` and `error` are single refs, so in a multi-file batch the first
  completion sets `isUploading = false` while the rest are in flight, and each `upload()` clears
  the previous file's `error`. The `XMLHttpRequest` is never returned and the composable exposes
  only `{isUploading, error, upload}`, so the `abort` listener at `:51` can never fire from the
  caller. Any multi-file UI built on this cannot show per-file state or offer cancel.
  *Pinned (3 tests).*
- **MED — the 403 access-token fallback fires a third request.**
  `usePublicShare.ts:63-71`: on a stale token the queryFn calls `clearAccess()` and re-requests
  with `null`, but the resolved data lands under the *stale-token* key while `clearAccess()`
  mutates `accessToken`, which is part of the key (`:58`) — so the query rekeys and fetches a
  third time. Three round trips and a wasted cache entry per stale-token visit, and these
  endpoints are **metered**. *Pinned.*
- **MED — `downloadAsset` does not invalidate the share** (`usePublicShare.ts:150-152`).
  Individual downloads are metered but, unlike `downloadAll` (`:134`), nothing refreshes
  `queryKeys.publicShare(...).all()`, so a remaining-downloads figure on the page stays stale
  until reload. *Pinned.*
- **MED — `useUpdateAssetTagMutation` does not invalidate the asset lists**
  (`useAssetTags.ts:64-70`) although delete does (`:90`). Renaming or recolouring a tag leaves
  every cached asset list rendering the old label and colour. *Pinned.*
- **MED — `useAssetVersionsQuery` silently overrides a caller `sort`**
  (`useAssetVersions.ts:42-46`): `{...toValue(params), sort: '-version_number'}` spreads the
  default **last**, so `useAssetVersionsQuery({sort: '+created_at'})` is ignored with no warning.
  That is the opposite order to every other composable in this batch (collections, tags, icons,
  field plugins all let the caller win). *Pinned.*
- **MED — `waitForPackageAndDownload` treats any unknown state as in-progress**
  (`useAssetPackages.ts:106-122`): only `completed` and `failed` exit the loop, so a state added
  server-side later (e.g. `expired`) polls **240 times over ten minutes** before throwing
  "Package build timed out". It does terminate, so this is UX and load, not a runaway. *Pinned.*
- **MED — `downloadSelectionAsPackage` swallows every failure**
  (`useAssetPackages.ts:153-160`): the `catch` toasts and returns `undefined`, and the promise
  resolves, so callers cannot distinguish a successful download from a failed one. *Pinned.*
- **MED — `useReorderCollectionAssetsMutation` leaves the collection list and detail stale**
  (`useAssetCollections.ts:218-223`): unlike `invalidateCollectionAssets` (`:16-27`) it skips
  `lists()` and `detail(id)`, so a cover asset or ordering hint on either stays stale after a
  reorder. Also the only mutation in the file with no success toast. *Pinned.*
- **LOW — `useAssetVersions`' restore mutation has no id guard** (`:52-56`): the query is gated
  on `hasAssetId`, the mutation is not, so with a null `assetId` it POSTs to
  `/assets//versions/{id}/restore`. *Pinned.*
- **LOW — three queries have no `enabled` guard on the space id**: `useAssetTagsQuery`,
  `useIconsQuery` and `useAssetCollectionsQuery` fire at `/mgmt/v1/spaces//…` when `spaceId` is
  `''`, while `useAssetPackages`, `useAssetShares` and `useFieldPlugins` all guard on
  `Boolean(toValue(spaceId))`. *Pinned in each file.*
- **LOW — version keys are not nested under the asset detail key**
  (`useQueryClient.ts:139-142`): `assetVersions(s,a).all()` is `['spaces',s,'assets',a,'versions']`
  while `assets(s).detail(a)` is `['spaces',s,'assets','detail',a]`, so invalidating an asset
  detail does not touch its version list. Both are invalidated explicitly at
  `useAssetVersions.ts:21-31`, so nothing is broken — but the naming implies nesting that does
  not exist.
- **LOW — `clearAccess` is called above its `const` declaration**
  (`usePublicShare.ts:67` calls it, declared at `:117`). Safe only because the queryFn runs after
  setup; any future synchronous call from the composable body is a TDZ `ReferenceError`.

### Two gaps this batch could not close — both need a component test

- **The field-plugin sandbox URL and token handshake are not in `useFieldPlugins` at all.**
  That composable is pure CRUD: it neither builds, signs nor validates `sandbox_url`, and there
  is no handshake code in it. The signing, the `postMessage` handshake and the "never re-parent
  the iframe" rule live in **`components/editor/PluginBlock.vue`, which is untested.** The
  immutable `handle` is enforced only by the type
  (`UpdateFieldPluginPayload = Partial<Omit<CreateFieldPluginPayload, 'handle'>>`) — there is no
  runtime strip, so a cast reaches the transport and the server must be the gatekeeper.
- **Asset-collection smart rules have no frontend evaluation or serialization.**
  `AssetCollectionRules` is types-only (`types/assets.d.ts:180-190`) and the composable passes
  `rules` through by reference. Rule *building* lives in
  **`components/assets/CreateAssetCollectionDialog.vue`, which is untested** — that is where an
  exhaustive rule-DSL test belongs.

## Wizard tree, alert dialog, canvas commands, blueprints, onboarding (266 tests)

- **HIGH — deleting an unsaved node silently erases saved descendants with no delete
  operation.** `useContentWizardTree.ts:785-801`: `toggleDelete` on a node without `backendId`
  calls `removeSubtree`, which `delete`s every descendant record outright — persisted ones
  included. Move an existing entry under a new draft parent, then remove the parent: the entry
  vanishes from the draft and `operationPlan` is **empty**, so the backend keeps it exactly where
  it was. Silent data divergence between what the user sees and what is stored.
  `canvas.vue:1201` reaches this path.
- **HIGH — `duplicateNode` throws instead of returning a `ValidationResult`, and
  `canvas.vue` does not catch.** `:588-636`: `cloneSubtree` calls `addNode` per descendant and
  `addNode` throws; only the *root* clone's placement is validated, so any descendant the target
  rejects (a nestable block, a whitelist violation) escapes as an exception and leaves a
  half-built copy. `canvas.vue:1688` calls it inside a `result.valid` check with no `try`, so the
  drag-copy handler crashes and skips its `restoreSnapshot`.
- **HIGH — `confirm()` may never settle.** `useAlertDialog.ts:212-239`: the boolean promise is
  resolved only inside the two click handlers. A dismissal (Escape, overlay click) resolves the
  *inner* `dialog()` promise with `'closed'`, which `confirm` discards. Any
  `if (await alert.confirm(…))` the user escapes out of **hangs forever**, leaking whatever the
  caller was awaiting.
- **MED — no dialog queue; an overlapping dialog abandons the first promise.**
  `useAlertDialog.ts:90-98`: `openDialog` overwrites `state.component`, tearing down the first
  dialog's markup while its resolvers stay captured in an unreachable closure. Two
  near-simultaneous confirms leave one permanently pending.
- **MED — the shared `defaultChoices` object is mutated in place, leaking answers between
  spaces.** `useOnboarding.ts:23-44`: it is handed to `useStorage` by reference, and `useStorage`
  returns *that object* whenever the key holds nothing, so editing the choices mutates module
  state for the tab's lifetime — the next space with no stored onboarding **starts from the
  previous space's answers and immediately persists them.** Directly contradicts the comment
  above it ("which stack you picked is a per-developer answer, not a property of the space").
  Fix: `() => ({ ...defaultChoices })`.
- **MED — `changes.moved` and the move plan disagree.** `useContentWizardTree.ts:393` vs
  `:1030-1044`: `changes.moved` compares only `parentId` while the plan's move filter also
  compares `position`. A pure sibling reorder therefore produces pending move operations while
  `hasUnsavedChanges` stays `false`, so the apply/leave guard cannot see them.
- **MED — no duplicate-node-id guard.** `:553-555`: `addNode` with an existing `nodeId` replaces
  the record but appends the id to the parent's `childrenIds` a **second** time, so the node is
  visited and laid out twice. Reachable from the collaboration path (`canvas.vue:275` applies
  remote adds with a peer-supplied `nodeId`) — a replayed or duplicated whisper corrupts the tree.
- **MED — a menu item whose `pid` is absent from the payload becomes an unreachable ghost.**
  `:415-481`: the node is hydrated but no parent lists it, so `recomputeNodeState`'s walk never
  visits it — no layout, no `deletedReason`, `isVisible` frozen at `true` — while it still feeds
  `validations` and `operationPlan`. A permission-filtered or partially loaded menu produces
  invisible nodes that block apply.
- **MED — an existing entry on a `nestable` block is permanently invalid.** `:189-193`:
  `nestable` is a legal `FlatContentMenuItem.type`, but the wizard rejects it unconditionally and
  `updateBlock`/`moveNode` also refuse it, so the placement error can never be cleared from the
  canvas.
- **MED — a duplicated deleted node can never be applied but blocks the dirty flag.** `:613`:
  the copy inherits `isDeletedSelf`, and `operationPlan` excludes it from creates
  (`!node.deletedReason`) *and* from deletes (`!!node.backendId`) — yet it renders in the tree and
  makes `hasUnsavedChanges` true forever.
- **MED — `executeCommand` does not guard `command.execute()`.**
  `useContentCanvasCommands.ts:51-69`: a throwing command escapes before the after-snapshot, so
  its partial mutation stays with no history entry to undo it. Callers must restore a snapshot
  themselves, and `canvas.vue`'s `add-node` command (`:1381`) does not.
- **LOW — `dialog()` never resolves with the clicked action's type.**
  `useAlertDialog.ts:111-135`: reka-ui's `AlertDialogAction`/`AlertDialogCancel` close the dialog
  themselves, firing `onUpdate:open` → `resolve('closed')` before the action's own handler; first
  resolve wins. So `dialog()` always yields `'closed'` and `message({cancelButton: true})` cannot
  distinguish OK from Cancel. No production caller reads the value today.
- **LOW — `autoClose: false` cannot keep a dialog open** (`useAlertDialog.ts:118`): it only skips
  the composable's own `closeDialog`; reka-ui closes regardless. The option is inert.
- **LOW — `defaultLabels` and both label setters are dead**
  (`useAlertDialog.ts:51-59,68-81,248-250`): `getLabel` prefers the i18n lookup and
  `alertDialog.ok|cancel|confirm` always resolve, so `setAlertDialogDefaultLabels` and the
  returned `setLabels` can never change a rendered label. The `useI18n()` try/catch (`:60-66`) is
  likewise unreachable — the standalone composer cannot throw — and `DialogState.resolve`/`.reject`
  (`:39-44,82-87`) are never assigned.
- **LOW — `orderedNodes` sorts by `(depth, position)` globally**
  (`useContentWizardTree.ts:966-974`), so same-depth children of *different* parents interleave by
  sibling index instead of staying grouped. `exportForAi` inherits it, so the AI sees a scrambled
  reading order (recoverable — parent ids are explicit).
- **LOW — `getNode` maps every falsy id to the virtual root** (`:119-125`), `''` included, so
  `markAiAltered` (`:744`) with a blank id from an AI response silently flags the root.
- **LOW — the root is addressable two ways and they disagree** (`:231-236`):
  `canPlaceBlockUnderParent(single, '__root__')` fails with "Single blocks can only live at the
  root." while `(single, null)` succeeds. That message is unreachable by any other path, so it is
  dead code guarding an inconsistency.
- **LOW — hidden nodes keep a stale `layout`** (`:147-157` +
  `useContentWizardLayout.ts:31`): `layoutTree` skips invisible nodes and `applyLayout` only
  writes positions it received, so a collapsed subtree retains its last coordinates. Harmless
  today, not a safe assumption.
- **LOW — a descendant whose block record is missing is dropped from a duplicate silently**
  (`:598-600`): no warning, no invalid result.
- **LOW — `isRootLevelItem` declares `Pick<…, 'pid' | 'type'>` and never reads `type`** (`:127`).
- **LOW — `useSpaceBlueprints.ts:60-62,86-94`** queryFn "… is required" throws are unreachable
  (the `enabled` gate already covers every missing id; they only narrow types), and `:124-130`'s
  second `invalidateQueries` is redundant — `['space-blueprints']` is a prefix of the team-list key.
- **LOW — `useOnboarding.ts:54`** keys the detail invalidation off the *response* id rather than
  the composable's `spaceId`; a mismatched response invalidates the wrong entry and leaves the
  real one stale.
- **LOW — `useContentCanvasCommands.ts:32-33`**:
  `options.serializeSnapshot?.(…) || JSON.stringify(…)` means a serializer that legitimately
  returns `''` falls through to full JSON on one side of the comparison, so two snapshots the
  serializer calls equal can still be recorded as a change.

## Data sources, automations, blocks family, notifications (346 tests)
## UI primitives — form fields, tags-input, input-otp, pagination, stepper, variants (215 tests)

Both of these batches were written by agents that hit the session limit **before running their
verify step**, so their test files landed but their oddity reports were lost. The tests
themselves are complete and green (and their typecheck/lint failures were fixed during
integration: `VueWrapper<InstanceType<typeof Host>>` on a plain options object, `unknown`
emitted payloads fed back through `setProps`, payload casts needing `as unknown as`, a
`read` filter that is actually `unread_only` in `NotificationQueryParams`, and a useless
empty-object spread fallback).

**These two areas therefore have coverage but no findings write-up.** Re-run those two slices
to recover the analysis — the tests are already in place, so it is a read-only review pass over:

- `useDataSources`, `useDataEntries` (the ShapeValue encode/decode + legacy raw-string
  fallback), `useRedirects`, `useAutomations`, `useAutomationActions`,
  `useAutomationExecutions`, `useBlockFolders`, `useBlockTemplates`, `useBlockVersions`,
  `useBlockTags`, `useNotifications`, `useUserNotifications`, `useUrlNotifications`
- `components/ui/`: `form/InputField`, `tags-input`, `input-otp`, `pagination`, `stepper`,
  `checkbox`, `switch`, `badge`, `alert`, `input`, `textarea`, `progress`, `skeleton`,
  `spinner`, `separator`, `avatar`

---

# Known coverage gaps

Untested surfaces that carry real logic, in rough priority order:

1. **`components/editor/PluginBlock.vue`** — the field-plugin sandbox URL signing, the
   `postMessage` token handshake, and the "never re-parent the iframe" invariant all live here,
   not in `useFieldPlugins` (which is pure CRUD). Security-relevant and untested.
2. **`components/assets/CreateAssetCollectionDialog.vue`** — the smart-rule DSL is built here;
   `useAssetCollections` passes `rules` through by reference and `AssetCollectionRules` is
   types-only.
3. **`components/assets/AssetGrid.vue` (2030 lines) and `AssetListView.vue` (1132)** — deliberately
   not mounted: each wires ten-plus query composables, auth, drag-drop and eight dialogs, so a
   mount test would be mostly stub scaffolding. Their delegated logic *is* covered
   (`useAssetSelection`, `useAssetRequirements`, `useAssetLibraryMoves`, `assetDragAndDrop`,
   `downloadAssets`). Closing this properly needs a shared app harness (router + auth + seeded
   caches).
4. **`components/ContentTree.vue` (2469 lines)** and the other large page-level components —
   same reasoning.
5. **`resources/js/pages/**`** — no page-level tests at all.
6. **`composables/useProvider.ts`** — the only remaining composable above 25 statements at 0%
   coverage (root-only provider dashboard/notes; missed by the fan-out partitioning).

# Test-infrastructure follow-ups

Independent of product code, worth doing to the harness:

- **No usable `localStorage`.** Node installs its own (undefined without `--localstorage-file`)
  and, since jsdom's `window === globalThis`, it shadows jsdom's implementation. Every
  `useStorage`-backed composable silently degrades to a detached ref, and five test files each
  install their own in-memory `Storage`. Belongs in `tests/js/setup.ts`.
- **reka-ui portalled listboxes need pointer-capture stubs.** `hasPointerCapture`,
  `setPointerCapture`, `releasePointerCapture` and `scrollIntoView` on `Element.prototype`;
  with those, portalled select content *is* assertable. Currently stubbed per-file in four files.
- **`vi.mock('~/api')` is unreliable for concurrent `await import('~/api')`.** In
  `useSpacePresencePeek.peekMultipleSpaces` the second concurrent import resolved to the **real**
  `ApiClient` and hit real `fetch`, and every later import in that file then got the real module.
  Mock `fetch` instead for dynamic-import composables, or a test will silently exercise the
  network layer.
- **Vue Query factories must be built inside the `withSetup` callback.** Every
  `useXxxQuery`/`useXxxMutation` factory calls a vue-query hook, so capturing the factory and
  calling it from a test body throws "hooks can only be used inside setup()". Three separate
  agents hit this; it is now documented in the README.

## `useUser`, `useTwoFactorAuth`, `useAiModels` (108 tests)

The last three untested composables. `useTwoFactorAuth` turned out to be dead code that would
not work if it were wired up.

- **HIGH — `useTwoFactorAuth`'s step-up interception can never fire.** `:32` reads
  `error?.response?.data?.error_code`, but `api/client.ts:102-104` sets
  `error.response = response` (the raw `Response`) and puts the parsed body on `error.data`. A
  `Response` has no `.data`, so `errorCode` is **always** `undefined` and both 423 branches
  (`:36-54`) are unreachable — `makeRequestWith2FA` just rethrows. The correct path is
  `error.data.error_code`. (`error.response.status` at `:33` *does* work, since `Response.status`
  exists, so the outer check passes and only the inner ones fail.)
  *Depends on it:* nothing — `grep` finds `useTwoFactorAuth` only in `auto-imports.d.ts`. No
  component, page or composable calls it.
  *Pinned by:* "never intercepts a real 423 from the API client".
- **HIGH — a wrong 2FA code permanently loses the caller** (`useTwoFactorAuth.ts:82-85,109-112`).
  The failure path calls `reject(error)` but leaves `pendingRequest` in the slot, so the caller's
  promise is already settled; a successful retry then calls `resolve(response)` on a rejected
  promise — a no-op. The dialog closes, the retry's HTTP call succeeds, and the original caller's
  `await` stays rejected forever. *Pinned.*
- **MED — neither verifier checks *which* requirement is pending**
  (`useTwoFactorAuth.ts:61,88`). `verifyWithPassword` happily answers a TOTP challenge: it sends
  `X-Password-Confirmation`, resolves the caller and clears `requiresPassword` /
  `passwordDialogOpen` — leaving `requiresVerification` / `verifyDialogOpen` set, so the verify
  dialog is stuck open over a completed request. Symmetrical for `verifyWithTOTP`. *Pinned.*
- **MED — a single `pendingRequest` slot** (`useTwoFactorAuth.ts:19-23`): a second concurrent
  challenge overwrites the first, and the first caller's promise never settles at all — a hung
  `await` and a leak. *Pinned.*
- **MED — the avatar "refresh" re-seats stale data** (`useUser.ts:72-77`).
  `await invalidateQueries` only refetches *active* observers, so on any screen that does not
  mount `useUserQuery` the following `getQueryData` returns the **pre-upload** entry and
  `setUser` re-applies the old avatar; with nothing cached, `setUser` is skipped entirely and the
  header avatar never updates. Meanwhile the mutation's own response already carries `{avatar}`
  and is discarded. *Pinned in three tests.*
- **MED — `useAiModels` delete never evicts the detail entry** (`:195-210`): only
  `['ai-configs', id]` is invalidated, while `['ai-config', id, configId]` is neither removed nor
  invalidated, so a detail route revisited within `gcTime` renders a config that no longer
  exists. `removeQueries` is never called anywhere in the file. *Pinned.*
- **MED — `useAiModels` invalidates the id the *server* returned, not the one requested**
  (`:189`): the key is built from `data.id` rather than the `configId` that was patched, so if
  they ever disagree the entry the detail view is reading stays stale. *Pinned.*
- **MED — `toggleFavourite` / `setModel` are hand-rolled, not mutations**
  (`useAiModels.ts:73-100`): no pending state, no error surface, no toast — callers get a raw
  rejection. Both PATCH the same endpoint, and `toggleFavourite` computes the new `favourites`
  array from a possibly stale `getQueryData` snapshot (never a re-read), so a rapid
  favourite-toggle plus model-change can race and clobber. `setModel` also invalidates only
  `['ai-settings', id]` while `toggleFavourite` correctly invalidates `['ai-models', id]` too —
  needed because `is_favourite` is denormalised onto each model.
- **MED — reactive state is mutated inside a `queryFn`** (`useAiModels.ts:248-258`):
  `forceRefresh.value = false` is a side effect in the fetcher, and retries are enabled in the
  real app's query client, so a retried fetch silently consumes the flag and the refresh button's
  bypass is lost. *Pinned as one-shot behaviour.*
- **LOW — none of `useAiModels`' keys go through `queryKeys`**: `['ai-models', id]`,
  `['ai-settings', id]`, `['ai-configs', id]`, `['ai-config', id, cId]`, `['ai-usage', id]` are
  ad-hoc arrays, so nothing outside the file can safely invalidate them by prefix. (Same root
  cause as the `ai`-namespace finding in the CRUD section.)
- **LOW — `setQueryData` then `invalidateQueries` on the same key** (`useUser.ts:33-35`): the
  PATCH response is written into the cache and immediately marked stale, so a mounted
  `useUserQuery` fires a redundant `GET /users/me` right after the PATCH already returned the
  updated user.
- **LOW — dead code**: `useTwoFactorAuth.ts:38,48` assign a `{resolve: () => {}, reject: () => {}}`
  placeholder that is overwritten two lines later (four never-invoked noops, and the whole
  residual function-coverage gap); `useAiModels.ts:37,62,118,135,255` re-guard `if (!id)` inside
  every `queryFn` although `enabled` already blocks the fetch (reachable only via an explicit
  `refetch()`, which ignores `enabled`); `useAiModels.ts:159-163,186-190,203-207` have
  unreachable `if (id)` in `onSuccess`, since the `mutationFn` already throws `'No space ID'`.
- **CONFIRMS finding in the CRUD section — `users.me()` prefix-matches the token list.**
  `useUser.ts:35`: `queryKeys.users.me()` is `['users','me']`,
  `personalAccessTokens.all()` is `['users','me','tokens']` and `users.socialLinks()` is
  `['users','me','social-links']`, so **renaming your profile refetches your personal access
  tokens and your social links.** Not a correctness bug, but `personalAccessTokens` is not
  independently namespaced despite reading like a top-level key group. *Pinned.*
- **NOTED — `useTwoFactorAuth` and `useTwoFactor` do not overlap.** Despite the names they solve
  unrelated problems: `useTwoFactor` is enrolment CRUD over `api.twoFactor.*` (status / setup /
  confirm / verify / disable / regenerateBackupCodes); `useTwoFactorAuth` is a request
  interceptor that replays a 423'd request with a step-up header. Nothing to reconcile. Header
  casing differs between them (`X-TOTP-Code` vs `x-totp-code` in `useAuth`) — harmless, HTTP
  headers are case-insensitive, but inconsistent.
