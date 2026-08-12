import { useMutation, useQuery } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { IconsQueryParams } from '~/api/resources/icons'
import { createCrudComposable } from '~/lib/crud-composable'
import { toastError, type Translate } from '~/lib/toast-error'

import { queryKeys } from './useQueryClient'

const useIconsCrud = createCrudComposable<
  IconResource,
  ApiCollectionResponse<IconResource>,
  IconsQueryParams,
  UploadIconPayload,
  UpdateIconPayload
>({
  i18nKey: 'icons',
  keys: (spaceId) => queryKeys.icons(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).icons,
  defaultParams: { sort: '+key' },
  toastValues: (data) => ({ name: data.name }),
  // The detail query was never gated here; components call it before an id resolves.
  detailGate: 'none',
  // The tag facet is derived from the icons themselves, so it goes stale with them.
  invalidateAlso: (spaceId) => [queryKeys.icons(spaceId).tags()],
})

export function useIcons(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const crud = useIconsCrud(spaceId)

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const invalidateLists = () => crud.invalidateLists('create')

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

  const useImportIconsMutation = () => {
    return useMutation({
      mutationFn: async ({ file, mode }: { file: File; mode: IconImportMode }) => {
        return spaceAPI.value.icons.importData(file, mode)
      },
      onSuccess: () => {
        invalidateLists()
        toast.success(t('composables.icons.importSuccess') as string)
      },
      onError: (error: Error) => toastError(t as Translate, 'composables.icons.importError', error),
    })
  }

  return {
    // Queries
    useIconsQuery: crud.useListQuery,
    useIconQuery: crud.useDetailQuery,
    useIconTagsQuery,

    // Mutations / actions
    uploadIcon,
    useUpdateIconMutation: crud.useUpdateMutation,
    useDeleteIconMutation: crud.useDeleteMutation,
    useImportIconsMutation,
  }
}
