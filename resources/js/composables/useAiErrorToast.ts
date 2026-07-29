import { toast } from 'vue-sonner'

import { aiErrorMessage, isPlanError } from '~/lib/aiErrors'
import { runtimeConfig } from '~/lib/runtime-config'

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

    // The subscription route only exists when billing is enabled; on
    // self-hosted installs there is no upgrade path to offer.
    if (isPlanError(reason) && runtimeConfig.public.features.billing) {
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
