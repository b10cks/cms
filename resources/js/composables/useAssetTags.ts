import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { AssetTagsQueryParams } from '~/api/resources/asset-tags'
import type { UpsertAssetTagPayload } from '~/types/assets'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useAssetTags(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useAssetTagsQuery = (params: MaybeRef<AssetTagsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetTags(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.assetTags.index({
          ...toValue(params),
          sort: '+name', // Default sorting
        })
        return response.data
      },
    })
  }

  // Query to fetch a single asset tag
  const useAssetTagQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetTags(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.assetTags.get(toValue(id))
        return response.data
      },
    })
  }

  // Mutation to create an asset tag
  const useCreateAssetTagMutation = () => {
    return useMutation({
      mutationFn: async (payload: UpsertAssetTagPayload) => {
        const response = await spaceAPI.value.assetTags.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        // Invalidate the asset tags list query to trigger a refetch
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

  // Mutation to update an asset tag
  const useUpdateAssetTagMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpsertAssetTagPayload }) => {
        const response = await spaceAPI.value.assetTags.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        // Invalidate the asset tags list and the specific tag detail
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

  // Mutation to delete an asset tag
  const useDeleteAssetTagMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.assetTags.delete(id)
        return id
      },
      onSuccess: (id) => {
        // Invalidate the asset tags list and remove the deleted tag from cache
        queryClient.invalidateQueries({ queryKey: queryKeys.assetTags(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.assetTags(spaceId).detail(id) })
        // Also invalidate assets that might have used this tag
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

  // Helper to find a tag by slug
  const useTagBySlug = (slug: MaybeRef<string>) => {
    const { data: tags, isLoading } = useAssetTagsQuery({})

    const tag = computed(() => {
      if (!tags.value) return null
      return tags.value.find((t) => t.slug === toValue(slug)) || null
    })

    return {
      tag,
      isLoading,
    }
  }

  // Query to fetch assets for a specific tag
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
    // Queries
    useAssetTagsQuery,
    useAssetTagQuery,
    useAssetsForTagQuery,

    // Helpers
    useTagBySlug,

    // Mutations
    useCreateAssetTagMutation,
    useUpdateAssetTagMutation,
    useDeleteAssetTagMutation,
  }
}
