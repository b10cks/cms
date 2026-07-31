# Frontend tests

Vitest + Vue Test Utils, jsdom. `bun run test`, `bun run test:watch`, `bun run test:coverage`.

Config lives in `vitest.config.ts` (root). Tests live here, mirroring `resources/js/`:
`tests/js/lib/`, `tests/js/utils/`, `tests/js/composables/`, `tests/js/components/`.
One test file per source module, named `<module>.test.ts`.

## Conventions

- **Always import** `describe`/`it`/`expect`/`vi` from `vitest`. Globals are off so the
  checked-in tsconfig needs no extra ambient types.
- **Group with `describe` per exported function or behaviour area**, not one giant block.
- **Test names state the behaviour**, not the mechanics: `'drops a child whose parent is
  also selected'`, not `'test normalizeRootSelection 2'`.
- **Assert on behaviour, not implementation.** Prefer the public return value / rendered
  output / emitted event over spying on internals.
- **Cover the edge cases the module actually guards**: null/undefined input, empty
  collections, malformed entries, falsy-but-valid values (`0`, `''`, `false`).
- **Comment only what the assertion cannot say.** A comment earns its place by
  explaining *why* a value is expected, or naming a non-obvious constraint. Do not
  narrate what the code plainly does.
- Match the repo's style: 2-space indent, no semicolons, single quotes, trailing commas.

## Environment

`tests/js/setup.ts` runs before every file and provides:

- `window.__APP_CONFIG__` — pinned. Several modules read runtime config *at import time*
  (`~/lib/access-control` builds its nav arrays from the billing flag), so this must be
  set before anything imports. `docsUrl` is `https://docs.b10cks.test`, ilum base is
  `/ilum`, all feature flags on except `realtime`.
- `ResizeObserver` — stubbed; jsdom has none and reka-ui primitives construct one.
- **Pointer capture + `scrollIntoView`** on `Element.prototype` — stubbed. Without them a
  reka-ui `Select`/`Combobox` listbox never opens, so its portalled content cannot be
  asserted. With them it can: query `document`, and drive selection with real
  `pointerdown`/`pointerup`.
- **`localStorage`** — an in-memory `Storage`, cleared before every test. Node installs its
  own `localStorage` global that is `undefined` without `--localstorage-file`, and because
  jsdom's `window === globalThis` that shadows jsdom's real implementation. Without the
  stub every `useStorage`-backed composable silently degrades to a detached ref and its
  tests pass while exercising nothing.
- **The real i18n instance**, installed globally. `~/plugins/i18n` exports a standalone
  Composer that works without an app, so composables already translate for real.
  **Assert on real English copy** (`'Choose a file'`), not on translation keys — that way
  a missing key fails the test instead of silently echoing back.

## Harnesses

### `withSetup` — composables that need Vue Query

`tests/js/support/harness.ts`. Runs a composable inside a real component instance with
`VueQueryPlugin`, and seeds the query cache by key:

```ts
import { queryKeys } from '~/composables/useQueryClient'
import { withSetup, type Harness } from '../support/harness'

// Explicit type, NOT ReturnType<typeof setup> — that is circular and TS silently
// widens the whole composable surface to `any`, so nothing is really typechecked.
let harness: Harness<ReturnType<typeof useThing>> | undefined

const setup = () =>
  withSetup(() => useThing('space-1'), {
    seed: [[queryKeys.assetFolders('space-1').list({}), folders]],
  })

afterEach(() => { harness?.unmount(); harness = undefined })
```

Seed the cache rather than stubbing the composable: that keeps the **query keys** under
test, so a key that drifts stops resolving and the test fails. Retries are off and there
is no `fetch`, so an unseeded key fails loudly instead of hanging.

Two rules that are easy to get wrong, and did cost several agents time:

- **Build the query/mutation factory *inside* the setup callback.** Every
  `useXxxQuery`/`useXxxMutation` a composable returns calls a vue-query hook itself, so
  capturing the factory and calling it from a test body throws *"hooks can only be used
  inside setup()"*. Wrap the whole build in `withSetup`, not just the outer composable.
- **Type the harness explicitly** — `Harness<ReturnType<typeof useThing>>`, never
  `ReturnType<typeof setup>`. The latter is circular, and TS resolves it to `any`, so the
  file typechecks while asserting nothing about the composable's surface.

### `createPresenceController` — anything on Echo presence

