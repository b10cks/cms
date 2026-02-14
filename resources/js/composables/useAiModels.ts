import type { MaybeRefOrGetter } from 'vue'

import { useQuery, useQueryClient } from '@tanstack/vue-query'

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

export function useAiModels(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()

  const useModelsQuery = () => {
    return useQuery({
      queryKey: computed(() => ['ai-models', toValue(spaceId)]),
      queryFn: async (): Promise<GroupedModels> => {
        const id = toValue(spaceId)
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

  const useAiSettingsQuery = () => {
    return useQuery({
      queryKey: computed(() => ['ai-settings', toValue(spaceId)]),
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

  const toggleFavourite = async (modelId: string) => {
    const id = toValue(spaceId)
    if (!id) return

    const currentSettings = queryClient.getQueryData(['ai-settings', id]) as SpaceAiSettings | null
    const favourites = currentSettings?.favourites ?? []
    const newFavourites = favourites.includes(modelId)
      ? favourites.filter((f) => f !== modelId)
      : [...favourites, modelId]

    await apiClient.patch(`/mgmt/v1/spaces/${id}/ai-settings`, {
      favourites: newFavourites,
    })

    queryClient.invalidateQueries({ queryKey: ['ai-settings', id] })
    queryClient.invalidateQueries({ queryKey: ['ai-models', id] })
  }

  const setModel = async (modelId: string | null) => {
    const id = toValue(spaceId)
    if (!id) return

    await apiClient.patch(`/mgmt/v1/spaces/${id}/ai-settings`, {
      model: modelId,
    })

    queryClient.invalidateQueries({ queryKey: ['ai-settings', id] })
  }

  return {
    useAiSettingsQuery,
    toggleFavourite,
    setModel,
  }
}

export interface SpaceAiSettings {
  enabled: boolean
  model: string | null
  favourites: string[]
}
