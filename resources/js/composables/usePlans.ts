import { useQuery } from '@tanstack/vue-query'
import type { MaybeRef } from 'vue'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function usePlans() {
  const usePlansQuery = () => {
    return useQuery({
      queryKey: queryKeys.plans.lists(),
      queryFn: async () => {
        const response = await api.plans.index()
        return response.data
      },
      staleTime: 60 * 60 * 1000, // 1 hour — matches server cache
    })
  }

  // Space-scoped plan list: public plans plus custom (agency) plans granted to
  // the space. Use this wherever a space picks a plan to switch to.
  const useSpacePlansQuery = (spaceId: MaybeRef<string>) => {
    const id = computed(() => unref(spaceId))

    return useQuery({
      queryKey: computed(() => queryKeys.plans.forSpace(id.value)),
      queryFn: async () => {
        const response = await api.forSpace(id.value).subscriptions.plans()
        return response.data
      },
      enabled: computed(() => !!id.value),
      staleTime: 5 * 60 * 1000,
    })
  }

  return { usePlansQuery, useSpacePlansQuery }
}
