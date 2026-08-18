import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h, inject, nextTick, ref, type Ref } from 'vue'
import {
  createMemoryHistory,
  createRouter,
  type NavigationGuard,
  type NavigationGuardNext,
  type RouteLocationNormalized,
  type Router,
} from 'vue-router'

import type { ContentLanguageVersion, ContentResource } from '~/types/contents'

const leaveGuards: NavigationGuard[] = []
const updateGuards: NavigationGuard[] = []

/**
 * Only the two guard registrations are faked: they need a matched route record,
 * which a bare mount has none of. `useRoute`/`useRouter` stay real because
 * `useRouteQuery` reaches them from inside `@vueuse/router`, which vitest does
 * not route through the mock registry — so the test drives a real router.
 */
vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return {
    ...actual,
    onBeforeRouteLeave: (guard: NavigationGuard) => leaveGuards.push(guard),
    onBeforeRouteUpdate: (guard: NavigationGuard) => updateGuards.push(guard),
  }
})

const confirm = vi.fn(async () => true)

vi.mock('~/composables/useAlertDialog', async () => {
  const actual = await vi.importActual<typeof import('~/composables/useAlertDialog')>(
    '~/composables/useAlertDialog'
  )

  return { ...actual, useAlertDialog: () => ({ ...actual.useAlertDialog(), alert: { confirm } }) }
})

const { useContentEditorPage } = await import('~/composables/useContentEditorPage')

type EditorPage = ReturnType<typeof useContentEditorPage>
type EditorPageOptions = Parameters<typeof useContentEditorPage>[0]

const languageVersion = (languageIso: string, isDefault = false): ContentLanguageVersion => ({
  language_iso: languageIso,
  label: languageIso.toUpperCase(),
  exists: true,
  content_id: `content-${languageIso}`,
  is_default: isDefault,
  is_current: false,
  status: 'draft',
  published_at: null,
  fallback_language: null,
})

const contentResource = (overrides: Partial<ContentResource> = {}): ContentResource =>
  ({
    id: 'content-1',
    name: 'Home',
    slug: 'home',
    content: { title: 'Hello' },
    ...overrides,
  }) as unknown as ContentResource

interface PageSetup {
  page: EditorPage
  content: Ref<ContentResource | null>
  persistedContent: Ref<ContentResource | null>
  routeContent: Ref<ContentResource | null>
  canonicalContent: Ref<ContentResource | null>
  spaceDefaultLanguage: Ref<string | null | undefined>
  onDiscardChanges: ReturnType<typeof vi.fn>
  unmount: () => void
}

let wrappers: VueWrapper[] = []
let router: Router

const createTestRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/content/:contentId',
        name: 'content-detail',
        component: defineComponent({ setup: () => () => h('div') }),
      },
    ],
  })

const setupPage = (options: Partial<EditorPageOptions> = {}): PageSetup => {
  const content = ref<ContentResource | null>(contentResource())
  const persistedContent = ref<ContentResource | null>(contentResource())
  const routeContent = ref<ContentResource | null>(contentResource())
  const canonicalContent = ref<ContentResource | null>(contentResource())
  const spaceDefaultLanguage = ref<string | null | undefined>('en')
  const onDiscardChanges = vi.fn()

  let page: EditorPage | undefined

  const wrapper = mount(
    defineComponent({
      setup() {
        page = useContentEditorPage({
          content,
          persistedContent,
          routeContent,
          canonicalContent,
          spaceDefaultLanguage,
          dirtyStrategy: 'edit-version',
          onDiscardChanges,
          ...options,
        })

        return () => h('div')
      },
    }),
    { global: { plugins: [router] } }
  )
  wrappers.push(wrapper)

  return {
    page: page as EditorPage,
    content,
    persistedContent,
    routeContent,
    canonicalContent,
    spaceDefaultLanguage,
    onDiscardChanges,
    unmount: () => wrapper.unmount(),
  }
}

const navigate = (
  guard: NavigationGuard,
  from: Partial<RouteLocationNormalized>,
  to: Partial<RouteLocationNormalized>
) => {
  const next = vi.fn()
  // A real router always normalizes `query`; the guard reads `?lang` off it.
  const normalize = (location: Partial<RouteLocationNormalized>) =>
    ({ query: {}, ...location }) as RouteLocationNormalized
  const result = guard(
    normalize(to),
    normalize(from),
    next as unknown as NavigationGuardNext
  )

  return { next, result }
}

beforeEach(async () => {
  leaveGuards.length = 0
  updateGuards.length = 0
  confirm.mockClear()
  confirm.mockResolvedValue(true)
  router = createTestRouter()
  await router.push('/content/content-1')
})

afterEach(() => {
  for (const wrapper of wrappers) wrapper.unmount()
  wrappers = []
})

