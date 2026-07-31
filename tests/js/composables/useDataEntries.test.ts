import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type {
  CreateDataEntryPayload,
  DataEntryValue,
  UpdateDataEntryPayload,
} from '~/types/data-sources'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const getEntries = vi.fn()
const getEntry = vi.fn()
const createEntry = vi.fn()
const updateEntry = vi.fn()
const deleteEntry = vi.fn()
const exportEntries = vi.fn()
const importEntries = vi.fn()
const custom = vi.fn()

const forSpace = vi.fn(() => ({
  dataSources: {
    getEntries,
    getEntry,
    createEntry,
    updateEntry,
    deleteEntry,
    exportEntries,
    importEntries,
    custom,
  },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useDataEntries } = await import('~/composables/useDataEntries')

const SPACE = 'space-1'
const SOURCE = 'ds-1'
const keys = queryKeys.dataEntries(SPACE, SOURCE)
const sourceKeys = queryKeys.dataSources(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useDataEntries>
type Mutations = {
  create: ReturnType<Composable['useCreateDataEntryMutation']>
  update: ReturnType<Composable['useUpdateDataEntryMutation']>
  remove: ReturnType<Composable['useDeleteDataEntryMutation']>
  batch: ReturnType<Composable['useBatchUpdateEntriesMutation']>
  exportAll: ReturnType<Composable['useExportDataEntriesMutation']>
  importAll: ReturnType<Composable['useImportDataEntriesMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (
  spaceId: MaybeRef<string> = SPACE,
  dataSourceId: MaybeRef<string> = SOURCE,
  seed?: Array<[readonly unknown[], unknown]>
) => {
  harness = withSetup<Mutations>(
    () => {
      const entries = useDataEntries(spaceId, dataSourceId)
      return {
        create: entries.useCreateDataEntryMutation(),
        update: entries.useUpdateDataEntryMutation(),
        remove: entries.useDeleteDataEntryMutation(),
        batch: entries.useBatchUpdateEntriesMutation(),
        exportAll: entries.useExportDataEntriesMutation(),
        importAll: entries.useImportDataEntriesMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [
    getEntries,
    getEntry,
    createEntry,
    updateEntry,
    deleteEntry,
    exportEntries,
    importEntries,
    custom,
    success,
    error,
  ]) {
    fn.mockReset()
  }
  forSpace.mockClear()
  getEntries.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useDataEntriesQuery', () => {
  it('sorts by key ascending by default and scopes to the data source', async () => {
    withSetup(() => useDataEntries(SPACE, SOURCE).useDataEntriesQuery())
    await flush()

    expect(getEntries).toHaveBeenCalledWith(SOURCE, { sort: '+key' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets the caller override the default sort and add filters', async () => {
    withSetup(() =>
      useDataEntries(SPACE, SOURCE).useDataEntriesQuery({ sort: '-key', key: 'at', page: 2 })
    )
    await flush()

    expect(getEntries).toHaveBeenCalledWith(SOURCE, { sort: '-key', key: 'at', page: 2 })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    getEntries.mockResolvedValue({ data: [{ id: 'e1' }], meta: { total: 1 } })

    const query = withSetup(() => useDataEntries(SPACE, SOURCE).useDataEntriesQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'e1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useDataEntries(SPACE, SOURCE).useDataEntriesQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle without a space id', async () => {
    const query = withSetup(() => useDataEntries('', SOURCE).useDataEntriesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(getEntries).not.toHaveBeenCalled()
  })

  it('stays idle without a data source id', async () => {
    const query = withSetup(() => useDataEntries(SPACE, '').useDataEntriesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(getEntries).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = withSetup(() => useDataEntries(SPACE, SOURCE).useDataEntriesQuery({}, false))
      .result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('rekeys when the data source changes, so two sources never share a page', async () => {
    const dataSourceId = ref(SOURCE)
    const local = withSetup(() => useDataEntries(SPACE, dataSourceId).useDataEntriesQuery())

    await flush()
    dataSourceId.value = 'ds-2'
    await nextTick()
    await flush()

    expect(getEntries).toHaveBeenLastCalledWith('ds-2', { sort: '+key' })
    expect(
      local.queryClient.getQueryData(queryKeys.dataEntries(SPACE, 'ds-2').list({}))
    ).toBeDefined()
    local.unmount()
  })
})

describe('useDataEntryQuery', () => {
  it('unwraps the data envelope for a single entry', async () => {
    getEntry.mockResolvedValue({ data: { id: 'e1', key: 'at', value: 'Austria' } })

    const query = withSetup(() => useDataEntries(SPACE, SOURCE).useDataEntryQuery('e1')).result
    await flush()

    expect(query.data.value).toEqual({ id: 'e1', key: 'at', value: 'Austria' })
    expect(getEntry).toHaveBeenCalledWith(SOURCE, 'e1')
  })

  // No `enabled` guard here, unlike every other detail query in this module's
  // siblings: an empty id still fires a request for `/entries/`.
  it('fires even without an id', async () => {
    getEntry.mockResolvedValue({ data: null })

    const query = withSetup(() => useDataEntries(SPACE, SOURCE).useDataEntryQuery('')).result
    await flush()

    expect(getEntry).toHaveBeenCalledWith(SOURCE, '')
    expect(query.status.value).toBe('success')
  })

  it('fires even without a data source id', async () => {
    getEntry.mockResolvedValue({ data: null })

    withSetup(() => useDataEntries(SPACE, '').useDataEntryQuery('e1'))
    await flush()

    expect(getEntry).toHaveBeenCalledWith('', 'e1')
  })
})

describe('entry values', () => {
  /**
   * The composable is a pass-through for `value`: the structured/raw-string
   * encoding lives in the data source page, not here. These pin that nothing
   * on the way to the API coerces a shaped object into a string or back.
   */
  const roundTrip = async (value: DataEntryValue) => {
    createEntry.mockResolvedValue({ data: { id: 'e1', key: 'at', value } })
    const { create } = setup()

    const result = await create.mutateAsync({ key: 'at', value } as CreateDataEntryPayload)

    return { sent: createEntry.mock.calls[0][1], received: result.value }
  }

  it('sends a shaped object untouched and reads it back unchanged', async () => {
    const value = { name: 'Austria', code: 'AT', population: 9000000, eu: true }

    const { sent, received } = await roundTrip(value)

    expect(sent).toEqual({ key: 'at', value })
    // Not JSON-stringified on the way out: the object stays an object.
    expect(typeof sent.value).toBe('object')
    expect(received).toEqual(value)
  })

  it('sends a legacy raw string untouched', async () => {
    const { sent, received } = await roundTrip('Austria')

    expect(sent).toEqual({ key: 'at', value: 'Austria' })
    expect(received).toBe('Austria')
  })

  it('preserves an empty string rather than dropping it', async () => {
    const { sent } = await roundTrip('')

    expect(sent).toEqual({ key: 'at', value: '' })
  })

  it('preserves an explicit null', async () => {
    const { sent, received } = await roundTrip(null)

    expect(sent).toEqual({ key: 'at', value: null })
    expect(received).toBeNull()
  })

  it('preserves an empty shaped object', async () => {
    const { sent } = await roundTrip({})

    expect(sent).toEqual({ key: 'at', value: {} })
  })

  it('preserves falsy-but-valid values inside a shaped object', async () => {
    const { sent } = await roundTrip({ count: 0, label: '', flag: false, missing: null })

    expect(sent).toEqual({ key: 'at', value: { count: 0, label: '', flag: false, missing: null } })
  })

  it('carries per-dimension values through, mixing shaped and raw', async () => {
    updateEntry.mockResolvedValue({ data: { id: 'e1', key: 'at' } })
    const { update } = setup()

    await update.mutateAsync({
      id: 'e1',
      payload: {
        dimensions: { de: { name: 'Österreich' }, en: 'Austria', fr: null },
      } as UpdateDataEntryPayload,
    })

    expect(updateEntry).toHaveBeenCalledWith(SOURCE, 'e1', {
      dimensions: { de: { name: 'Österreich' }, en: 'Austria', fr: null },
    })
  })
})

describe('useCreateDataEntryMutation', () => {
  it('invalidates the entry lists and the parent data source detail', async () => {
    createEntry.mockResolvedValue({ data: { id: 'e1', key: 'at' } })
    const { create } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await create.mutateAsync({ key: 'at' } as CreateDataEntryPayload)

    expect(createEntry).toHaveBeenCalledWith(SOURCE, { key: 'at' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    // The parent carries entries_count, so it has to be refetched too.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: sourceKeys.detail(SOURCE) })
    expect(success).toHaveBeenCalledWith('Data entry "at" created successfully')
  })

  it('reports the failure reason', async () => {
    createEntry.mockRejectedValue(new Error('duplicate key'))
    const { create } = setup()

    await expect(create.mutateAsync({ key: 'at' } as CreateDataEntryPayload)).rejects.toThrow(
      'duplicate key'
    )
    expect(error).toHaveBeenCalledWith('Failed to create data entry: duplicate key')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    createEntry.mockRejectedValue(new Error(''))
    const { create } = setup()

    await create.mutateAsync({ key: 'at' } as CreateDataEntryPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create data entry: Unknown error')
  })
})

describe('useUpdateDataEntryMutation', () => {
  it('invalidates the lists and that entry detail', async () => {
    updateEntry.mockResolvedValue({ data: { id: 'e1', key: 'at' } })
    const { update } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await update.mutateAsync({ id: 'e1', payload: { key: 'at' } as UpdateDataEntryPayload })

    expect(updateEntry).toHaveBeenCalledWith(SOURCE, 'e1', { key: 'at' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('e1') })
    expect(success).toHaveBeenCalledWith('Data entry "at" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    updateEntry.mockResolvedValue({ data: { id: 'server-id', key: 'at' } })
    const { update } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await update.mutateAsync({ id: 'e1', payload: {} as UpdateDataEntryPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('e1') })
  })

  // Unlike create and delete, update leaves the parent data source alone.
  it('does not invalidate the parent data source detail', async () => {
    updateEntry.mockResolvedValue({ data: { id: 'e1', key: 'at' } })
    const { update } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await update.mutateAsync({ id: 'e1', payload: {} as UpdateDataEntryPayload })

    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: sourceKeys.detail(SOURCE) })
  })

  it('reports the failure reason', async () => {
    updateEntry.mockRejectedValue(new Error('validation failed'))
    const { update } = setup()

    await update.mutateAsync({ id: 'e1', payload: {} as UpdateDataEntryPayload }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update data entry: validation failed')
  })
})

describe('useDeleteDataEntryMutation', () => {
  it('invalidates the lists, evicts the detail and refreshes the parent', async () => {
    deleteEntry.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, SOURCE, [[keys.detail('e1'), { id: 'e1' }]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('e1')

    expect(deleteEntry).toHaveBeenCalledWith(SOURCE, 'e1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: sourceKeys.detail(SOURCE) })
    expect(harness?.queryClient.getQueryData(keys.detail('e1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Data entry deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    deleteEntry.mockRejectedValue(new Error('nope'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('e1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete data entry: nope')
  })
})

describe('useBatchUpdateEntriesMutation', () => {
  it('posts the whole batch to the entries/batch endpoint', async () => {
    custom.mockResolvedValue({ data: [{ id: 'e1', key: 'at' }, { id: 'e2', key: 'de' }] })
    const { batch } = setup()

    const result = await batch.mutateAsync([
      { key: 'at', value: { name: 'Austria' } },
      { key: 'de', value: 'Germany' },
    ] as CreateDataEntryPayload[])

    expect(custom).toHaveBeenCalledWith('POST', `${SOURCE}/entries/batch`, {
      entries: [
        { key: 'at', value: { name: 'Austria' } },
        { key: 'de', value: 'Germany' },
      ],
    })
    expect(result).toHaveLength(2)
  })

  it('still posts an empty batch', async () => {
    custom.mockResolvedValue({ data: [] })
    const { batch } = setup()

    await batch.mutateAsync([])

    expect(custom).toHaveBeenCalledWith('POST', `${SOURCE}/entries/batch`, { entries: [] })
  })

  it('invalidates the lists and the parent data source', async () => {
    custom.mockResolvedValue({ data: [] })
    const { batch } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await batch.mutateAsync([])

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: sourceKeys.detail(SOURCE) })
    expect(success).toHaveBeenCalledWith('Batch update completed successfully')
  })

  // Individual detail entries are left stale: only the lists are invalidated.
  it('does not evict the per-entry detail cache', async () => {
    custom.mockResolvedValue({ data: [{ id: 'e1', key: 'at' }] })
    const { batch } = setup(SPACE, SOURCE, [[keys.detail('e1'), { id: 'e1', value: 'stale' }]])

    await batch.mutateAsync([{ key: 'at', value: 'fresh' }] as CreateDataEntryPayload[])

    expect(harness?.queryClient.getQueryData(keys.detail('e1'))).toEqual({
      id: 'e1',
      value: 'stale',
    })
  })

  it('reports the failure reason', async () => {
    custom.mockRejectedValue(new Error('row 3 invalid'))
    const { batch } = setup()

    await batch.mutateAsync([]).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to batch update entries: row 3 invalid')
  })
})

describe('useExportDataEntriesMutation', () => {
  it('returns the blob straight through', async () => {
    const blob = new Blob(['key,value'])
    exportEntries.mockResolvedValue(blob)
    const { exportAll } = setup()

    expect(await exportAll.mutateAsync({ as: 'csv' })).toBe(blob)
    expect(exportEntries).toHaveBeenCalledWith(SOURCE, { as: 'csv' })
  })

  it('invalidates nothing and shows no success toast — an export changes no data', async () => {
    exportEntries.mockResolvedValue(new Blob([]))
    const { exportAll } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await exportAll.mutateAsync({ as: 'json' })

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
  })

  it('reports the failure reason', async () => {
    exportEntries.mockRejectedValue(new Error('too many rows'))
    const { exportAll } = setup()

    await exportAll.mutateAsync({ as: 'csv' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to export data entries: too many rows')
  })
})

describe('useImportDataEntriesMutation', () => {
  const file = new File(['key,value'], 'entries.csv', { type: 'text/csv' })

  it('forwards the file and mode, then invalidates the lists and parent', async () => {
    importEntries.mockResolvedValue({ successes: [], errors: [] })
    const { importAll } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await importAll.mutateAsync({ file, mode: 'replacement' })

    expect(importEntries).toHaveBeenCalledWith(SOURCE, file, 'replacement')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: sourceKeys.detail(SOURCE) })
    expect(success).toHaveBeenCalledWith('Data entries imported successfully')
  })

  // A partially failed import still resolves, so the success toast fires even
  // when the result carries row errors.
  it('reports success even when the result contains row errors', async () => {
    importEntries.mockResolvedValue({ successes: [], errors: [{ row: 3, message: 'bad' }] })
    const { importAll } = setup()

    const result = await importAll.mutateAsync({ file, mode: 'addition' })

    expect(result.errors).toHaveLength(1)
    expect(success).toHaveBeenCalledWith('Data entries imported successfully')
    expect(error).not.toHaveBeenCalled()
  })

  it('reports the failure reason', async () => {
    importEntries.mockRejectedValue(new Error('unsupported format'))
    const { importAll } = setup()

    await importAll.mutateAsync({ file, mode: 'addition' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to import data entries: unsupported format')
  })
})

describe('key shape', () => {
  it('nests entries under their data source, so two sources never share a list', () => {
    expect(queryKeys.dataEntries(SPACE, 'a').lists()).not.toEqual(
      queryKeys.dataEntries(SPACE, 'b').lists()
    )
  })

  it('nests under the data source detail path without colliding with it', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'data-sources', SOURCE, 'entries'])
    expect(sourceKeys.detail(SOURCE)).toEqual(['spaces', SPACE, 'data-sources', 'detail', SOURCE])
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
