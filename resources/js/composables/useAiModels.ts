import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { MaybeRefOrGetter } from 'vue'
import { toast } from 'vue-sonner'

import type { SpaceAiConfig } from '~/api/resources/ai'

export interface AiModel {
  id: string
  full_id: string
  name: string
  driver: string
  description: string | null
  context_window: {
    input: number
    output: number
  }
  input_cost: number
  output_cost: number
  capabilities: string[]
  supports_streaming: boolean
  supports_tools: boolean
  supports_vision: boolean
  is_favourite: boolean
}

export interface GroupedModels {
  [driver: string]: AiModel[]
}

/**
 * `queryKeys.ai` takes a resolved id; every key below is rebuilt inside a
 * `computed`, so reading the id here keeps the key reactive. A null space only
 * ever reaches a disabled query, whose key is never observed.
 */
const aiKeys = (spaceId: MaybeRefOrGetter<string | null>) =>
  queryKeys.ai(toValue(spaceId) as string)

export function useAiModels(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()

  const useModelsQuery = () => {
    return useQuery({
      queryKey: computed(() => aiKeys(spaceId).models()),
      queryFn: async (): Promise<GroupedModels> => {
        const id = toValue(spaceId)
        // `enabled` already blocks this, but an explicit `refetch()` ignores it.
        if (!id) return {}

        const response = await apiClient.get<{ data: GroupedModels }>(
          `/mgmt/v1/ai/models?spaceId=${id}`
        )
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId)),
    })
  }

  return {
    useModelsQuery,
  }
}

export function useAiSettings(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const useAiSettingsQuery = () => {
    return useQuery({
      queryKey: computed(() => aiKeys(spaceId).settings()),
      queryFn: async () => {
        const id = toValue(spaceId)
        if (!id) return null

        const response = await apiClient.get<{ data: { ai: SpaceAiSettings } }>(
          `/mgmt/v1/spaces/${id}/ai-settings`
        )
        return response.data.ai
      },
      enabled: computed(() => !!toValue(spaceId)),
    })
  }

  // One mutation for both writers: they PATCH the same endpoint, so this is the
  // single place that owns the pending flag, the error surface and the
  // invalidation set. `is_favourite` is denormalised onto every model, so the
  // model list has to be refreshed alongside the settings.
  const settingsMutation = useMutation<void, Error, Partial<SpaceAiSettings>>({
    mutationFn: async (payload: Partial<SpaceAiSettings>) => {
      const id = toValue(spaceId)
      if (!id) throw new Error('No space ID')

      await apiClient.patch(`/mgmt/v1/spaces/${id}/ai-settings`, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: aiKeys(spaceId).settings() })
      queryClient.invalidateQueries({ queryKey: aiKeys(spaceId).models() })
    },
  })

  const toggleFavourite = async (modelId: string) => {
    const id = toValue(spaceId)
    if (!id) return

    const key = aiKeys(id).settings()
    const previous = queryClient.getQueryData<SpaceAiSettings | null>(key)
    const favourites = previous?.favourites ?? []
    const nextFavourites = favourites.includes(modelId)
      ? favourites.filter((favourite) => favourite !== modelId)
      : [...favourites, modelId]

    // Written back before the request so a second toggle fired before the
    // refetch lands reads this list rather than the pre-toggle snapshot, which
    // would silently undo the first toggle.
    if (previous) {
      queryClient.setQueryData<SpaceAiSettings>(key, { ...previous, favourites: nextFavourites })
    }

    try {
      await settingsMutation.mutateAsync({ favourites: nextFavourites })
    } catch (error) {
      if (previous) queryClient.setQueryData<SpaceAiSettings>(key, previous)
      toast.error(t('components.aiModelSelector.favouriteError') as string)
      throw error
    }
  }

  const setModel = async (modelId: string | null) => {
    const id = toValue(spaceId)
    if (!id) return

    try {
      await settingsMutation.mutateAsync({ model: modelId })
    } catch (error) {
      toast.error(t('components.aiModelSelector.setModelError') as string)
      throw error
    }
  }

  return {
    useAiSettingsQuery,
    settingsMutation,
    isSavingSettings: settingsMutation.isPending,
    toggleFavourite,
    setModel,
  }
}

