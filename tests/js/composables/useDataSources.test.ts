import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { CreateDataSourcePayload, UpdateDataSourcePayload } from '~/types/data-sources'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({
  dataSources: { index, get, create, update, delete: destroy },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useDataSources } = await import('~/composables/useDataSources')

const SPACE = 'space-1'
const keys = queryKeys.dataSources(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useDataSources>
type Mutations = {
  create: ReturnType<Composable['useCreateDataSourceMutation']>
  update: ReturnType<Composable['useUpdateDataSourceMutation']>
  remove: ReturnType<Composable['useDeleteDataSourceMutation']>
}

let harness: Harness<Mutations> | undefined

/**
 * useMutation needs an injection context too, so the mutations have to be built
 * inside setup() rather than pulled off the composable in the test body.
 */
const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const dataSources = useDataSources(spaceId)
      return {
        create: dataSources.useCreateDataSourceMutation(),
        update: dataSources.useUpdateDataSourceMutation(),
        remove: dataSources.useDeleteDataSourceMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, success, error]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useDataSourcesQuery', () => {
  it('sorts by name ascending by default', async () => {
    withSetup(() => useDataSources(SPACE).useDataSourcesQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets the caller override the default sort', async () => {
    withSetup(() => useDataSources(SPACE).useDataSourcesQuery({ sort: '-name' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-name' })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'ds1' }], meta: { total: 1 } })

    const query = withSetup(() => useDataSources(SPACE).useDataSourcesQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'ds1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useDataSources(SPACE).useDataSourcesQuery({ is_active: true }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ is_active: true }))).toBeDefined()
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useDataSources('').useDataSourcesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = withSetup(() => useDataSources(SPACE).useDataSourcesQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() => useDataSources(SPACE).useDataSourcesQuery(params))

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('follows a reactive space id', async () => {
    const spaceId = ref(SPACE)
    const local = withSetup(() => useDataSources(spaceId).useDataSourcesQuery())

    await flush()
    spaceId.value = 'space-2'
    await nextTick()
    await flush()

    expect(forSpace).toHaveBeenCalledWith('space-2')
    expect(local.queryClient.getQueryData(queryKeys.dataSources('space-2').list({}))).toBeDefined()
    local.unmount()
  })
})

describe('useDataSourceQuery', () => {
  it('unwraps the data envelope for a single data source', async () => {
    get.mockResolvedValue({ data: { id: 'ds1', name: 'Countries' } })

    const query = withSetup(() => useDataSources(SPACE).useDataSourceQuery('ds1')).result
    await flush()

    expect(query.data.value).toEqual({ id: 'ds1', name: 'Countries' })
    expect(get).toHaveBeenCalledWith('ds1')
  })

  it('stays idle without an id', async () => {
    const query = withSetup(() => useDataSources(SPACE).useDataSourceQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = withSetup(() => useDataSources(SPACE).useDataSourceQuery('ds1', false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })
})

describe('useCreateDataSourceMutation', () => {
  const payload = {
    name: 'Countries',
    slug: 'countries',
    dimensions: [],
  } as CreateDataSourcePayload

  it('invalidates the lists and names the data source in the toast', async () => {
    create.mockResolvedValue({ data: { id: 'ds1', name: 'Countries' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    const result = await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(result).toEqual({ id: 'ds1', name: 'Countries' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Data source "Countries" created successfully')
  })

  it('touches nothing but the lists — a new data source has no detail entry yet', async () => {
    create.mockResolvedValue({ data: { id: 'ds1', name: 'Countries' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(invalidate).toHaveBeenCalledTimes(1)
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('slug taken'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('slug taken')
    expect(error).toHaveBeenCalledWith('Failed to create data source: slug taken')
    expect(success).not.toHaveBeenCalled()
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create data source: Unknown error')
  })
})

describe('useUpdateDataSourceMutation', () => {
  it('invalidates the lists and that data source detail', async () => {
    update.mockResolvedValue({ data: { id: 'ds1', name: 'Renamed' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      id: 'ds1',
      payload: { name: 'Renamed' } as UpdateDataSourcePayload,
    })

    expect(update).toHaveBeenCalledWith('ds1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('ds1') })
    expect(success).toHaveBeenCalledWith('Data source "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'x' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'ds1', payload: {} as UpdateDataSourcePayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('ds1') })
  })

  it('forwards the shape untouched, including an explicit null', async () => {
    update.mockResolvedValue({ data: { id: 'ds1', name: 'x' } })
    const { update: mutation } = setup()

    await mutation.mutateAsync({
      id: 'ds1',
      payload: { shape: null } as UpdateDataSourcePayload,
    })

    expect(update).toHaveBeenCalledWith('ds1', { shape: null })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('conflict'))
    const { update: mutation } = setup()

    await mutation.mutateAsync({ id: 'ds1', payload: {} as UpdateDataSourcePayload }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update data source: conflict')
  })
})

describe('useDeleteDataSourceMutation', () => {
  it('invalidates the lists and evicts the detail entry', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('ds1'), { id: 'ds1' }]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('ds1')

    expect(destroy).toHaveBeenCalledWith('ds1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(harness?.queryClient.getQueryData(keys.detail('ds1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Data source deleted successfully')
  })

  it('leaves the entries of the deleted data source in the cache', async () => {
    const entries = queryKeys.dataEntries(SPACE, 'ds1').list({})
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[entries, { data: [{ id: 'e1' }] }]])

    await remove.mutateAsync('ds1')

    expect(harness?.queryClient.getQueryData(entries)).toEqual({ data: [{ id: 'e1' }] })
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('ds1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
  })

  // Pins the shipped copy: the delete-error key talks about a *space*, not a
  // data source. Wrong noun, but asserting the right one would hide it.
  it('reports the failure with the copy the key actually carries', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const { remove } = setup()

    await remove.mutateAsync('ds1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to delete space: in use')
  })
})

describe('space scoping', () => {
  it('keys data sources per space, so two spaces never share a list', () => {
    expect(queryKeys.dataSources('a').lists()).not.toEqual(queryKeys.dataSources('b').lists())
  })

  it('invalidates only the current space on delete', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('ds1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.dataSources('space-2').lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
