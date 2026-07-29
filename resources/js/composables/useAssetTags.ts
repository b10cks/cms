import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetTagsQueryParams } from '~/api/resources/asset-tags'

import { queryKeys } from './useQueryClient'

export function useAssetTags(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useAssetTagsQuery = (params: MaybeRef<AssetTagsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetTags(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.assetTags.index({
          sort: '+name',
          ...toValue(params),
        })
      },
      placeholderData: keepPreviousData,
    })
  }

  const useAssetTagQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetTags(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.assetTags.get(toValue(id))
        return response.data
      },
    })
  }

  const useCreateAssetTagMutation = () => {
    return useMutation({
      mutationFn: async (payload: UpsertAssetTagPayload) => {
        const response = await spaceAPI.value.assetTags.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetTags(spaceId).lists() })
        toast.success(t('composables.assetTags.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetTags.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateAssetTagMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpsertAssetTagPayload }) => {
        const response = await spaceAPI.value.assetTags.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetTags(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.assetTags(spaceId).detail(data.id),
        })
        toast.success(t('composables.assetTags.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetTags.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAssetTagMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.assetTags.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetTags(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.assetTags(spaceId).detail(id) })
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        toast.success(t('composables.assetTags.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetTags.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useAssignTagToAssetsMutation = () => {
    return useMutation({
      mutationFn: async ({ tagId, assetIds }: { tagId: string; assetIds: string[] }) => {
        await spaceAPI.value.assetTags.assign(tagId, assetIds)
        return { tagId, assetIds }
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.assetTags(spaceId).lists() })
        toast.success(t('composables.assetTags.assignSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetTags.assignError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useAssetsForTagQuery = (tagId: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => [...queryKeys.assets(spaceId).lists(), { tag: toValue(tagId) }]),
      queryFn: async () => {
        const response = await spaceAPI.value.assets.index({
          tags: [toValue(tagId)],
        })
        return response.data
      },
    })
  }

  return {
    useAssetTagsQuery,
    useAssetTagQuery,
    useAssetsForTagQuery,
    useCreateAssetTagMutation,
    useUpdateAssetTagMutation,
    useDeleteAssetTagMutation,
    useAssignTagToAssetsMutation,
  }
}
