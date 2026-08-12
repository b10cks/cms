import { afterEach, describe, expect, it } from 'vitest'
import { effectScope, type EffectScope, nextTick, ref } from 'vue'

import {
  useTableQueryState,
  type UseTableQueryStateOptions,
} from '~/composables/useTableQueryState'

const scopes: EffectScope[] = []

const run = (options: UseTableQueryStateOptions) => {
  const scope = effectScope()
  scopes.push(scope)

  return scope.run(() => useTableQueryState(options)) as ReturnType<typeof useTableQueryState>
}

afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop())
})

describe('sort encoding', () => {
  it('prefixes ascending sorts with +', () => {
    const { sortParam, queryParams } = run({ defaultSort: { column: 'name', direction: 'asc' } })

    expect(sortParam.value).toBe('+name')
    expect(queryParams.value.sort).toBe('+name')
  })

  it('prefixes descending sorts with -', () => {
    const { sortParam } = run({ defaultSort: { column: 'created_at', direction: 'desc' } })

    expect(sortParam.value).toBe('-created_at')
  })

  it('re-encodes when the sort changes', async () => {
    const { sortBy, setSortBy, sortParam } = run({
      defaultSort: { column: 'name', direction: 'asc' },
    })

    setSortBy({ column: 'size', direction: 'desc' })
    await nextTick()
    expect(sortParam.value).toBe('-size')

    sortBy.value.direction = 'asc'
    await nextTick()
    expect(sortParam.value).toBe('+size')
  })

  it('does not share the default sort object with the caller', () => {
    const defaultSort = { column: 'name' as const, direction: 'asc' as const }
    const { sortBy } = run({ defaultSort })

    sortBy.value.column = 'size'

    expect(defaultSort.column).toBe('name')
  })
})

describe('query params', () => {
  it('exposes page, per_page and sort', () => {
    const { queryParams } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      pageSize: 24,
    })

    expect(queryParams.value).toEqual({ page: 1, per_page: 24, sort: '+name' })
  })

  it('defaults the page size to 25', () => {
    const { queryParams } = run({ defaultSort: { column: 'name', direction: 'asc' } })

    expect(queryParams.value.per_page).toBe(25)
  })

  it('merges filters without letting them clobber the pagination params', () => {
    const { setFilters, queryParams } = run({ defaultSort: { column: 'name', direction: 'asc' } })

    setFilters({ state: 'active' })

    expect(queryParams.value).toEqual({
      state: 'active',
      page: 1,
      per_page: 25,
      sort: '+name',
    })
  })

  it('tracks externally owned page and per-page refs', () => {
    const page = ref(3)
    const perPage = ref(12)
    const { queryParams, setPerPage } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      page,
      perPage,
    })

    expect(queryParams.value.page).toBe(3)
    expect(queryParams.value.per_page).toBe(12)

    setPerPage(48)
    expect(perPage.value).toBe(48)
  })
})

describe('page resets', () => {
  it('keeps the page by default when the sort, filters or page size change', async () => {
    const { setPage, setSortBy, setFilters, setPerPage, currentPage } = run({
      defaultSort: { column: 'name', direction: 'asc' },
    })

    setPage(4)
    setSortBy({ column: 'size', direction: 'desc' })
    setFilters({ state: 'active' })
    setPerPage(50)
    await nextTick()

    expect(currentPage.value).toBe(4)
  })

  it('resets to page 1 on sort changes when asked', async () => {
    const { setPage, setSortBy, currentPage } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      resetOnSort: true,
    })

    setPage(4)
    setSortBy({ column: 'size', direction: 'desc' })
    await nextTick()

    expect(currentPage.value).toBe(1)
  })

  it('resets to page 1 on filter changes when asked', async () => {
    const { setPage, setFilters, currentPage } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      resetOnFilters: true,
    })

    setPage(4)
    setFilters({ state: 'active' })
    await nextTick()

    expect(currentPage.value).toBe(1)
  })

  it('resets to page 1 on page-size changes when asked', () => {
    const { setPage, setPerPage, currentPage, queryParams } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      resetOnPageSize: true,
    })

    setPage(4)
    setPerPage(50)

    expect(currentPage.value).toBe(1)
    expect(queryParams.value.per_page).toBe(50)
  })

  it('resets to page 1 when a watched source changes', async () => {
    const search = ref('')
    const { setPage, currentPage } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      resetOn: search,
    })

    setPage(4)
    search.value = 'logo'
    await nextTick()

    expect(currentPage.value).toBe(1)
  })

  it('accepts several watched sources', async () => {
    const search = ref('')
    const folder = ref<string | null>(null)
    const { setPage, currentPage } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      resetOn: [search, () => folder.value],
    })

    setPage(4)
    folder.value = 'folder-1'
    await nextTick()

    expect(currentPage.value).toBe(1)
  })
})

describe('pagination bindings', () => {
  it('mirrors the current state and forwards updates', () => {
    const { paginationBindings, currentPage, queryParams } = run({
      defaultSort: { column: 'name', direction: 'asc' },
      pageSize: 24,
      pageSizeOptions: [24, 48],
    })

    expect(paginationBindings.value.currentPage).toBe(1)
    expect(paginationBindings.value.perPage).toBe(24)
    expect(paginationBindings.value.pageSizeOptions).toEqual([24, 48])

    paginationBindings.value['onUpdate:currentPage'](3)
    expect(currentPage.value).toBe(3)

    paginationBindings.value['onUpdate:perPage'](48)
    expect(queryParams.value.per_page).toBe(48)
  })
})
