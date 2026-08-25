import { computed, reactive, watch } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { LocaleCode } from '~/plugins/i18n'
import { detectBrowserLocale, setLocale } from '~/plugins/i18n'
import type { User } from '~/types/users'

type UserSettings = {
  languageIso: string
  extendedSidebar: boolean
}

// The browser's language is the best guess until the user has an opinion of their
// own — a saved account setting and the cached settings below both outrank it.
const defaultSettings: UserSettings = {
  languageIso: detectBrowserLocale(),
  extendedSidebar: true,
}

// Last-known settings survive reloads so the first paint (sidebar width,
// locale) matches the user's preference instead of flashing the defaults
// until the user request resolves.
const STORAGE_KEY = 'user-settings'

const readStoredSettings = (): Partial<UserSettings> => {
  if (typeof window === 'undefined') return {}
  try {
    return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}') as Partial<UserSettings>
  } catch {
    return {}
  }
}

let initialized = false
let stopWatchers: Array<() => void> = []
let syncSuspended = false

const state = reactive<UserSettings>({
  ...defaultSettings,
  ...readStoredSettings(),
})

const meta = reactive({
  isUpdating: false,
  pendingCount: 0,
})

const settingsFromState = (): UserSettings => ({
  languageIso: state.languageIso,
  extendedSidebar: state.extendedSidebar,
})

const assignSettings = (nextSettings?: Partial<User['settings']> | null) => {
  // No user (yet) — keep the last-known settings instead of resetting to
  // defaults, so nothing jumps while the user request is in flight.
  if (!nextSettings) return

  state.languageIso = nextSettings.languageIso ?? defaultSettings.languageIso
  state.extendedSidebar = nextSettings.extendedSidebar ?? defaultSettings.extendedSidebar

  setLocale(state.languageIso as LocaleCode)
}

export function useUserSettings() {
  const { user, setUser } = useAuth()

  const persistSetting = async <K extends keyof UserSettings>(key: K, value: UserSettings[K]) => {
    const currentUser = user.value
    if (!currentUser) return

    const previousSettings: UserSettings = {
      languageIso: currentUser.settings?.languageIso ?? defaultSettings.languageIso,
      extendedSidebar: currentUser.settings?.extendedSidebar ?? defaultSettings.extendedSidebar,
    }

    if (previousSettings[key] === value) return

    // Both optimistic writes touch this key only. Rewriting every key — from the
    // state or from the stored settings — would revert a sibling changed in the
    // same tick before its own watcher had a chance to persist it.
    syncSuspended = true
    assignSettings({ ...settingsFromState(), [key]: value })
    syncSuspended = false

    setUser({
      ...currentUser,
      settings: { ...previousSettings, [key]: value },
    })

    if (key === 'languageIso') {
      setLocale(value as LocaleCode)
    }

    meta.pendingCount += 1
    meta.isUpdating = true

    try {
      await api.users.updateSettings({ [key]: value } as Partial<UserSettings>)
    } catch (error: any) {
      // Roll back this key only — a sibling change made meanwhile is unrelated
      // and may already have been accepted by the server.
      syncSuspended = true
      assignSettings({ ...settingsFromState(), [key]: previousSettings[key] })
      syncSuspended = false

      setUser({
        ...currentUser,
        settings: { ...(user.value?.settings ?? previousSettings), [key]: previousSettings[key] },
      })

      if (key === 'languageIso') {
        setLocale(previousSettings.languageIso as LocaleCode)
      }

      toast.error(error?.message || 'Failed to update user settings')
    } finally {
      meta.pendingCount -= 1
      meta.isUpdating = meta.pendingCount > 0
    }
  }

  if (!initialized) {
    setLocale(state.languageIso as LocaleCode)
    assignSettings(user.value?.settings)

    stopWatchers = [
      // `immediate`, so the settings applied during initialisation are cached
      // too — otherwise the first paint after a reload has nothing to read.
      watch(
        () => ({ ...state }),
        (value) => {
          if (typeof window === 'undefined') return
          try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
          } catch {
            /** */
          }
        },
        { immediate: true }
      ),

      watch(
        () => user.value?.settings,
        (nextSettings) => {
          if (syncSuspended) return
          assignSettings(nextSettings)
        },
        { immediate: true, deep: true }
      ),

      watch(
        () => state.languageIso,
        (value, oldValue) => {
          if (!initialized || syncSuspended || value === oldValue) return
          void persistSetting('languageIso', value)
        }
      ),

      watch(
        () => state.extendedSidebar,
        (value, oldValue) => {
          if (!initialized || syncSuspended || value === oldValue) return
          void persistSetting('extendedSidebar', value)
        }
      ),
    ]

    initialized = true
  }

  return {
    settings: state,
    languageIso: computed({
      get: () => state.languageIso,
      set: (value: string) => {
        state.languageIso = value
      },
    }),
    extendedSidebar: computed({
      get: () => state.extendedSidebar,
      set: (value: boolean) => {
        state.extendedSidebar = value
      },
    }),
    isUpdating: computed(() => meta.isUpdating),
    // Stops every watcher this module registered, so a re-init starts from one
    // clean set instead of stacking a second one on top.
    dispose: () => {
      stopWatchers.forEach((stop) => stop())
      stopWatchers = []
      initialized = false
    },
  }
}
