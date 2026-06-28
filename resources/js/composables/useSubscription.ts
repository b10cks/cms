import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

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

  const useCheckoutMutation = () => {
    return useMutation({
      mutationFn: async (planId: string) => {
        return spaceAPI.value.subscriptions.checkout(planId)
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions(spaceId.value).all() })

        if (data.checkout_url) {
          window.location.href = data.checkout_url
        } else if (data.upgraded) {
          toast.success(t('composables.subscriptions.upgradeSuccess'))
        } else {
          toast.success(t('composables.subscriptions.planChanged'))
          queryClient.invalidateQueries({
            queryKey: queryKeys.subscriptions(spaceId.value).current(),
          })
        }
      },
      onError: (error: Error) => {
        const apiError = error as any
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
        return spaceAPI.value.subscriptions.reinit()
      },
      onSuccess: (data) => {
        if (data.checkout_url) {
          window.location.href = data.checkout_url
        } else {
          toast.error(t('composables.subscriptions.reinitNoUrl'))
        }
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.subscriptions.reinitError', { error: error.message || 'Unknown error' })
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

  return {
    useSubscriptionsQuery,
    useCurrentSubscriptionQuery,
    useCheckoutMutation,
    useReinitPaymentMutation,
    useCancelMutation,
  }
}
