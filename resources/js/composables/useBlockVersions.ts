import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useBlockVersions(spaceId: MaybeRef<string>, blockId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))
  const versionsAPI = computed(() => spaceAPI.value.blockVersions(toValue(blockId)))

  const useBlockVersionsQuery = (params: MaybeRef<BlockVersionsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.blockVersions(spaceId, blockId).list(params)),
      queryFn: async () => {
        const response = await versionsAPI.value.index({
          ...toValue(params),
          sort: '-created_at',
        })
        return response.data
      },
    })
  }

  const useBlockVersionQuery = (versionId: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.blockVersions(spaceId, blockId).detail(versionId)),
      queryFn: async () => {
        const response = await versionsAPI.value.get(toValue(versionId))
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!blockId.value && !!versionId.value),
    })
  }

  const useUpdateBlockVersionMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateBlockVersionPayload }) => {
        const response = await versionsAPI.value.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.blockVersions(spaceId, blockId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.blockVersions(spaceId, blockId).detail(data.id),
        })
        toast.success(t('composables.blockVersions.updateSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.blockVersions.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRestoreBlockVersionMutation = () => {
    return useMutation({
      mutationFn: async (versionId: string) => {
        const data = await versionsAPI.value.restore(versionId)
        return data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.blockVersions(spaceId, blockId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.blocks(spaceId).detail(blockId),
        })
        toast.success(t('composables.blockVersions.restoreSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.blockVersions.restoreError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteBlockVersionMutation = () => {
    return useMutation({
      mutationFn: async (versionId: string) => {
        await versionsAPI.value.delete(versionId)
        return versionId
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.blockVersions(spaceId, blockId).lists(),
        })
        toast.success(t('composables.blockVersions.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.blockVersions.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useBlockVersionsQuery,
    useBlockVersionQuery,
    useUpdateBlockVersionMutation,
    useRestoreBlockVersionMutation,
    useDeleteBlockVersionMutation,
  }
}
