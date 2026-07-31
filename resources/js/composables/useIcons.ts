import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { IconsQueryParams } from '~/api/resources/icons'

import { queryKeys } from './useQueryClient'

export function useIcons(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const invalidateLists = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.icons(spaceId).lists() })
    queryClient.invalidateQueries({ queryKey: queryKeys.icons(spaceId).tags() })
  }

  const useIconsQuery = (params: MaybeRef<IconsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.icons(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.icons.index({
          sort: '+key',
          ...toValue(params),
        })
        return response
      },
      enabled: computed(() => Boolean(toValue(spaceId))),
      placeholderData: keepPreviousData,
    })
  }

  const useIconQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.icons(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.icons.get(toValue(id))
        return response.data
      },
    })
  }

  const useIconTagsQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.icons(spaceId).tags()),
      queryFn: async () => {
        const response = await spaceAPI.value.icons.tags()
        return response.data
      },
    })
  }

  /**
   * Upload a single icon, optionally reporting progress. Returns the created IconResource.
   */
  const uploadIcon = async (
    payload: UploadIconPayload,
    onProgress?: (progress: number) => void
  ): Promise<IconResource> => {
    const response = await spaceAPI.value.icons.upload(payload, onProgress)
    invalidateLists()
    return response.data
  }

  const useUpdateIconMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateIconPayload }) => {
        const response = await spaceAPI.value.icons.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        invalidateLists()
        queryClient.invalidateQueries({ queryKey: queryKeys.icons(spaceId).detail(data.id) })
        toast.success(t('composables.icons.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.icons.updateError', { error: error.message || 'Unknown error' }) as string
        )
      },
    })
  }

  const useDeleteIconMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.icons.delete(id)
        return id
      },
      onSuccess: (id) => {
        invalidateLists()
        queryClient.removeQueries({ queryKey: queryKeys.icons(spaceId).detail(id) })
        toast.success(t('composables.icons.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.icons.deleteError', { error: error.message || 'Unknown error' }) as string
        )
      },
    })
  }

  const useImportIconsMutation = () => {
    return useMutation({
      mutationFn: async ({ file, mode }: { file: File; mode: IconImportMode }) => {
        return spaceAPI.value.icons.importData(file, mode)
      },
      onSuccess: () => {
        invalidateLists()
        toast.success(t('composables.icons.importSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.icons.importError', { error: error.message || 'Unknown error' }) as string
        )
      },
    })
  }

  return {
    // Queries
    useIconsQuery,
    useIconQuery,
    useIconTagsQuery,

    // Mutations / actions
    uploadIcon,
    useUpdateIconMutation,
    useDeleteIconMutation,
    useImportIconsMutation,
  }
}
