import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const reset = vi.fn()
const exportData = vi.fn()
const importData = vi.fn()

const forSpace = vi.fn(() => ({
  redirects: {
    index,
    get,
    create,
    update,
    delete: destroy,
    reset,
    export: exportData,
    importData,
  },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useRedirects } = await import('~/composables/useRedirects')

const SPACE = 'space-1'
const keys = queryKeys.redirects(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useRedirects>
type Mutations = {
  create: ReturnType<Composable['useCreateRedirectMutation']>
  update: ReturnType<Composable['useUpdateRedirectMutation']>
  remove: ReturnType<Composable['useDeleteRedirectMutation']>
  resetStats: ReturnType<Composable['useResetRedirectStatsMutation']>
  exportAll: ReturnType<Composable['useExportRedirectsMutation']>
  importAll: ReturnType<Composable['useImportRedirectsMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const redirects = useRedirects(spaceId)
      return {
        create: redirects.useCreateRedirectMutation(),
        update: redirects.useUpdateRedirectMutation(),
        remove: redirects.useDeleteRedirectMutation(),
        resetStats: redirects.useResetRedirectStatsMutation(),
        exportAll: redirects.useExportRedirectsMutation(),
        importAll: redirects.useImportRedirectsMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [
    index,
    get,
    create,
    update,
    destroy,
    reset,
    exportData,
    importData,
    success,
    error,
  ]) {
    fn.mockReset()
  }
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useRedirectsQuery', () => {
  it('sorts by source ascending by default', async () => {
    withSetup(() => useRedirects(SPACE).useRedirectsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+source' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets the caller override the default sort', async () => {
    withSetup(() => useRedirects(SPACE).useRedirectsQuery({ sort: '-hits' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-hits' })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'r1' }], meta: { total: 1 } })

    const query = withSetup(() => useRedirects(SPACE).useRedirectsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'r1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useRedirects(SPACE).useRedirectsQuery({ status_code: 301 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ status_code: 301 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useRedirects('').useRedirectsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = withSetup(() => useRedirects(SPACE).useRedirectsQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() => useRedirects(SPACE).useRedirectsQuery(params))

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })
})

describe('useRedirectQuery', () => {
  it('unwraps the data envelope for a single redirect', async () => {
    get.mockResolvedValue({ data: { id: 'r1', source: '/old' } })

    const query = withSetup(() => useRedirects(SPACE).useRedirectQuery('r1')).result
    await flush()

    expect(query.data.value).toEqual({ id: 'r1', source: '/old' })
    expect(get).toHaveBeenCalledWith('r1')
  })

  it('stays idle without an id', async () => {
    const query = withSetup(() => useRedirects(SPACE).useRedirectQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = withSetup(() => useRedirects(SPACE).useRedirectQuery('r1', false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })
})

describe('useCreateRedirectMutation', () => {
  const payload = { source: '/old', target: '/new', status_code: 301 } as CreateRedirectPayload

  it('invalidates the lists and names the source in the toast', async () => {
    create.mockResolvedValue({ data: { id: 'r1', source: '/old' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(success).toHaveBeenCalledWith('Redirect "/old" created successfully')
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('source already redirected'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('source already redirected')
    expect(error).toHaveBeenCalledWith('Failed to create redirect: source already redirected')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create redirect: Unknown error')
  })
})

describe('useUpdateRedirectMutation', () => {
  it('invalidates the lists and that redirect detail', async () => {
    update.mockResolvedValue({ data: { id: 'r1', source: '/old' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      id: 'r1',
      payload: { target: '/newer' } as UpdateRedirectPayload,
    })

    expect(update).toHaveBeenCalledWith('r1', { target: '/newer' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('r1') })
    expect(success).toHaveBeenCalledWith('Redirect "/old" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', source: '/old' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'r1', payload: {} as UpdateRedirectPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('r1') })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('loop detected'))
    const { update: mutation } = setup()

    await mutation.mutateAsync({ id: 'r1', payload: {} as UpdateRedirectPayload }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update redirect: loop detected')
  })
})

describe('useDeleteRedirectMutation', () => {
  it('invalidates the lists and evicts the detail entry', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('r1'), { id: 'r1' }]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('r1')

    expect(destroy).toHaveBeenCalledWith('r1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(harness?.queryClient.getQueryData(keys.detail('r1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Redirect deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('nope'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('r1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete redirect: nope')
  })
})

describe('useResetRedirectStatsMutation', () => {
  it('invalidates the lists and the detail, and names the source', async () => {
    reset.mockResolvedValue({ data: { id: 'r1', source: '/old', hits: 0 } })
    const { resetStats } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    const result = await resetStats.mutateAsync('r1')

    expect(reset).toHaveBeenCalledWith('r1')
    expect(result).toEqual({ id: 'r1', source: '/old', hits: 0 })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('r1') })
    expect(success).toHaveBeenCalledWith('Statistics for redirect "/old" reset successfully')
  })

  it('keys the detail invalidation off the response id', async () => {
    reset.mockResolvedValue({ data: { id: 'server-id', source: '/old' } })
    const { resetStats } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await resetStats.mutateAsync('r1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
  })

  it('reports the failure reason', async () => {
    reset.mockRejectedValue(new Error('not found'))
    const { resetStats } = setup()

    await resetStats.mutateAsync('r1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to reset redirect statistics: not found')
  })
})

describe('useExportRedirectsMutation', () => {
  it('forwards the filters alongside the format and returns the blob', async () => {
    const blob = new Blob(['source,target'])
    exportData.mockResolvedValue(blob)
    const { exportAll } = setup()

    expect(await exportAll.mutateAsync({ as: 'csv', status_code: 301 })).toBe(blob)
    expect(exportData).toHaveBeenCalledWith({ as: 'csv', status_code: 301 })
  })

  it('invalidates nothing and shows no success toast — an export changes no data', async () => {
    exportData.mockResolvedValue(new Blob([]))
    const { exportAll } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await exportAll.mutateAsync({ as: 'json' })

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
  })

  it('reports the failure reason', async () => {
    exportData.mockRejectedValue(new Error('too many rows'))
    const { exportAll } = setup()

    await exportAll.mutateAsync({ as: 'csv' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to export redirects: too many rows')
  })
})

describe('useImportRedirectsMutation', () => {
  const file = new File(['source,target'], 'redirects.csv', { type: 'text/csv' })

  it('forwards the file and mode, then invalidates the lists', async () => {
    importData.mockResolvedValue({ successes: [], errors: [] })
    const { importAll } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await importAll.mutateAsync({ file, mode: 'replacement' })

    expect(importData).toHaveBeenCalledWith(file, 'replacement')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Redirects imported successfully')
  })

  // A replacement import can delete rows, but the per-redirect detail entries
  // are never evicted — only the lists are invalidated.
  it('leaves stale detail entries in the cache after a replacement import', async () => {
    importData.mockResolvedValue({ successes: [], errors: [] })
    const { importAll } = setup(SPACE, [[keys.detail('r1'), { id: 'r1', source: '/gone' }]])

    await importAll.mutateAsync({ file, mode: 'replacement' })

    expect(harness?.queryClient.getQueryData(keys.detail('r1'))).toEqual({
      id: 'r1',
      source: '/gone',
    })
  })

  it('reports success even when the result contains row errors', async () => {
    importData.mockResolvedValue({ successes: [], errors: [{ row: 2, message: 'bad' }] })
    const { importAll } = setup()

    await importAll.mutateAsync({ file, mode: 'addition' })

    expect(success).toHaveBeenCalledWith('Redirects imported successfully')
    expect(error).not.toHaveBeenCalled()
  })

  it('reports the failure reason', async () => {
    importData.mockRejectedValue(new Error('unsupported format'))
    const { importAll } = setup()

    await importAll.mutateAsync({ file, mode: 'addition' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to import redirects: unsupported format')
  })
})

describe('space scoping', () => {
  it('keys redirects per space, so two spaces never share a list', () => {
    expect(queryKeys.redirects('a').lists()).not.toEqual(queryKeys.redirects('b').lists())
  })

  it('invalidates only the current space on delete', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('r1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.redirects('space-2').lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
