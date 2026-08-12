import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { RedirectsQueryParams } from '~/api/resources/redirects'
import { createCrudComposable } from '~/lib/crud-composable'
import { toastError, type Translate } from '~/lib/toast-error'

import { queryKeys } from './useQueryClient'

const useRedirectsCrud = createCrudComposable<
  RedirectResource,
  ApiCollectionResponse<RedirectResource>,
  RedirectsQueryParams,
  CreateRedirectPayload,
  UpdateRedirectPayload
>({
  i18nKey: 'redirects',
  keys: (spaceId) => queryKeys.redirects(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).redirects,
  defaultParams: { sort: '+source' },
  toastValues: (data) => ({ source: data.source }),
})

export function useRedirects(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const crud = useRedirectsCrud(spaceId)

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useResetRedirectStatsMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.redirects.reset(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.redirects(spaceId).detail(data.id),
        })
        toast.success(
          t('composables.redirects.resetStatsSuccess', { source: data.source }) as string
        )
      },
      onError: (error: Error) => toastError(t as Translate, 'composables.redirects.resetStatsError', error),
    })
  }

  const useExportRedirectsMutation = () => {
    return useMutation({
      mutationFn: async (params: RedirectsQueryParams & { as: RedirectImportExportFormat }) => {
        return spaceAPI.value.redirects.export(params)
      },
      onError: (error: Error) => toastError(t as Translate, 'composables.redirects.exportError', error),
    })
  }

  const useImportRedirectsMutation = () => {
    return useMutation({
      mutationFn: async ({ file, mode }: { file: File; mode: RedirectImportMode }) => {
        return spaceAPI.value.redirects.importData(file, mode)
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        toast.success(t('composables.redirects.importSuccess') as string)
      },
      onError: (error: Error) => toastError(t as Translate, 'composables.redirects.importError', error),
    })
  }

  return {
    // Queries
    useRedirectsQuery: crud.useListQuery,
    useRedirectQuery: crud.useDetailQuery,

    // Mutations
    useCreateRedirectMutation: crud.useCreateMutation,
    useUpdateRedirectMutation: crud.useUpdateMutation,
    useDeleteRedirectMutation: crud.useDeleteMutation,
    useResetRedirectStatsMutation,
    useExportRedirectsMutation,
    useImportRedirectsMutation,
  }
}
