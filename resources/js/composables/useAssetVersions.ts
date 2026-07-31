import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetVersionsQueryParams } from '~/api/resources/asset-versions'

import { queryKeys } from './useQueryClient'

export function useAssetVersions(
  spaceId: MaybeRef<string>,
  assetId: MaybeRef<string | null | undefined>
) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))
  const resolvedAssetId = computed(() => toValue(assetId) || '')
  const versionsAPI = computed(() => spaceAPI.value.assetVersions(resolvedAssetId.value))
  const hasAssetId = computed(() => !!toValue(assetId))

  const invalidateVersionQueries = () => {
    queryClient.invalidateQueries({
      queryKey: queryKeys.assetVersions(spaceId, resolvedAssetId.value).lists(),
    })
    queryClient.invalidateQueries({
      queryKey: queryKeys.assets(spaceId).lists(),
    })
    queryClient.invalidateQueries({
      queryKey: queryKeys.assets(spaceId).detail(resolvedAssetId.value),
    })
  }

  const useAssetVersionsQuery = (
    params: MaybeRef<AssetVersionsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() =>
        queryKeys.assetVersions(spaceId, resolvedAssetId.value).list(toValue(params))
      ),
      queryFn: async () => {
        return await versionsAPI.value.index({
          sort: '-version_number',
          ...toValue(params),
        })
      },
      enabled: computed(() => hasAssetId.value && toValue(enabled)),
      placeholderData: keepPreviousData,
    })
  }

  const useRestoreAssetVersionMutation = () => {
    return useMutation({
      mutationFn: async (versionId: string) => {
        // Without an asset the request would POST to `/assets//versions/{id}/restore`.
        if (!hasAssetId.value) throw new Error('No asset selected')
        return await versionsAPI.value.restore(versionId)
      },
      onSuccess: (asset) => {
        invalidateVersionQueries()
        toast.success(t('composables.assetVersions.restoreSuccess') as string)

        return asset
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetVersions.restoreError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useAssetVersionsQuery,

    // Mutations
    useRestoreAssetVersionMutation,
  }
}
