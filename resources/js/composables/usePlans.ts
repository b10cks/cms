import { useQuery } from '@tanstack/vue-query'

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

  return { usePlansQuery }
}
