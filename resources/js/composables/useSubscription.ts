import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

type ApiError = Error & { status?: number; data?: { use_reinit?: boolean } }

/** A reinit that comes back without a payment link — a failure, not a success. */
class MissingCheckoutUrlError extends Error {}

export function useSubscription(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useSubscriptionsQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.subscriptions(spaceId.value).lists()),
      queryFn: async () => {
        const response = await spaceAPI.value.subscriptions.index()
        return response.data
      },
      enabled: computed(() => !!spaceId.value),
    })
  }

  const useCurrentSubscriptionQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.subscriptions(spaceId.value).current()),
      queryFn: async () => {
        const response = await spaceAPI.value.subscriptions.current()
        return response.data
      },
      enabled: computed(() => !!spaceId.value),
    })
  }

  const useProposalQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.subscriptions(spaceId.value).proposal()),
      queryFn: async () => {
        const response = await spaceAPI.value.subscriptions.proposal()
        return response.data
      },
      enabled: computed(() => !!spaceId.value),
    })
  }

  const useCreateProposalMutation = () => {
    return useMutation({
      mutationFn: async (payload: { planId: string; interval?: BillingInterval; email: string }) => {
        const response = await spaceAPI.value.subscriptions.createProposal({
          plan_id: payload.planId,
          interval: payload.interval ?? 'month',
          email: payload.email,
        })
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.subscriptions(spaceId.value).proposal(),
        })
        toast.success(t('composables.subscriptions.proposalSent'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.subscriptions.proposalError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  const useRevokeProposalMutation = () => {
    return useMutation({
      mutationFn: async () => {
        await spaceAPI.value.subscriptions.revokeProposal()
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.subscriptions(spaceId.value).proposal(),
        })
        toast.success(t('composables.subscriptions.proposalRevoked'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.subscriptions.proposalError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  const useCheckoutMutation = () => {
    return useMutation({
      mutationFn: async ({ planId, interval = 'month' }: { planId: string; interval?: BillingInterval }) => {
        return spaceAPI.value.subscriptions.checkout(planId, interval)
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })
        // The plan carries the quotas, the space detail carries the limits derived from it.
        queryClient.invalidateQueries({ queryKey: queryKeys.plans.all() })
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.detail(spaceId.value) })
        queryClient.invalidateQueries({ queryKey: queryKeys.spaceUsage(spaceId.value).all() })

        if (data.checkout_url) {
          window.location.href = data.checkout_url
        } else if (data.upgraded) {
          toast.success(t('composables.subscriptions.upgradeSuccess'))
        } else if (data.scheduled) {
          toast.success(t('composables.subscriptions.downgradeScheduled'))
        } else {
          toast.success(t('composables.subscriptions.planChanged'))
        }
      },
      onError: (error: Error) => {
        const apiError = error as ApiError
        if (apiError.status === 409 && apiError.data?.use_reinit) {
          // A pending payment already exists for this plan — refresh so the pending notice appears
          queryClient.invalidateQueries({
            queryKey: queryKeys.subscriptions(spaceId.value).current(),
          })
          toast.warning(t('composables.subscriptions.pendingAlreadyExists'))
        } else if (apiError.status === 409) {
          toast.warning(t('composables.subscriptions.pendingConflict'))
        } else {
          toast.error(
            t('composables.subscriptions.checkoutError', {
              error: error.message || 'Unknown error',
            })
          )
        }
      },
    })
  }

  const useReinitPaymentMutation = () => {
    return useMutation({
      mutationFn: async () => {
        const response = await spaceAPI.value.subscriptions.reinit()
        if (!response.checkout_url) throw new MissingCheckoutUrlError()
        return { ...response, checkout_url: response.checkout_url }
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })
        window.location.href = data.checkout_url
      },
      onError: (error: Error) => {
        // Either way the pending checkout may be gone server-side, so refresh the notice.
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })
        toast.error(
          error instanceof MissingCheckoutUrlError
            ? t('composables.subscriptions.reinitNoUrl')
            : t('composables.subscriptions.reinitError', {
                error: error.message || 'Unknown error',
              })
        )
      },
    })
  }

  const useDiscardPendingMutation = () => {
    return useMutation({
      mutationFn: async () => {
        await spaceAPI.value.subscriptions.discardPending()
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })
        toast.success(t('composables.subscriptions.pendingDiscarded'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.subscriptions.pendingDiscardError', {
            error: error.message || 'Unknown error',
          })
        )
      },
    })
  }

  const useCancelMutation = () => {
    return useMutation({
      mutationFn: async () => {
        return spaceAPI.value.subscriptions.cancel()
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })
        toast.success(t('composables.subscriptions.cancelSuccess'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.subscriptions.cancelError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  const useResumeMutation = () => {
    return useMutation({
      mutationFn: async () => {
        return spaceAPI.value.subscriptions.resume()
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })
        toast.success(t('composables.subscriptions.resumeSuccess'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.subscriptions.resumeError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  return {
    useSubscriptionsQuery,
    useCurrentSubscriptionQuery,
    useCheckoutMutation,
    useReinitPaymentMutation,
    useCancelMutation,
    useResumeMutation,
    useDiscardPendingMutation,
    useProposalQuery,
    useCreateProposalMutation,
    useRevokeProposalMutation,
  }
}
