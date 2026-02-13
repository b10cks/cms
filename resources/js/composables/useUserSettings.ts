import { computed, ref, watch } from 'vue'

import type { User } from '~/types/users'

export function useUserSettings(user: Ref<User | undefined>) {
  const { useUpdateUserMutation } = useUser()
  const { mutate: updateUser, isPending: isUpdating } = useUpdateUserMutation()

  const languageIso = ref('en')

  watch(
    () => user?.value,
    (newUser) => {
      if (newUser?.settings?.languageIso) {
        languageIso.value = newUser.settings.languageIso
      }
    },
    { immediate: true }
  )

  const handleUpdateLanguage = async (newLanguageIso: string) => {
    const i18n = useI18n() as any
    languageIso.value = newLanguageIso
    i18n.switchLocale(newLanguageIso)
    await updateUser({
      settings: {
        languageIso: newLanguageIso,
      },
    })
  }

  return {
    languageIso: computed(() => languageIso.value),
    isUpdating: computed(() => isUpdating.value),
    handleUpdateLanguage,
  }
}
