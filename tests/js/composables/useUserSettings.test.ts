import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import type { User } from '~/types/users'

const updateSettings = vi.fn()
const toastError = vi.fn()

vi.mock('~/api', () => ({
  api: { users: { updateSettings } },
}))

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn(), error: toastError },
}))

// `useAuth` imports the real router, which would pull in every page chunk.
vi.mock('~/router', () => ({
  router: { currentRoute: { value: { query: {}, meta: {}, fullPath: '/' } }, push: vi.fn() },
}))

vi.mock('~/plugins/posthog', () => ({
  getPosthog: () => ({ identify: vi.fn(), reset: vi.fn() }),
}))

const STORAGE_KEY = 'user-settings'

// This jsdom build exposes no localStorage; useUserSettings hydrates from it at
// module load and writes back on every change.
const memoryStorage = (() => {
  const store = new Map<string, string>()

  return {
    get length() {
      return store.size
    },
    key: (index: number) => [...store.keys()][index] ?? null,
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => void store.set(key, String(value)),
    removeItem: (key: string) => void store.delete(key),
    clear: () => store.clear(),
  } as Storage
})()

Object.defineProperty(window, 'localStorage', { value: memoryStorage, configurable: true })

const user = (settings?: User['settings']): User =>
  ({ id: 'user-1', email: 'ada@b10cks.test', firstname: 'Ada', lastname: 'Lovelace', settings }) as User

type Auth = ReturnType<typeof import('~/composables/useAuth').useAuth>
type Settings = ReturnType<typeof import('~/composables/useUserSettings').useUserSettings>

/**
 * The settings live in module scope behind an `initialized` latch, and hydrate
 * from localStorage at import time — so every test needs the module evaluated
 * afresh rather than a reset of state the composable does not expose.
 */
const load = async (
  { currentUser, stored }: { currentUser?: User; stored?: string } = {}
): Promise<{ auth: Auth; settings: Settings; getI18nLocale: () => string }> => {
  vi.resetModules()
  window.localStorage.clear()

  if (stored !== undefined) {
    window.localStorage.setItem(STORAGE_KEY, stored)
  }

  const { useAuth } = await import('~/composables/useAuth')
  const i18n = await import('~/plugins/i18n')
  i18n.setLocale('en')

  const auth = useAuth()

  if (currentUser) {
    auth.setUser(currentUser)
  }

  const { useUserSettings } = await import('~/composables/useUserSettings')

  return { auth, settings: useUserSettings(), getI18nLocale: i18n.getLocale }
}

/** persistSetting is fire-and-forget behind a watcher. */
const flush = async () => {
  await nextTick()
  await Promise.resolve()
  await Promise.resolve()
}

beforeEach(() => {
  vi.clearAllMocks()
  updateSettings.mockResolvedValue({})
})

afterEach(async () => {
  const { setLocale } = await import('~/plugins/i18n')
  setLocale('en')
})

describe('initial state', () => {
  it('starts from the defaults', async () => {
    const { settings } = await load()

    expect(settings.settings).toEqual({ languageIso: 'en', extendedSidebar: true })
  })

  it('hydrates the last-known settings from storage before any user loads', async () => {
    const { settings, getI18nLocale } = await load({
      stored: JSON.stringify({ languageIso: 'de', extendedSidebar: false }),
    })

    expect(settings.languageIso.value).toBe('de')
    expect(settings.extendedSidebar.value).toBe(false)
    expect(getI18nLocale()).toBe('de')
  })

  it.each([
    ['unparseable JSON', '{'],
    ['an empty object', '{}'],
  ])('falls back to the defaults for %s in storage', async (_label, stored) => {
    const { settings } = await load({ stored })

    expect(settings.settings).toEqual({ languageIso: 'en', extendedSidebar: true })
  })

  it('applies the settings of a user who is already loaded', async () => {
    const { settings, getI18nLocale } = await load({
      currentUser: user({ languageIso: 'de', extendedSidebar: false }),
    })

    expect(settings.settings).toEqual({ languageIso: 'de', extendedSidebar: false })
    expect(getI18nLocale()).toBe('de')
  })

  it('lets the user settings win over the stored ones', async () => {
    const { settings } = await load({
      stored: JSON.stringify({ languageIso: 'de' }),
      currentUser: user({ languageIso: 'en' }),
    })

    expect(settings.languageIso.value).toBe('en')
  })

  it('fills the defaults in for a partial settings payload', async () => {
    const { settings } = await load({ currentUser: user({ extendedSidebar: false }) })

    expect(settings.settings).toEqual({ languageIso: 'en', extendedSidebar: false })
  })
})

