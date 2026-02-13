import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { DataSourcesQueryParams } from '~/api/resources/data-sources'
import type { CreateDataSourcePayload, UpdateDataSourcePayload } from '~/types/data-sources'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useDataSources(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  // Get the API instance for this space
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  /**
   * Query all data sources in a space
   */
  const useDataSourcesQuery = (params: MaybeRef<DataSourcesQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.dataSources(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.dataSources.index({
          sort: '+name',
          ...toValue(params),
        })
      },
    })
  }

  /**
   * Query a single data source by ID
   */
  const useDataSourceQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.dataSources(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.dataSources.get(toValue(id))
        return response.data
      },
    })
  }

  /**
   * Create a new data source
   */
  const useCreateDataSourceMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateDataSourcePayload) => {
        const response = await spaceAPI.value.dataSources.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.dataSources(spaceId).lists() })
        toast.success(t('composables.dataSources.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataSources.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /**
   * Update an existing data source
   */
  const useUpdateDataSourceMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateDataSourcePayload }) => {
        const response = await spaceAPI.value.dataSources.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.dataSources(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataSources(spaceId).detail(data.id),
        })
        toast.success(t('composables.dataSources.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataSources.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /**
   * Delete a data source
   */
  const useDeleteDataSourceMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.dataSources.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.dataSources(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.dataSources(spaceId).detail(id) })
        toast.success(t('composables.dataSources.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataSources.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useDataSourcesQuery,
    useDataSourceQuery,

    // Mutations
    useCreateDataSourceMutation,
    useUpdateDataSourceMutation,
    useDeleteDataSourceMutation,
  }
}
