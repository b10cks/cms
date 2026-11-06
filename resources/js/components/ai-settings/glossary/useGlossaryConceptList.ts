import { refDebounced } from '@vueuse/core'
import { useRouteQuery } from '@vueuse/router'

import type { AiGlossaryConceptRowFilters, AiOwner } from '~/api/resources/ai-knowledge'
import { useAiGlossaryConceptRows } from '~/composables/useAiGlossaryConceptRows'
import { useTableQueryState } from '~/composables/useTableQueryState'
import { hasActiveFilter } from '~/lib/glossaryConcepts'

export const NEW_CONCEPT = 'new'

const PAGE_SIZES = [25, 50, 100, 200]

type RouteValue = string | string[] | null | undefined

const firstOf = (value: RouteValue) => (Array.isArray(value) ? value[0] : value) ?? ''

const textParam = (name: string) => useRouteQuery<RouteValue, string>(name, '', { transform: firstOf })

const numberParam = (name: string, fallback: number) =>
  useRouteQuery<RouteValue, number>(name, String(fallback), {
    transform: {
      get: (value) => {
        const parsed = Number.parseInt(firstOf(value), 10)
        return parsed > 0 ? parsed : fallback
      },
      set: (value) => String(value),
    },
  })

/**
 * The list's state, kept in the URL so a view can be shared and survives a reload:
 * search, the missing filter, unfolded columns, paging, the open concept and the language tab.
 */
export function useGlossaryConceptList(owner: MaybeRefOrGetter<AiOwner>, glossaryId: MaybeRefOrGetter<string>) {
  const rowsApi = useAiGlossaryConceptRows(owner, glossaryId)

  const query = textParam('q')
  const missing = textParam('missing')
  const concept = textParam('concept')
  // Not `lang`: the unsaved changes guard reads that as a different document.
  const language = textParam('language')
  const columns = textParam('columns')
  const page = numberParam('page', 1)
  const perPage = numberParam('per_page', 50)

  const searchInput = ref(query.value)
  const debouncedSearch = refDebounced(searchInput, 250)
  watch(debouncedSearch, (value) => {
    query.value = value.trim()
  })
  watch(query, (value) => {
    if (value !== searchInput.value.trim()) searchInput.value = value
  })

  const table = useTableQueryState({
    defaultSort: { column: 'term', direction: 'asc' },
    page,
    perPage,
    pageSizeOptions: PAGE_SIZES,
    resetOn: [query, missing],
    resetOnPageSize: true,
  })

  const filters = computed<AiGlossaryConceptRowFilters>(() => ({
    search: query.value || undefined,
    missing: missing.value || undefined,
    page: page.value,
    per_page: perPage.value,
  }))

  const result = rowsApi.useRowsQuery(filters)
  const rows = computed(() => result.data.value?.data ?? [])
  const meta = computed(() => result.data.value?.meta ?? null)
  /** Filtering or paging waits on the server. A refetch after a save does not, so it doesn't dim. */
  const isChangingView = computed(() => result.isPlaceholderData.value && result.isFetching.value)
  const isFiltered = computed(() => hasActiveFilter(filters.value))

  watch(meta, (current) => {
    if (current && page.value > current.last_page && current.last_page > 0) page.value = current.last_page
  })

  /** Languages with no wording on this page fold away until someone asks for them. */
  const allColumns = computed({
    get: () => columns.value === 'all',
    set: (value: boolean) => {
      columns.value = value ? 'all' : ''
    },
  })

  const clearFilters = () => {
    searchInput.value = ''
    query.value = ''
    missing.value = ''
  }

  const toggleMissing = (code: string) => {
    missing.value = missing.value === code ? '' : code
  }

  const prefetchPage = (target: number) => {
    const last = meta.value?.last_page ?? 1
    return target >= 1 && target <= last ? rowsApi.prefetchRows({ ...filters.value, page: target }) : undefined
  }

  /** Moves to a page and waits until its rows are there. */
  const goToPage = async (target: number) => {
    await prefetchPage(target)
    page.value = target
    await nextTick()
  }

  return {
    rowsApi,
    searchInput,
    missing,
    concept,
    language,
    allColumns,
    page,
    perPage,
    table,
    filters,
    result,
    rows,
    meta,
    isChangingView,
    isFiltered,
    clearFilters,
    toggleMissing,
    prefetchPage,
    goToPage,
  }
}

export type GlossaryConceptList = ReturnType<typeof useGlossaryConceptList>
