import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { ApiResponse } from '~/types'
import type {
  CreateDataEntryPayload,
  DataEntryQueryParams,
  DataEntryResource,
  UpdateDataEntryPayload,
} from '~/types/data-sources'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useDataEntries(spaceId: MaybeRef<string>, dataSourceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  // Get the API instance for this space
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  /**
   * Query all entries in a data source
   */
  const useDataEntriesQuery = (params: MaybeRef<DataEntryQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.dataEntries(spaceId, dataSourceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.dataSources.getEntries(toValue(dataSourceId), {
          sort: '+key',
          ...toValue(params),
        })
        return response
      },
    })
  }

  /**
   * Query a single data entry by ID
   */
  const useDataEntryQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.dataEntries(spaceId, dataSourceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.dataSources.getEntry(
          toValue(dataSourceId),
          toValue(id)
        )
        return response.data
      },
    })
  }

  /**
   * Create a new data entry
   */
  const useCreateDataEntryMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateDataEntryPayload) => {
        const response = await spaceAPI.value.dataSources.createEntry(
          toValue(dataSourceId),
          payload
        )
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataEntries(spaceId, dataSourceId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataSources(spaceId).detail(dataSourceId),
        })
        toast.success(t('composables.dataEntries.createSuccess', { key: data.key }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataEntries.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /**
   * Update an existing data entry
   */
  const useUpdateDataEntryMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateDataEntryPayload }) => {
        const response = await spaceAPI.value.dataSources.updateEntry(
          toValue(dataSourceId),
          id,
          payload
        )
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataEntries(spaceId, dataSourceId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataEntries(spaceId, dataSourceId).detail(data.id),
        })
        toast.success(t('composables.dataEntries.updateSuccess', { key: data.key }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataEntries.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /**
   * Delete a data entry
   */
  const useDeleteDataEntryMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.dataSources.deleteEntry(toValue(dataSourceId), id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataEntries(spaceId, dataSourceId).lists(),
        })
        queryClient.removeQueries({
          queryKey: queryKeys.dataEntries(spaceId, dataSourceId).detail(id),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataSources(spaceId).detail(dataSourceId),
        })
        toast.success(t('composables.dataEntries.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataEntries.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /**
   * Batch update or create data entries
   */
  const useBatchUpdateEntriesMutation = () => {
    return useMutation({
      mutationFn: async (entries: CreateDataEntryPayload[]) => {
        const response = await spaceAPI.value.dataSources.custom<ApiResponse<DataEntryResource[]>>(
          'POST',
          `${toValue(dataSourceId)}/entries/batch`,
          { entries }
        )
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataEntries(spaceId, dataSourceId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.dataSources(spaceId).detail(dataSourceId),
        })
        toast.success(t('composables.dataEntries.batchUpdateSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.dataEntries.batchUpdateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useDataEntriesQuery,
    useDataEntryQuery,

    // Mutations
    useCreateDataEntryMutation,
    useUpdateDataEntryMutation,
    useDeleteDataEntryMutation,
    useBatchUpdateEntriesMutation,
  }
}
