import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useStorage } from '@vueuse/core'
import type { MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { OnboardingFramework, PackageManager } from '~/lib/onboarding'

import { queryKeys } from './useQueryClient'

interface OnboardingChoices {
  framework: OnboardingFramework | null
  packageManager: PackageManager
  /** Empty means "not touched" — the page falls back to the space slug. */
  directory: string
  /**
   * The scaffold step has no server-side signal — nothing tells us the user ran
   * the CLI — so copying the command is what marks it done.
   */
  commandCopied: boolean
}

/**
 * A factory, not a shared object: `useStorage` hands back the very object it was
 * given whenever the key holds nothing, so a single instance would be mutated in
 * place and the next space with no stored onboarding would start from — and
 * immediately persist — the previous space's answers.
 */
const createDefaultChoices = (): OnboardingChoices => ({
  framework: null,
  packageManager: 'bun',
  directory: '',
  commandCopied: false,
})

export function useOnboarding(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  /**
   * Which stack you picked is a per-developer answer, not a property of the
   * space: two people onboarding the same space may well target different
   * frameworks. Only the dismissal is shared, and that lives on the space.
   */
  const choices = useStorage<OnboardingChoices>(
    computed(() => `space-${toValue(spaceId)}-onboarding`),
    createDefaultChoices,
    undefined,
    { mergeDefaults: true }
  )

  const useDismissOnboardingMutation = () => {
    return useMutation({
      mutationFn: async (dismissed: boolean) => {
        const response = await api.spaces.updateOnboarding(toValue(spaceId), dismissed)
        return response.data
      },
      // Keyed off the space we mutated, not off the response: a mismatched id
      // would invalidate a stranger and leave this space stale.
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.detail(toValue(spaceId)) })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.onboarding.dismissError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    choices,
    useDismissOnboardingMutation,
  }
}