describe('syncing with the signed-in user', () => {
  it('adopts the settings when the user arrives later', async () => {
    const { auth, settings } = await load()

    auth.setUser(user({ languageIso: 'de', extendedSidebar: false }))
    await nextTick()

    expect(settings.settings).toEqual({ languageIso: 'de', extendedSidebar: false })
  })

  it('keeps the last-known settings when the user is cleared', async () => {
    // Pinned, not endorsed: `assignSettings` ignores a null payload, so the
    // signed-out browser keeps the previous account's locale and sidebar — and
    // localStorage still holds them for whoever signs in next.
    const { auth, settings } = await load({
      currentUser: user({ languageIso: 'de', extendedSidebar: false }),
    })

    auth.setUser(null)
    await nextTick()

    expect(settings.settings).toEqual({ languageIso: 'de', extendedSidebar: false })
  })

  it('caches the settings applied during initialisation', async () => {
    // The documented "first paint matches the preference" cache has to be filled
    // for a user who is already loaded, not only when a setting later changes.
    await load({ currentUser: user({ languageIso: 'de', extendedSidebar: false }) })

    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null')).toEqual({
      languageIso: 'de',
      extendedSidebar: false,
    })
  })

  it('caches settings that arrive after initialisation', async () => {
    const { auth } = await load()

    auth.setUser(user({ languageIso: 'de', extendedSidebar: false }))
    await nextTick()

    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null')).toEqual({
      languageIso: 'de',
      extendedSidebar: false,
    })
  })

  it('does not echo a server-sent change back to the server', async () => {
    const { auth } = await load({ currentUser: user({ languageIso: 'en' }) })

    auth.setUser(user({ languageIso: 'de' }))
    await flush()

    expect(updateSettings).not.toHaveBeenCalled()
  })
})

describe('persisting a change', () => {
  it('sends only the changed key and updates the user optimistically', async () => {
    const { auth, settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.extendedSidebar.value = false
    await flush()

    expect(updateSettings).toHaveBeenCalledWith({ extendedSidebar: false })
    expect(auth.user.value?.settings).toEqual({ languageIso: 'en', extendedSidebar: false })
  })

  it('switches the locale when the language changes', async () => {
    const { settings, getI18nLocale } = await load({ currentUser: user({ languageIso: 'en' }) })

    settings.languageIso.value = 'de'
    await flush()

    expect(updateSettings).toHaveBeenCalledWith({ languageIso: 'de' })
    expect(getI18nLocale()).toBe('de')
  })

  it('writes the change to storage', async () => {
    const { settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.extendedSidebar.value = false
    await flush()

    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null')).toEqual({
      languageIso: 'en',
      extendedSidebar: false,
    })
  })

  it('ignores a write that matches the stored user setting', async () => {
    const { settings } = await load({ currentUser: user({ extendedSidebar: false }) })

    settings.extendedSidebar.value = false
    await flush()

    expect(updateSettings).not.toHaveBeenCalled()
  })

  it('keeps a change local when nobody is signed in', async () => {
    // Pinned, not endorsed: persistSetting bails without a user, so the toggle
    // moves, persists to localStorage and is never sent anywhere.
    const { settings } = await load()

    settings.extendedSidebar.value = false
    await flush()

    expect(settings.extendedSidebar.value).toBe(false)
    expect(updateSettings).not.toHaveBeenCalled()
  })

  it('tracks the in-flight request', async () => {
    let resolveUpdate: (() => void) | undefined
    updateSettings.mockReturnValue(
      new Promise<void>((resolve) => {
        resolveUpdate = resolve
      })
    )

    const { settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.extendedSidebar.value = false
    await flush()
    expect(settings.isUpdating.value).toBe(true)

    resolveUpdate?.()
    await flush()

    expect(settings.isUpdating.value).toBe(false)
  })

  it('stays updating until the last of two changes settles', async () => {
    const resolvers: Array<() => void> = []
    updateSettings.mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          resolvers.push(resolve)
        })
    )

    const { settings } = await load({
      currentUser: user({ languageIso: 'en', extendedSidebar: true }),
    })

    settings.extendedSidebar.value = false
    await flush()
    settings.languageIso.value = 'de'
    await flush()

    expect(resolvers).toHaveLength(2)

    resolvers[0]()
    await flush()
    expect(settings.isUpdating.value).toBe(true)

    resolvers[1]()
    await flush()
    expect(settings.isUpdating.value).toBe(false)
  })

  it('keeps both settings changed in the same tick', async () => {
    // Each optimistic update composes onto the live state instead of rewriting
    // every key from the user's stored settings, so neither change reverts the
    // other before its own watcher runs.
    const { auth, settings } = await load({
      currentUser: user({ languageIso: 'en', extendedSidebar: true }),
    })

    settings.extendedSidebar.value = false
    settings.languageIso.value = 'de'
    await flush()

    expect(settings.languageIso.value).toBe('de')
    expect(settings.extendedSidebar.value).toBe(false)
    expect(updateSettings).toHaveBeenCalledTimes(2)
    expect(updateSettings).toHaveBeenCalledWith({ extendedSidebar: false })
    expect(updateSettings).toHaveBeenCalledWith({ languageIso: 'de' })
    expect(auth.user.value?.settings).toEqual({ languageIso: 'de', extendedSidebar: false })
  })

  it('rolls back only the failed key when a sibling change succeeded', async () => {
    updateSettings.mockImplementation(async (payload: Record<string, unknown>) => {
      if ('languageIso' in payload) throw new Error('Network down')
      return {}
    })

    const { settings } = await load({
      currentUser: user({ languageIso: 'en', extendedSidebar: true }),
    })

    settings.extendedSidebar.value = false
    settings.languageIso.value = 'de'
    await flush()

    expect(settings.languageIso.value).toBe('en')
    expect(settings.extendedSidebar.value).toBe(false)
  })
})

