import { useQuery } from '@tanstack/vue-query'
import type { MaybeRefOrGetter } from 'vue'

export function useSpaceUsage(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()

  const useUsageQuery = () => {
    return useQuery({
      queryKey: computed(() => ['space-usage', toValue(spaceId)]),
      queryFn: async (): Promise<SpaceUsage | null> => {
        const id = toValue(spaceId)
        if (!id) return null

        const response = await apiClient.get<{ data: SpaceUsage }>(`/mgmt/v1/spaces/${id}/usage`)
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId)),
      staleTime: 60_000,
      refetchOnWindowFocus: false,
    })
  }

  return {
    useUsageQuery,
  }
}
