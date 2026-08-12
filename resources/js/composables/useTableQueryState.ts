import type { ComputedRef, Ref, WatchSource } from 'vue'

export type TableSortDirection = 'asc' | 'desc'

export interface TableSort<TColumn extends string = string> {
  column: TColumn
  direction: TableSortDirection
}

export interface TableQueryParams extends Record<string, unknown> {
  page: number
  per_page: number
  sort: string
}

export interface TablePaginationBindings {
  currentPage: number
  perPage: number
  pageSizeOptions?: number[]
  'onUpdate:currentPage': (value: number) => void
  'onUpdate:perPage': (value: number) => void
}

export interface UseTableQueryStateOptions<TColumn extends string = string> {
  /**
   * Initial sort; encoded as the backend's `+column` / `-column` sort param.
   * `NoInfer` keeps `TColumn` at its `string` default unless the caller pins
   * it explicitly — otherwise every table would narrow to a single literal.
   */
  defaultSort: TableSort<NoInfer<TColumn>>
  /** Initial page size. Ignored when an external `perPage` ref is supplied. */
  pageSize?: number
  /** Options offered by the per-page select; omit to use its defaults. */
  pageSizeOptions?: number[]
  /** Externally owned page ref, e.g. `useRouteQuery('page', 1, …)`. */
  page?: Ref<number>
  /** Externally owned page-size ref, e.g. a persisted space setting. */
  perPage?: Ref<number>
  /** Extra sources (search terms, date ranges, …) that reset back to page 1. */
  resetOn?: WatchSource | WatchSource[]
  /** Reset to page 1 when the sort changes. Off by default. */
  resetOnSort?: boolean
  /** Reset to page 1 when the filters change. Off by default. */
  resetOnFilters?: boolean
  /** Reset to page 1 when the page size changes. Off by default. */
  resetOnPageSize?: boolean
}

export interface UseTableQueryStateReturn<TColumn extends string = string> {
  currentPage: Ref<number>
  perPage: Ref<number>
  pageSizeOptions?: number[]
  sortBy: Ref<TableSort<TColumn>>
  filters: Ref<Record<string, unknown>>
  sortParam: ComputedRef<string>
  queryParams: ComputedRef<TableQueryParams>
  paginationBindings: ComputedRef<TablePaginationBindings>
  resetPage: () => void
  setPage: (page: number) => void
  setPerPage: (perPage: number) => void
  setSortBy: (sort: TableSort<TColumn>) => void
  setFilters: (filters: Record<string, unknown>) => void
}

/**
 * Shared list-table query state: page, page size, sort and filters, plus the
 * `page` / `per_page` / `sort` params to spread into the list query.
 *
 * Page resets are opt-in so every call site keeps the exact behaviour it had
 * before it was migrated onto this composable.
 */
export function useTableQueryState<TColumn extends string = string>(
  options: UseTableQueryStateOptions<TColumn>
): UseTableQueryStateReturn<TColumn> {
  const {
    defaultSort,
    pageSize = 25,
    pageSizeOptions,
    resetOnSort = false,
    resetOnFilters = false,
    resetOnPageSize = false,
  } = options

  const currentPage = options.page ?? ref(1)
  const perPageState = options.perPage ?? ref(pageSize)
  const sortBy = ref({ ...defaultSort }) as Ref<TableSort<TColumn>>
  const filters = ref<Record<string, unknown>>({})

  const resetPage = () => {
    currentPage.value = 1
  }
  const setPage = (page: number) => {
    currentPage.value = page
  }
  const setPerPage = (value: number) => {
    perPageState.value = value
    if (resetOnPageSize) {
      resetPage()
    }
  }
  const setSortBy = (sort: TableSort<TColumn>) => {
    sortBy.value = sort
  }
  const setFilters = (value: Record<string, unknown>) => {
    filters.value = value
  }

  const perPage = computed({
    get: () => perPageState.value,
    set: setPerPage,
  })

  const sortParam = computed(
    () => `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`
  )

  const queryParams = computed<TableQueryParams>(() => ({
    ...filters.value,
    page: currentPage.value,
    per_page: perPageState.value,
    sort: sortParam.value,
  }))

  if (resetOnFilters) {
    watch(filters, resetPage, { deep: true })
  }
  if (resetOnSort) {
    watch(sortBy, resetPage, { deep: true })
  }
  if (options.resetOn) {
    watch(Array.isArray(options.resetOn) ? options.resetOn : [options.resetOn], resetPage, {
      deep: true,
    })
  }

  const paginationBindings = computed<TablePaginationBindings>(() => ({
    currentPage: currentPage.value,
    perPage: perPageState.value,
    pageSizeOptions,
    'onUpdate:currentPage': setPage,
    'onUpdate:perPage': setPerPage,
  }))

  return {
    currentPage,
    perPage,
    pageSizeOptions,
    sortBy,
    filters,
    sortParam,
    queryParams,
    paginationBindings,
    resetPage,
    setPage,
    setPerPage,
    setSortBy,
    setFilters,
  }
}
