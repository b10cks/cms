import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetTagsQueryParams } from '~/api/resources/asset-tags'
import { createCrudComposable } from '~/lib/crud-composable'
import { toastError, type Translate } from '~/lib/toast-error'

import { queryKeys } from './useQueryClient'

const useAssetTagsCrud = createCrudComposable<
  AssetTagResource,
  ApiCollectionResponse<AssetTagResource>,
  AssetTagsQueryParams,
  UpsertAssetTagPayload,
  UpsertAssetTagPayload
>({
  i18nKey: 'assetTags',
  keys: (spaceId) => queryKeys.assetTags(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).assetTags,
  defaultParams: { sort: '+name' },
  toastValues: (data) => ({ name: data.name }),
  // The detail query was never gated here; components call it before an id resolves.
  detailGate: 'none',
  // Assets embed the tag's label and colour, so every cached list is stale.
  invalidateAlso: (spaceId, operation) =>
    operation === 'create' ? [] : [queryKeys.assets(spaceId).lists()],
})

export function useAssetTags(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const crud = useAssetTagsCrud(spaceId)

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

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
      onError: (error: Error) =>
        toastError(t as Translate, 'composables.assetTags.assignError', error),
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
    useAssetTagsQuery: crud.useListQuery,
    useAssetTagQuery: crud.useDetailQuery,
    useAssetsForTagQuery,
    useCreateAssetTagMutation: crud.useCreateMutation,
    useUpdateAssetTagMutation: crud.useUpdateMutation,
    useDeleteAssetTagMutation: crud.useDeleteMutation,
    useAssignTagToAssetsMutation,
  }
}
