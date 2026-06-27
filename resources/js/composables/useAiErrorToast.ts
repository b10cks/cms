import { toast } from 'vue-sonner'

import { aiErrorMessage, isPlanError } from '~/lib/aiErrors'

/**
 * Consistent toast for AI failures. Resolves the backend `reason` to a localised
 * message and, for plan-related failures, offers a shortcut to the space's
 * subscription page so the user can upgrade.
 */
export function useAiErrorToast() {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()

  const showAiError = (reason?: string | null, fallbackMessage?: string | null): void => {
    const message = aiErrorMessage(t, reason, fallbackMessage)

    if (isPlanError(reason)) {
      const space = route.params.space as string | undefined

      toast.error(
        message,
        space
          ? {
              action: {
                label: t('composables.ai.upgradeAction') as string,
                onClick: () =>
                  router.push({ name: 'space-settings-subscription', params: { space } }),
              },
            }
          : undefined
      )

      return
    }

    toast.error(message)
  }

  return { showAiError }
}
