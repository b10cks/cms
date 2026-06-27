import { useQuery } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useInvoices(spaceIdRef: MaybeRef<string> | ComputedRef<string>) {
  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useInvoicesQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.invoices(spaceId.value).lists()),
      queryFn: async () => {
        const response = await spaceAPI.value.invoices.index()
        return response.data
      },
      enabled: computed(() => !!spaceId.value),
      staleTime: 5 * 60_000,
      refetchOnWindowFocus: false,
    })
  }

  return { useInvoicesQuery }
}
