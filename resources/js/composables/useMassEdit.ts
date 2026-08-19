import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type {
  MassEditDocument,
  MassEditRowsParams,
  MassEditSavePayload,
  MassEditSaveResult,
} from '~/types/mass-edit'

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
   * Contents per save request. Each (content, language) pair writes a content version
   * server side, so one request has to stay well inside an HTTP timeout. The endpoint
   * rejects anything above 100.
   */
  const SAVE_CHUNK_SIZE = 25

  /** Progress of a chunked save, for the button label. Null while idle. */
  const saveProgress = ref<{ saved: number; total: number } | null>(null)

  const emptyResult = (): MassEditSaveResult => ({
    successes: [],
    changes: [],
    ignored_fields: [],
    errors: [],
    deleted: [],
    summary: { total_success: 0, total_changes: 0, total_errors: 0, total_deleted: 0 },
  })

  /**
   * Save a delta of edited cells (draft or publish), in chunks.
   *
   * A chunk that fails is recorded as an error against its contents rather than
   * aborting the run, so one bad content cannot strand the other 4000. The merged
   * result names every content that made it, which is what the caller uses to decide
   * which edits it may drop.
   */
  const useMassEditSaveMutation = () => {
    return useMutation({
      mutationFn: async (payload: MassEditSavePayload): Promise<MassEditSaveResult> => {
        const chunks: MassEditSavePayload['documents'][] = []
        for (let i = 0; i < payload.documents.length; i += SAVE_CHUNK_SIZE) {
          chunks.push(payload.documents.slice(i, i + SAVE_CHUNK_SIZE))
        }

        const merged = emptyResult()
        saveProgress.value = { saved: 0, total: payload.documents.length }

        try {
          for (const documents of chunks) {
            try {
              const result = await spaceAPI.value.massEdit.save({ ...payload, documents })
              merged.successes.push(...result.successes)
              merged.changes.push(...result.changes)
              merged.ignored_fields.push(...result.ignored_fields)
              merged.errors.push(...result.errors)
            } catch (error) {
              const message = error instanceof Error ? error.message : String(error)
              merged.errors.push(
                ...documents.map((document) => ({ content_id: document.content_id, message }))
              )
            }

            saveProgress.value = {
              saved: (saveProgress.value?.saved ?? 0) + documents.length,
              total: payload.documents.length,
            }
          }
        } finally {
          saveProgress.value = null
        }

        merged.ignored_fields = [...new Set(merged.ignored_fields)]
        merged.summary = {
          total_success: merged.successes.length,
          total_changes: merged.changes.length,
          total_errors: merged.errors.length,
          total_deleted: 0,
        }

        return merged
      },
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
    saveProgress,
  }
}
