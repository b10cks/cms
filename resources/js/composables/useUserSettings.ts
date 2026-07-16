import { computed, reactive, watch } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import { setLocale } from '~/plugins/i18n'
import type { User } from '~/types/users'

type UserSettings = {
  languageIso: string
  extendedSidebar: boolean
}

const defaultSettings: UserSettings = {
  languageIso: 'en',
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
let stopSync: (() => void) | null = null
let syncSuspended = false

const state = reactive<UserSettings>({
  ...defaultSettings,
  ...readStoredSettings(),
})

const meta = reactive({
  isUpdating: false,
  pendingCount: 0,
})

const assignSettings = (nextSettings?: Partial<User['settings']> | null) => {
  // No user (yet) — keep the last-known settings instead of resetting to
  // defaults, so nothing jumps while the user request is in flight.
  if (!nextSettings) return

  state.languageIso = nextSettings.languageIso ?? defaultSettings.languageIso
  state.extendedSidebar = nextSettings.extendedSidebar ?? defaultSettings.extendedSidebar

  setLocale(state.languageIso as 'en' | 'de')
}

export function useUserSettings() {
  const { user, setUser } = useAuth()
  const i18n = useI18n() as any

  const persistSetting = async <K extends keyof UserSettings>(key: K, value: UserSettings[K]) => {
    const currentUser = user.value
    if (!currentUser) return

    const previousSettings: UserSettings = {
      languageIso: currentUser.settings?.languageIso ?? defaultSettings.languageIso,
      extendedSidebar: currentUser.settings?.extendedSidebar ?? defaultSettings.extendedSidebar,
    }

    if (previousSettings[key] === value) return

    const optimisticSettings: UserSettings = {
      ...previousSettings,
      [key]: value,
    }

    syncSuspended = true
    assignSettings(optimisticSettings)
    syncSuspended = false

    setUser({
      ...currentUser,
      settings: optimisticSettings,
    })

    if (key === 'languageIso') {
      i18n.setLocale(value)
    }

    meta.pendingCount += 1
    meta.isUpdating = true

    try {
      await api.users.updateSettings({ [key]: value } as Partial<UserSettings>)
    } catch (error: any) {
      syncSuspended = true
      assignSettings(previousSettings)
      syncSuspended = false

      setUser({
        ...currentUser,
        settings: previousSettings,
      })

      if (key === 'languageIso') {
        i18n.setLocale(previousSettings.languageIso)
      }

      toast.error(error?.message || 'Failed to update user settings')
    } finally {
      meta.pendingCount -= 1
      meta.isUpdating = meta.pendingCount > 0
    }
  }

  if (!initialized) {
    setLocale(state.languageIso as 'en' | 'de')
    assignSettings(user.value?.settings)

    watch(
      () => ({ ...state }),
      (value) => {
        if (typeof window === 'undefined') return
        try {
          window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
        } catch {
          /** */
        }
      }
    )

    stopSync = watch(
      () => user.value?.settings,
      (nextSettings) => {
        if (syncSuspended) return
        assignSettings(nextSettings)
      },
      { immediate: true, deep: true }
    )

    watch(
      () => state.languageIso,
      (value, oldValue) => {
        if (!initialized || syncSuspended || value === oldValue) return
        void persistSetting('languageIso', value)
      }
    )

    watch(
      () => state.extendedSidebar,
      (value, oldValue) => {
        if (!initialized || syncSuspended || value === oldValue) return
        void persistSetting('extendedSidebar', value)
      }
    )

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
    dispose: () => {
      stopSync?.()
      stopSync = null
      initialized = false
    },
  }
}