`tests/js/support/presence.ts`. Stands in for `useContentPresence`/`usePresence`.
`fire(event, payload)` delivers a peer whisper; `sent` records outgoing whispers.

```ts
let presence: PresenceController
vi.mock('~/composables/usePresence', async () => {
  const actual = await vi.importActual<typeof import('~/composables/usePresence')>(
    '~/composables/usePresence'
  )
  return { ...actual, useContentPresence: () => presence }
})
beforeEach(() => { presence = createPresenceController('me') })
```

## Mocking

- **Mock at the transport boundary, not the logic boundary.** Fake `~/api`, a mutation's
  `mutateAsync`, `fetch`, Echo — never the function under test's own helpers.
- **Partial-mock with `importActual`** so the untargeted exports stay real:
  ```ts
  vi.mock('~/composables/useAssetFolders', async () => {
    const actual = await vi.importActual<typeof import('~/composables/useAssetFolders')>(
      '~/composables/useAssetFolders'
    )
    return {
      ...actual,
      useAssetFolders: (id) => ({
        ...actual.useAssetFolders(id),
        useUpdateAssetFolderMutation: () => ({ mutateAsync: updateFolder }),
      }),
    }
  })
  ```
  Then `const { useThing } = await import('~/composables/useThing')` *after* the mocks.
  This works for auto-imported composables too — `unplugin-auto-import` resolves to the
  same specifier.
- Stub `Icon` and `NuxtImg` in component tests; they resolve iconify collections and the
  image resizer, and never change what a component decides.

## Gotchas that have already bitten

- **A `Response` body can only be read once.** `fetchMock.mockResolvedValue(new Response(…))`
  makes the *second* call in a batch fail for the wrong reason. Use
  `mockImplementation(async () => new Response(…))`.
- **Template refs resolve on the tick after mount.** A `watchEffect` that registers a
  draggable/observer against `rootElement.value` has not run yet when `mount()` returns —
  `await wrapper.vm.$nextTick()` first.
- **`watch` is lazy.** A composable that populates state from `watch(someRef, …)` needs
  the ref to *change* after setup, plus `await nextTick()` — setting it beforehand means
  the watcher never fires.
- **jsdom has no `URL.createObjectURL`/`revokeObjectURL`** and no real downloads: stub
  them and spy on `HTMLAnchorElement.prototype.click`. Capture `href`/`download` *inside*
  the click spy — the link is usually removed before the function returns.
- **Fake timers**: `vi.setSystemTime` before mounting anything that reads the clock, and
  restore in `afterEach`.
- **Module-scoped state** (e.g. `useContentTreeClipboard`'s `clipboardState`) is shared
  across every caller by design — reset it in `beforeEach`.
- **`vi.mock('~/api')` is unreliable for concurrent `await import('~/api')`.** With two
  dynamic imports in flight the second can resolve to the *real* `ApiClient` and hit real
  `fetch` — and every later import in that file then gets the real module too. For
  dynamic-import composables, mock `fetch` instead.
- **A component with a timer must be unmounted.** A wrapper left attached (e.g.
  `vue-input-otp`, which polls for a password-manager badge) lets its timer fire during a
  *later* test file, where it surfaces as an unhandled error rather than a failure. Track
  wrappers and unmount them in `afterEach`.
- **Adding an explicit import from a module stops `unplugin-auto-import` injecting *any* of
  that module's names into the same file.** Writing
  `import { GLOBAL_TEAM_QUERY_PARAMS } from './useGlobalTeam'` silently broke the
  auto-imported `useGlobalTeam()` call further down — at runtime, with typecheck still
  passing. If you add one explicit import, import everything you use from that module
  explicitly.
- **vueuse's `useStorage` suspends its persist watcher for one tick after each write**, so a
  second write in the same tick updates memory but never reaches `localStorage`. Insert an
  `await nextTick()` between writes. (This is production behaviour, not a test artifact.)

## Lint and typecheck

`bun run lint:ci` and `bun run typecheck` gate CI and cover `tests/` too.

- `no-unsafe-optional-chaining`: `(a?.b as X).c` is a warning. Use `((a?.b ?? {}) as X).c`
  or a small helper.
- `no-this-alias`: don't assign `this` to a local in a spy; read the properties you need
  inside the callback.
- Import order is enforced: `vitest` / third-party, then `~/…` types, then `~/…` values,
  then relative.
- Prefer a narrow `as unknown as T` cast for fixtures over `any`. `as never` makes
  property access on the result a type error — avoid it for values you then read.