export function useAiConfigs(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()
  const queryClient = useQueryClient()

  const useAiConfigsQuery = () => {
    return useQuery({
      queryKey: computed(() => aiKeys(spaceId).configs()),
      queryFn: async (): Promise<SpaceAiConfig[]> => {
        const id = toValue(spaceId)
        if (!id) return []

        const response = await apiClient.get<{ data: SpaceAiConfig[] }>(
          `/mgmt/v1/spaces/${id}/ai-configs`
        )
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId)),
    })
  }

  const useAiConfigQuery = (configId: MaybeRefOrGetter<string | null>) => {
    return useQuery({
      queryKey: computed(() => aiKeys(spaceId).config(toValue(configId) as string)),
      queryFn: async (): Promise<SpaceAiConfig | null> => {
        const id = toValue(spaceId)
        const cId = toValue(configId)
        if (!id || !cId) return null

        const response = await apiClient.get<{ data: SpaceAiConfig }>(
          `/mgmt/v1/spaces/${id}/ai-configs/${cId}`
        )
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(configId)),
    })
  }

  const useCreateAiConfigMutation = () => {
    return useMutation({
      mutationFn: async (payload: Omit<SpaceAiConfig, 'id' | 'created_at' | 'updated_at'>) => {
        const id = toValue(spaceId)
        if (!id) throw new Error('No space ID')

        const response = await apiClient.post<{ data: SpaceAiConfig }>(
          `/mgmt/v1/spaces/${id}/ai-configs`,
          payload
        )
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: aiKeys(spaceId).configs() })
      },
    })
  }

  const useUpdateAiConfigMutation = () => {
    return useMutation({
      mutationFn: async ({
        configId,
        payload,
      }: {
        configId: string
        payload: Partial<SpaceAiConfig>
      }) => {
        const id = toValue(spaceId)
        if (!id) throw new Error('No space ID')

        const response = await apiClient.patch<{ data: SpaceAiConfig }>(
          `/mgmt/v1/spaces/${id}/ai-configs/${configId}`,
          payload
        )
        return response.data
      },
      // Keyed off the id that was patched, not `data.id`: the detail view is
      // reading the requested id, and a response that disagrees would leave it
      // stale forever.
      onSuccess: (_data, { configId }) => {
        queryClient.invalidateQueries({ queryKey: aiKeys(spaceId).configs() })
        queryClient.invalidateQueries({ queryKey: aiKeys(spaceId).config(configId) })
      },
    })
  }

  const useDeleteAiConfigMutation = () => {
    return useMutation({
      mutationFn: async (configId: string) => {
        const id = toValue(spaceId)
        if (!id) throw new Error('No space ID')

        await apiClient.delete(`/mgmt/v1/spaces/${id}/ai-configs/${configId}`)
      },
      onSuccess: (_data, configId) => {
        queryClient.invalidateQueries({ queryKey: aiKeys(spaceId).configs() })
        // Removed, not invalidated: the config is gone, so a detail route
        // revisited within `gcTime` must not render it from the cache.
        queryClient.removeQueries({ queryKey: aiKeys(spaceId).config(configId) })
      },
    })
  }

  return {
    useAiConfigsQuery,
    useAiConfigQuery,
    useCreateAiConfigMutation,
    useUpdateAiConfigMutation,
    useDeleteAiConfigMutation,
  }
}

export interface SpaceAiSettings {
  enabled: boolean
  model: string | null
  favourites: string[]
}

export interface SpaceAiUsage {
  provider: string
  unit: 'usd' | 'tokens'
  available: boolean
  unlimited: boolean
  live: boolean
  used: number
  limit: number | null
  remaining: number | null
  percentage: number
  reset: string | null
  resets_at: string | null
  breakdown: { daily: number; weekly: number; monthly: number } | null
  message: string | null
}

export function useAiUsage(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()

  // When set, the next fetch bypasses the server-side usage cache so the
  // refresh button always pulls a fresh snapshot from the provider.
  const forceRefresh = ref(false)

  const useAiUsageQuery = () => {
    const query = useQuery({
      queryKey: computed(() => aiKeys(spaceId).usage()),
      queryFn: async (): Promise<SpaceAiUsage | null> => {
        const id = toValue(spaceId)
        if (!id) return null

        const response = await apiClient.get<{ data: SpaceAiUsage }>(
          `/mgmt/v1/spaces/${id}/ai-usage${forceRefresh.value ? '?refresh=1' : ''}`
        )
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId)),
      staleTime: 60_000,
      refetchOnWindowFocus: false,
    })

    // The flag is one-shot, but clearing it inside the fetcher would let a
    // retried attempt consume it and quietly fall back to the cached endpoint.
    // Clear it once the fetch has settled instead; `sync` so a caller that
    // awaits `refetch()` observes it reset.
    watch(
      () => query.isFetching.value,
      (isFetching, wasFetching) => {
        if (wasFetching && !isFetching) forceRefresh.value = false
      },
      { flush: 'sync' }
    )

    return query
  }

  return {
    useAiUsageQuery,
    forceRefresh,
  }
}
