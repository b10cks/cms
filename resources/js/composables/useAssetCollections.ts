import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetCollectionsQueryParams } from '~/api/resources/asset-collections'
import type { AssetsQueryParams } from '~/api/resources/assets'

import { queryKeys } from './useQueryClient'

export function useAssetCollections(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const invalidateCollectionAssets = (collectionId: string) => {
    queryClient.invalidateQueries({
      queryKey: queryKeys.assetCollections(spaceId).assets(collectionId),
    })
    queryClient.invalidateQueries({ queryKey: queryKeys.assetCollections(spaceId).lists() })
    queryClient.invalidateQueries({
      queryKey: queryKeys.assetCollections(spaceId).detail(collectionId),
    })
    // Collection asset pages fetched through the asset grid live under the
    // assets list key (with a `collection` param), so refresh those too.
    queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
  }

  const useAssetCollectionsQuery = (params: MaybeRef<AssetCollectionsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetCollections(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.assetCollections.index({
          sort: '+name',
          ...toValue(params),
        })
      },
    })
  }

  const useAssetCollectionQuery = (
    id: MaybeRef<string | null | undefined>,
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetCollections(spaceId).detail(toValue(id) ?? '')),
      queryFn: async () => {
        const response = await spaceAPI.value.assetCollections.get(toValue(id) ?? '')
        return response.data
      },
      enabled: computed(() => Boolean(toValue(id)) && toValue(enabled)),
    })
  }

  const useCollectionAssetsQuery = (
    collectionId: MaybeRef<string | null | undefined>,
    params: MaybeRef<AssetsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() =>
        queryKeys.assetCollections(spaceId).assetsList(toValue(collectionId) ?? '', params)
      ),
      queryFn: async () => {
        return await spaceAPI.value.assetCollections.getAssets(
          toValue(collectionId) ?? '',
          toValue(params)
        )
      },
      enabled: computed(() => Boolean(toValue(collectionId)) && toValue(enabled)),
    })
  }

  const useCreateAssetCollectionMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateAssetCollectionPayload) => {
        const response = await spaceAPI.value.assetCollections.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetCollections(spaceId).lists() })
        toast.success(t('composables.assetCollections.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetCollections.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateAssetCollectionMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        payload,
      }: {
        id: string
        payload: UpdateAssetCollectionPayload
      }) => {
        const response = await spaceAPI.value.assetCollections.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetCollections(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.assetCollections(spaceId).detail(data.id),
        })
        // Rules of a smart collection may have changed, so its asset list is stale.
        queryClient.invalidateQueries({
          queryKey: queryKeys.assetCollections(spaceId).assets(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        toast.success(t('composables.assetCollections.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetCollections.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAssetCollectionMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.assetCollections.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetCollections(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.assetCollections(spaceId).detail(id) })
        queryClient.removeQueries({ queryKey: queryKeys.assetCollections(spaceId).assets(id) })
        toast.success(t('composables.assetCollections.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetCollections.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useAddAssetsToCollectionMutation = () => {
    return useMutation({
      mutationFn: async ({
        collectionId,
        assetIds,
      }: {
        collectionId: string
        assetIds: string[]
      }) => {
        await spaceAPI.value.assetCollections.addAssets(collectionId, assetIds)
        return { collectionId, assetIds }
      },
      onSuccess: ({ collectionId, assetIds }) => {
        invalidateCollectionAssets(collectionId)
        toast.success(
          t('composables.assetCollections.addSuccess', { count: assetIds.length }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetCollections.addError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRemoveAssetsFromCollectionMutation = () => {
    return useMutation({
      mutationFn: async ({
        collectionId,
        assetIds,
      }: {
        collectionId: string
        assetIds: string[]
      }) => {
        await spaceAPI.value.assetCollections.removeAssets(collectionId, assetIds)
        return { collectionId, assetIds }
      },
      onSuccess: ({ collectionId, assetIds }) => {
        invalidateCollectionAssets(collectionId)
        toast.success(
          t('composables.assetCollections.removeSuccess', { count: assetIds.length }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetCollections.removeError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useReorderCollectionAssetsMutation = () => {
    return useMutation({
      mutationFn: async ({
        collectionId,
        assetIds,
      }: {
        collectionId: string
        assetIds: string[]
      }) => {
        await spaceAPI.value.assetCollections.reorderAssets(collectionId, assetIds)
        return { collectionId }
      },
      onSuccess: ({ collectionId }) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.assetCollections(spaceId).assets(collectionId),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetCollections.reorderError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useAssetCollectionsQuery,
    useAssetCollectionQuery,
    useCollectionAssetsQuery,

    // Mutations
    useCreateAssetCollectionMutation,
    useUpdateAssetCollectionMutation,
    useDeleteAssetCollectionMutation,
    useAddAssetsToCollectionMutation,
    useRemoveAssetsFromCollectionMutation,
    useReorderCollectionAssetsMutation,
  }
}
