import { useQuery } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useUsageHistory(spaceIdRef: MaybeRef<string> | ComputedRef<string>) {
  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useUsageHistoryQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.usageHistory(spaceId.value).lists()),
      queryFn: async () => {
        const response = await spaceAPI.value.usage.history()
        return response.data
      },
      enabled: computed(() => !!spaceId.value),
      staleTime: 60_000,
      refetchOnWindowFocus: false,
    })
  }

  const useUsageTimeseriesQuery = (periodId: MaybeRef<string | null>) => {
    return useQuery({
      queryKey: computed(() =>
        queryKeys.usageHistory(spaceId.value).timeseries(unref(periodId) ?? '')
      ),
      queryFn: async () => {
        const response = await spaceAPI.value.usage.timeseries(unref(periodId)!)
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!unref(periodId)),
      staleTime: 60_000,
      refetchOnWindowFocus: false,
    })
  }

  return { useUsageHistoryQuery, useUsageTimeseriesQuery }
}
