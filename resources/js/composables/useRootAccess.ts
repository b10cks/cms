import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import { toastError } from '~/lib/toast-error'
import type { ElevationDuration } from '~/types/provider-console'

/**
 * Root elevation and "sign in as". Both change what the current session may
 * do everywhere, so every cached response is refetched or dropped.
 */
export function useRootAccess() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const router = useRouter()
  const { loadUser } = useAuth()

  /** A different user behind the session: nothing cached may survive. */
  const switchSession = async (to: string | { name: string }) => {
    queryClient.cancelQueries()
    queryClient.clear()
    await loadUser(true)
    await router.push(to)
  }

  const useElevateMutation = () =>
    useMutation({
      mutationFn: ({ reason, duration }: { reason: string; duration: ElevationDuration }) =>
        api.rootAccess.elevate(reason, duration),
      onSuccess: () => {
        void queryClient.invalidateQueries()
        toast.success(t('rootAccess.toasts.elevated'))
      },
      onError: (error) => toastError(t, 'rootAccess.toasts.failed', error),
    })

  const useEndElevationMutation = () =>
    useMutation({
      mutationFn: () => api.rootAccess.endElevation(),
      onSuccess: () => {
        void queryClient.invalidateQueries()
      },
      onError: (error) => toastError(t, 'rootAccess.toasts.failed', error),
    })

  const useImpersonateMutation = () =>
    useMutation({
      mutationFn: ({ userId, reason }: { userId: string; reason: string }) =>
        api.rootAccess.impersonate(userId, reason),
      onSuccess: () => switchSession('/'),
      onError: (error) => toastError(t, 'rootAccess.toasts.failed', error),
    })

  /** Also runs when the server already ended it: the session is the operator's again either way. */
  const useStopImpersonatingMutation = () =>
    useMutation({
      mutationFn: () => api.rootAccess.stopImpersonating(),
      onSettled: () => switchSession({ name: 'provider-users' }),
    })

  return {
    useElevateMutation,
    useEndElevationMutation,
    useImpersonateMutation,
    useStopImpersonatingMutation,
  }
}