describe('language resolution', () => {
  it('prefers the space default language', () => {
    const { page } = setupPage()

    expect(page.defaultLanguage.value).toBe('en')
  })

  it('falls back to the content default when the space has none', () => {
    const { page, spaceDefaultLanguage, routeContent } = setupPage()

    spaceDefaultLanguage.value = null
    routeContent.value = contentResource({
      language_versions: [languageVersion('en'), languageVersion('de', true)],
    })

    expect(page.defaultLanguage.value).toBe('de')
  })

  it('resolves ?lang against the canonical entry language versions', async () => {
    await router.push('/content/content-1?lang=de')
    const { page, canonicalContent } = setupPage()

    canonicalContent.value = contentResource({
      language_versions: [languageVersion('en', true), languageVersion('de')],
    })
    await nextTick()

    expect(page.languageQuery.value).toBe('de')
    expect(page.resolvedLanguage.value).toBe('de')
  })

  it('ignores a ?lang the content has no version for', async () => {
    await router.push('/content/content-1?lang=fr')
    const { page, canonicalContent } = setupPage()

    canonicalContent.value = contentResource({
      language_versions: [languageVersion('en', true), languageVersion('de')],
    })
    await nextTick()

    expect(page.resolvedLanguage.value).toBe('en')
  })

  it('falls back to the default language when no ?lang is set', () => {
    const { page, canonicalContent } = setupPage()

    canonicalContent.value = contentResource({
      language_versions: [languageVersion('en', true), languageVersion('de')],
    })

    expect(page.languageQuery.value).toBeUndefined()
    expect(page.resolvedLanguage.value).toBe('en')
  })
})

describe('dirty tracking', () => {
  it('counts edits with the edit-version strategy', () => {
    const { page, content } = setupPage({ dirtyStrategy: 'edit-version' })

    expect(page.isDirty.value).toBe(false)

    content.value = contentResource({ name: 'Renamed' })
    expect(page.isDirty.value).toBe(true)

    page.markSaved()
    expect(page.isDirty.value).toBe(false)
  })

  it('compares against the baseline with the snapshot strategy', () => {
    const { page, content, persistedContent } = setupPage({ dirtyStrategy: 'snapshot' })

    expect(page.isDirty.value).toBe(false)

    content.value = contentResource({ name: 'Renamed' })
    expect(page.isDirty.value).toBe(true)

    // The localization editor saves by replacing the baseline, not by re-baselining.
    persistedContent.value = contentResource({ name: 'Renamed' })
    expect(page.isDirty.value).toBe(false)
  })
})

describe('unsaved-changes guards', () => {
  it('registers a guard for both leaving and updating the route', () => {
    setupPage()

    expect(leaveGuards).toHaveLength(1)
    expect(updateGuards).toHaveLength(1)
  })

  it('lets a clean editor navigate away without asking', async () => {
    setupPage()

    const { next, result } = navigate(leaveGuards[0], { path: '/a' }, { path: '/b' })
    await result

    expect(confirm).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith()
  })

  it('does not ask when the path stays the same', async () => {
    const { content } = setupPage()

    content.value = contentResource({ name: 'Renamed' })
    const { next, result } = navigate(leaveGuards[0], { path: '/a' }, { path: '/a' })
    await result

    // Same-path UI query/hash changes are not a leave; `lang` is handled separately.
    expect(confirm).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith()
  })

  it('discards the edits once the user confirms leaving', async () => {
    const { content, onDiscardChanges } = setupPage()

    content.value = contentResource({ name: 'Renamed' })
    const { next, result } = navigate(leaveGuards[0], { path: '/a' }, { path: '/b' })
    await result

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(onDiscardChanges).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith()
  })

  it('cancels the navigation and keeps the edits when the user declines', async () => {
    const { content, onDiscardChanges } = setupPage()

    confirm.mockResolvedValue(false)
    content.value = contentResource({ name: 'Renamed' })
    const { next, result } = navigate(leaveGuards[0], { path: '/a' }, { path: '/b' })
    await result

    expect(onDiscardChanges).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith(false)
  })

  it('asks in real English, so a missing translation fails loudly', async () => {
    const { content } = setupPage()

    content.value = contentResource({ name: 'Renamed' })
    const { result } = navigate(leaveGuards[0], { path: '/a' }, { path: '/b' })
    await result

    expect(confirm).toHaveBeenCalledWith(
      'You have unsaved changes. Are you sure you want to leave?'
    )
  })

  it('guards a route update the same way it guards a leave', async () => {
    const { content, onDiscardChanges } = setupPage()

    content.value = contentResource({ name: 'Renamed' })
    const { next, result } = navigate(updateGuards[0], { path: '/a' }, { path: '/b' })
    await result

    expect(onDiscardChanges).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith()
  })
})

