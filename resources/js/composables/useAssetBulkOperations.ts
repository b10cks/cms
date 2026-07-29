import { useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AssetsQueryParams } from '~/api/resources/assets'

import { queryKeys } from './useQueryClient'

export interface BulkDeleteConflict {
  asset: AssetResource
  linkedContentsCount: number
}

export interface BulkDeleteResult {
  deletedIds: string[]
  conflicts: BulkDeleteConflict[]
  failed: number
}

/**
 * Bulk asset operations that hit the API directly and invalidate the query
 * cache once, instead of going through the per-item mutations (which would
 * fire one toast per asset).
 */
export function useAssetBulkOperations(spaceId: MaybeRef<string>) {
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const invalidateAssetLists = () => {
    return queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
  }

  const bulkUpdateAssets = async (
    updates: { id: string; payload: UpdateAssetPayload }[]
  ): Promise<{ succeeded: number; failed: number }> => {
    const results = await Promise.allSettled(
      updates.map(({ id, payload }) => spaceAPI.value.assets.update(id, payload))
    )

    await invalidateAssetLists()

    const failed = results.filter((result) => result.status === 'rejected').length

    return { succeeded: results.length - failed, failed }
  }

  const bulkDeleteAssets = async (
    assets: AssetResource[],
    options: { force?: boolean } = {}
  ): Promise<BulkDeleteResult> => {
    const results = await Promise.allSettled(
      assets.map((asset) => spaceAPI.value.assets.delete(asset.id, { force: options.force }))
    )

    const deletedIds: string[] = []
    const conflicts: BulkDeleteConflict[] = []
    let failed = 0

    results.forEach((result, index) => {
      const asset = assets[index]

      if (result.status === 'fulfilled') {
        deletedIds.push(asset.id)
        return
      }

      const error = result.reason as {
        status?: number
        data?: { code?: string; linked_contents_count?: number }
      }

      if (error?.status === 409 && error?.data?.code === 'asset_in_use') {
        conflicts.push({ asset, linkedContentsCount: error.data.linked_contents_count ?? 0 })
      } else {
        failed += 1
      }
    })

    await invalidateAssetLists()

    return { deletedIds, conflicts, failed }
  }

  const fetchAllMatchingAssets = async (
    params: AssetsQueryParams,
    { maxItems = 2000 }: { maxItems?: number } = {}
  ): Promise<{ assets: AssetResource[]; truncated: boolean; total: number }> => {
    const assets: AssetResource[] = []
    let page = 1
    let total = 0
    let truncated = false

    for (;;) {
      const response = await spaceAPI.value.assets.index({
        ...params,
        page,
        per_page: 500,
      })

      assets.push(...response.data)
      total = response.meta?.total ?? assets.length

      const lastPage = response.meta?.last_page ?? page

      if (assets.length >= maxItems) {
        truncated = assets.length < total
        assets.length = Math.min(assets.length, maxItems)
        break
      }

      if (page >= lastPage) {
        break
      }

      page += 1
    }

    return { assets, truncated, total }
  }

  return {
    bulkDeleteAssets,
    bulkUpdateAssets,
    fetchAllMatchingAssets,
    invalidateAssetLists,
  }
}
