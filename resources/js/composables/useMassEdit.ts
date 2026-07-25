import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { MassEditDocument, MassEditRowsParams, MassEditSavePayload } from '~/types/mass-edit'

import { queryKeys } from './useQueryClient'

export function useMassEdit(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  /**
   * All translatable fields available for mass editing, aggregated across blocks.
   */
  const useMassEditFieldsQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.massEdit(spaceId).fields()),
      queryFn: async () => {
        const response = await spaceAPI.value.massEdit.getFields()
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId)),
    })
  }

  /**
   * Paginated grid rows for the selected fields/languages.
   */
  const useMassEditRowsQuery = (
    params: MaybeRef<MassEditRowsParams>,
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.massEdit(spaceId).rows(toValue(params))),
      queryFn: async () => spaceAPI.value.massEdit.getRows(toValue(params)),
      enabled: computed(() => !!toValue(spaceId) && !!toValue(enabled)),
      placeholderData: keepPreviousData,
    })
  }

  /**
   * Every row matching the current selection, not just the visible page — used by the
   * AI translation run so it covers the whole result set. Stops at `maxPages` and
   * reports it, rather than paging forever through an unbounded space.
   */
  const fetchAllRows = async (
    params: MassEditRowsParams,
    maxPages = 50
  ): Promise<{ documents: MassEditDocument[]; truncated: boolean }> => {
    const documents: MassEditDocument[] = []
    let page = 1
    let lastPage = 1

    do {
      const response = await spaceAPI.value.massEdit.getRows({ ...params, page, per_page: 100 })
      documents.push(...response.data)
      lastPage = response.meta?.last_page ?? 1
      page += 1
    } while (page <= lastPage && page <= maxPages)

    return { documents, truncated: lastPage > maxPages }
  }

  /**
   * Save a delta of edited cells (draft or publish).
   */
  const useMassEditSaveMutation = () => {
    return useMutation({
      mutationFn: async (payload: MassEditSavePayload) => spaceAPI.value.massEdit.save(payload),
      onSuccess: (result) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.massEdit(spaceId).all() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })

        if (result.summary.total_errors > 0) {
          toast.warning(
            t('composables.massEdit.savePartial', {
              saved: result.summary.total_success,
              errors: result.summary.total_errors,
            }) as string
          )
        } else {
          toast.success(
            t('composables.massEdit.saveSuccess', result.summary.total_success) as string
          )
        }
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.massEdit.saveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useMassEditFieldsQuery,
    useMassEditRowsQuery,
    useMassEditSaveMutation,
    fetchAllRows,
  }
}