describe('beforeunload', () => {
  const addEventListener = vi.spyOn(window, 'addEventListener')
  const removeEventListener = vi.spyOn(window, 'removeEventListener')

  const beforeUnloadHandlers = (spy: typeof addEventListener) =>
    spy.mock.calls.filter(([event]) => event === 'beforeunload')

  // The registration watcher is pre-flush, so every assertion is a tick behind
  // the edit. Spies are cleared after mount: the immediate run already
  // deregisters once for the clean state the page starts in.
  const mountClean = async (options: Partial<EditorPageOptions> = {}) => {
    const setup = setupPage(options)
    await nextTick()
    addEventListener.mockClear()
    removeEventListener.mockClear()

    return setup
  }

  it('only warns the browser while there are unsaved changes', async () => {
    const { page, content } = await mountClean()

    expect(beforeUnloadHandlers(addEventListener)).toHaveLength(0)

    content.value = contentResource({ name: 'Renamed' })
    await nextTick()
    expect(beforeUnloadHandlers(addEventListener)).toHaveLength(1)

    page.markSaved()
    await nextTick()
    expect(beforeUnloadHandlers(removeEventListener)).toHaveLength(1)
  })

  it('cancels the unload event while dirty', async () => {
    const { content } = await mountClean()

    content.value = contentResource({ name: 'Renamed' })
    await nextTick()
    const [, handler] = beforeUnloadHandlers(addEventListener)[0]
    const event = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent
    ;(handler as (e: BeforeUnloadEvent) => void)(event)

    // jsdom models the legacy `returnValue` as `!defaultPrevented`, so the
    // prevented flag is the only observable part of the browser prompt.
    expect(event.defaultPrevented).toBe(true)
  })

  it('stops warning once the page is gone', async () => {
    const { content, unmount } = await mountClean()

    content.value = contentResource({ name: 'Renamed' })
    await nextTick()
    removeEventListener.mockClear()
    unmount()

    expect(beforeUnloadHandlers(removeEventListener)).toHaveLength(1)
  })
})

describe('provideValidationState', () => {
  const injectionKeys = [
    'markFieldDirty',
    'getFieldError',
    'shouldShowFieldError',
    'setValidationErrors',
    'clearValidationErrors',
    'getClientValidationErrors',
    'getValidationSummary',
    'getVisibleValidationEntries',
    'getValidationIssueSignature',
    'sanitizeContentForSubmit',
    'validateContentForSubmit',
    'revealValidationState',
    'submitValidationAttempted',
    'focusFirstValidationError',
  ]

  const mountWithValidation = () => {
    const validation = {
      markFieldDirty: vi.fn(),
      getFieldError: vi.fn(),
      shouldShowFieldError: vi.fn(),
      setServerErrors: vi.fn(),
      clearServerErrors: vi.fn(),
      getClientErrors: vi.fn(),
      getVisibleValidationEntries: vi.fn(),
      getValidationIssueSignature: vi.fn(),
      validateAllForSubmit: vi.fn(),
      revealValidationState: vi.fn(),
      focusFirstInvalidField: vi.fn(),
      submitAttempted: ref(false),
      validationSummary: computed(() => ({ isValid: false, issueCount: 2 })),
      sanitizedContent: computed(() => ({ title: 'sanitized' })),
    }

    const injected: Record<string, unknown> = {}

    const Child = defineComponent({
      setup() {
        for (const key of injectionKeys) injected[key] = inject(key)

        return () => h('span')
      },
    })

    const wrapper = mount(
      defineComponent({
        setup() {
          const page = useContentEditorPage({
            content: ref(contentResource()),
            persistedContent: ref(contentResource()),
            routeContent: ref(contentResource()),
            canonicalContent: ref(contentResource()),
            spaceDefaultLanguage: ref('en'),
            dirtyStrategy: 'edit-version',
          })
          page.provideValidationState(
            validation as unknown as Parameters<typeof page.provideValidationState>[0]
          )

          return () => h(Child)
        },
      }),
      { global: { plugins: [router] } }
    )
    wrappers.push(wrapper)

    return { validation, injected }
  }

  it('provides every key the editor fields and header actions inject', () => {
    const { injected } = mountWithValidation()

    for (const key of injectionKeys) {
      expect(injected[key], `missing injection: ${key}`).toBeDefined()
    }
  })

  it('hands through the validation callbacks unwrapped', () => {
    const { validation, injected } = mountWithValidation()

    expect(injected.markFieldDirty).toBe(validation.markFieldDirty)
    expect(injected.setValidationErrors).toBe(validation.setServerErrors)
    expect(injected.clearValidationErrors).toBe(validation.clearServerErrors)
    expect(injected.getClientValidationErrors).toBe(validation.getClientErrors)
    expect(injected.validateContentForSubmit).toBe(validation.validateAllForSubmit)
    expect(injected.focusFirstValidationError).toBe(validation.focusFirstInvalidField)
    expect(injected.submitValidationAttempted).toBe(validation.submitAttempted)
  })

  it('reads the computed state at call time, not at provide time', () => {
    const { injected } = mountWithValidation()

    expect((injected.getValidationSummary as () => unknown)()).toEqual({
      isValid: false,
      issueCount: 2,
    })
    expect((injected.sanitizeContentForSubmit as () => unknown)()).toEqual({ title: 'sanitized' })
  })
})
