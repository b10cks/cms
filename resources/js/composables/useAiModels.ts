import type { MaybeRefOrGetter } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

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

export function useAiConfigs(spaceId: MaybeRefOrGetter<string | null>) {
  const { client: apiClient } = useApiClient()
  const queryClient = useQueryClient()

  const useAiConfigsQuery = () => {
    return useQuery({
      queryKey: computed(() => ['ai-configs', toValue(spaceId)]),
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
      queryKey: computed(() => ['ai-config', toValue(spaceId), toValue(configId)]),
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
        const id = toValue(spaceId)
        if (id) {
          queryClient.invalidateQueries({ queryKey: ['ai-configs', id] })
        }
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
      onSuccess: (data) => {
        const id = toValue(spaceId)
        if (id) {
          queryClient.invalidateQueries({ queryKey: ['ai-configs', id] })
          queryClient.invalidateQueries({ queryKey: ['ai-config', id, data.id] })
        }
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
      onSuccess: () => {
        const id = toValue(spaceId)
        if (id) {
          queryClient.invalidateQueries({ queryKey: ['ai-configs', id] })
        }
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