describe('a failed update', () => {
  it('rolls the setting, the user and the locale back', async () => {
    updateSettings.mockRejectedValue(new Error('Network down'))

    const { auth, settings, getI18nLocale } = await load({
      currentUser: user({ languageIso: 'en', extendedSidebar: true }),
    })

    settings.languageIso.value = 'de'
    await flush()

    expect(settings.languageIso.value).toBe('en')
    expect(getI18nLocale()).toBe('en')
    expect(auth.user.value?.settings).toEqual({ languageIso: 'en', extendedSidebar: true })
    expect(toastError).toHaveBeenCalledWith('Network down')
  })

  it('rolls a sidebar toggle back', async () => {
    updateSettings.mockRejectedValue(new Error('Network down'))

    const { settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.extendedSidebar.value = false
    await flush()

    expect(settings.extendedSidebar.value).toBe(true)
  })

  it('falls back to generic copy for a message-less failure', async () => {
    updateSettings.mockRejectedValue({})

    const { settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.extendedSidebar.value = false
    await flush()

    expect(toastError).toHaveBeenCalledWith('Failed to update user settings')
  })

  it('clears the updating flag', async () => {
    updateSettings.mockRejectedValue(new Error('Network down'))

    const { settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.extendedSidebar.value = false
    await flush()

    expect(settings.isUpdating.value).toBe(false)
  })
})

describe('dispose', () => {
  it('stops adopting further user settings', async () => {
    const { auth, settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.dispose()
    auth.setUser(user({ extendedSidebar: false }))
    await nextTick()

    expect(settings.extendedSidebar.value).toBe(true)
  })

  it('stops persisting changes too', async () => {
    const { settings } = await load({ currentUser: user({ extendedSidebar: true }) })

    settings.dispose()
    settings.extendedSidebar.value = false
    await flush()

    expect(updateSettings).not.toHaveBeenCalled()
  })

  it('lets a re-init register exactly one set of watchers', async () => {
    const { auth, settings: first } = await load({ currentUser: user({ extendedSidebar: true }) })

    first.dispose()

    const { useUserSettings } = await import('~/composables/useUserSettings')
    const second = useUserSettings()

    second.extendedSidebar.value = false
    await flush()

    expect(updateSettings).toHaveBeenCalledTimes(1)
    expect(auth.user.value?.settings?.extendedSidebar).toBe(false)
  })
})
