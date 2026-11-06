import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type {
  AiGlossaryCheckPayload,
  AiGlossaryConceptBulkPayload,
  AiGlossaryConceptPayload,
  AiGlossaryConceptRowFilters,
  AiOwner,
} from '~/api/resources/ai-knowledge'

/**
 * One glossary as concept rows. Mutations leave error handling to the caller, because
 * a refused write names the concept in the way and needs more than a toast.
 */
export function useAiGlossaryConceptRows(owner: MaybeRefOrGetter<AiOwner>, glossaryId: MaybeRefOrGetter<string>) {
  const queryClient = useQueryClient()
  const keys = computed(() => queryKeys.aiKnowledge(toValue(owner)))
  const resource = () => api.aiKnowledge(toValue(owner))
  const rowsKey = (filters: AiGlossaryConceptRowFilters) =>
    [...keys.value.glossaryConceptRows(toValue(glossaryId)), { ...filters }] as const
  const fetchRows = (filters: AiGlossaryConceptRowFilters) => resource().conceptRows(toValue(glossaryId), filters)
  const invalidate = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.value.glossaryConceptRows(toValue(glossaryId)) }),
      queryClient.invalidateQueries({ queryKey: keys.value.glossaries() }),
    ])

  return {
    useRowsQuery: (filters: MaybeRefOrGetter<AiGlossaryConceptRowFilters>) =>
      useQuery({
        queryKey: computed(() => rowsKey(toValue(filters))),
        queryFn: () => fetchRows(toValue(filters)),
        placeholderData: keepPreviousData,
      }),

    /** A page ready in the cache, so moving to it shows no skeleton. */
    prefetchRows: (filters: AiGlossaryConceptRowFilters) =>
      queryClient.prefetchQuery({ queryKey: rowsKey(filters), queryFn: () => fetchRows(filters) }),

    /** One row, for a link to a concept the current page does not hold. */
    useRowQuery: (rowId: MaybeRefOrGetter<string | null>) =>
      useQuery({
        queryKey: computed(() => [...keys.value.glossaryConceptRows(toValue(glossaryId)), 'row', toValue(rowId)] as const),
        queryFn: async () => (await resource().conceptRow(toValue(glossaryId), toValue(rowId) ?? '')).data,
        enabled: computed(() => !!toValue(rowId)),
      }),

    /**
     * Another concept that holds this exact wording in this language. Search also finds
     * parts and other wordings, so the exact match is picked here; the server has the last word
     * with its 409.
     */
    useWordingHolderQuery: (check: MaybeRefOrGetter<{ locale: string; wording: string; rowId: string | null } | null>) =>
      useQuery({
        queryKey: computed(() => {
          const value = toValue(check)
          return [...keys.value.glossaryConceptRows(toValue(glossaryId)), 'holder', value?.locale, value?.wording, value?.rowId] as const
        }),
        queryFn: async () => {
          const value = toValue(check)
          if (!value) return null
          const folded = value.wording.toLocaleLowerCase()
          const { data } = await fetchRows({ search: value.wording, per_page: 200 })
          return (
            data.find(
              (row) => {
                const language = row.languages[value.locale]
                return row.id !== value.rowId && (language?.use ?? language?.avoid[0])?.toLocaleLowerCase() === folded
              }
            ) ?? null
          )
        },
        enabled: computed(() => !!toValue(check)),
      }),

    /** One row as stored now, after a save found it had moved. The list refreshes with it. */
    refreshRow: async (rowId: string) => {
      void invalidate()
      return (await resource().conceptRow(toValue(glossaryId), rowId)).data
    },

    useCreateRowMutation: () =>
      useMutation({
        mutationFn: async (payload: AiGlossaryConceptPayload) =>
          (await resource().createConceptRow(toValue(glossaryId), payload)).data,
        onSuccess: invalidate,
      }),

    useUpdateRowMutation: () =>
      useMutation({
        mutationFn: async ({ rowId, payload }: { rowId: string; payload: AiGlossaryConceptPayload }) =>
          (await resource().updateConceptRow(toValue(glossaryId), rowId, payload)).data,
        onSuccess: invalidate,
      }),

    useDeleteRowMutation: () =>
      useMutation({
        mutationFn: (rowId: string) => resource().deleteConceptRow(toValue(glossaryId), rowId),
        onSuccess: invalidate,
      }),

    useBulkMutation: () =>
      useMutation({
        mutationFn: async (payload: AiGlossaryConceptBulkPayload) =>
          (await resource().bulkConceptRows(toValue(glossaryId), payload)).data,
        onSuccess: invalidate,
      }),

    /** Tries a text. Makes no provider call and changes nothing, so it invalidates nothing. */
    useCheckMutation: () =>
      useMutation({
        mutationFn: async (payload: AiGlossaryCheckPayload) =>
          (await resource().checkGlossaryText(toValue(glossaryId), payload)).data,
      }),
  }
}
