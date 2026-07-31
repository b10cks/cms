import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const addAssets = vi.fn()
const removeAssets = vi.fn()
const reorderAssets = vi.fn()
const getAssets = vi.fn()

const forSpace = vi.fn(() => ({
  assetCollections: {
    index,
    get,
    create,
    update,
    delete: destroy,
    addAssets,
    removeAssets,
    reorderAssets,
    getAssets,
  },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAssetCollections } = await import('~/composables/useAssetCollections')

const SPACE = 'space-1'
const keys = queryKeys.assetCollections(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const mounted: Array<() => void> = []

/**
 * Every factory the composable returns calls useQuery/useMutation, so it has to
 * be built inside setup() — not on a value captured out of a previous mount.
 */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const inSpace = (spaceId: MaybeRef<string> = SPACE) => useAssetCollections(spaceId)

/** A smart collection's rule DSL travels through the frontend untouched. */
const smartRules = {
  match: 'all',
  conditions: [
    { field: 'tag', operator: 'in', value: ['hero', 'press'] },
    { field: 'mime_type', operator: 'starts_with', value: 'image/' },
    { field: 'created_at', operator: 'after', value: '2026-01-01' },
    { field: 'untagged', operator: 'equals', value: true },
  ],
} as unknown as AssetCollectionRules

beforeEach(() => {
  for (const fn of [
    index,
    get,
    create,
    update,
    destroy,
    addAssets,
    removeAssets,
    reorderAssets,
    getAssets,
    success,
    error,
  ]) {
    fn.mockReset()
  }
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
  getAssets.mockResolvedValue({ data: [] })
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
})

describe('useAssetCollectionsQuery', () => {
  it('sorts by name ascending by default', async () => {
    mount(() => inSpace().useAssetCollectionsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets caller params override the default sort', async () => {
    mount(() => inSpace().useAssetCollectionsQuery({ sort: '-created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('forwards a type filter alongside the sort', async () => {
    mount(() => inSpace().useAssetCollectionsQuery({ type: 'smart' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name', type: 'smart' })
  })

  it('caches under the filter-scoped list key', async () => {
    const harness = mount(() => inSpace().useAssetCollectionsQuery({ type: 'manual' }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ type: 'manual' }))).toBeDefined()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ q: 'a' })
    const harness = mount(() => inSpace().useAssetCollectionsQuery(params))
    await flush()

    params.value = { q: 'b' }
    await nextTick()
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ q: 'b' }))).toBeDefined()
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'c1' }], meta: { total: 1 } })

    const query = mount(() => inSpace().useAssetCollectionsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'c1' }], meta: { total: 1 } })
  })

  it('stays idle for an empty space id rather than requesting /spaces//asset-collections', async () => {
    mount(() => inSpace('').useAssetCollectionsQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()
  })
})

describe('useAssetCollectionQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: { id: 'c1', name: 'Hero', type: 'manual' } })

    const query = mount(() => inSpace().useAssetCollectionQuery('c1')).result
    await flush()

    expect(get).toHaveBeenCalledWith('c1')
    expect(query.data.value).toEqual({ id: 'c1', name: 'Hero', type: 'manual' })
  })

  it('returns the smart rules verbatim', async () => {
    get.mockResolvedValue({ data: { id: 'c1', type: 'smart', rules: smartRules } })

    const query = mount(() => inSpace().useAssetCollectionQuery('c1')).result
    await flush()

    expect(query.data.value?.rules).toEqual(smartRules)
  })

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['an empty string', ''],
  ])('stays idle for %s id', async (_label, id) => {
    const query = mount(() => inSpace().useAssetCollectionQuery(id)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useAssetCollectionQuery('c1', false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('keys a missing id as the empty-string detail', () => {
    const harness = mount(() => inSpace().useAssetCollectionQuery(null))

    expect(harness.queryClient.getQueryCache().find({ queryKey: keys.detail('') })).toBeDefined()
  })
})

describe('useCollectionAssetsQuery', () => {
  it('fetches the collection assets with the caller params', async () => {
    mount(() => inSpace().useCollectionAssetsQuery('c1', { page: 2, per_page: 24 }))
    await flush()

    expect(getAssets).toHaveBeenCalledWith('c1', { page: 2, per_page: 24 })
  })

  it('adds no default sort — collection order is server-defined', async () => {
    mount(() => inSpace().useCollectionAssetsQuery('c1'))
    await flush()

    expect(getAssets).toHaveBeenCalledWith('c1', {})
  })

  it('caches under the collection-scoped assets list key', async () => {
    const harness = mount(() => inSpace().useCollectionAssetsQuery('c1', { page: 2 }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.assetsList('c1', { page: 2 }))).toBeDefined()
  })

  it('stays idle without a collection id', async () => {
    const query = mount(() => inSpace().useCollectionAssetsQuery(null)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(getAssets).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useCollectionAssetsQuery('c1', {}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })
})

describe('useCreateAssetCollectionMutation', () => {
  it('invalidates the lists and names the collection in the toast', async () => {
    create.mockResolvedValue({ data: { id: 'c1', name: 'Hero shots' } })
    const harness = mount(() => inSpace().useCreateAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync({ name: 'Hero shots', type: 'manual' })

    expect(create).toHaveBeenCalledWith({ name: 'Hero shots', type: 'manual' })
    expect(result).toEqual({ id: 'c1', name: 'Hero shots' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Collection "Hero shots" created successfully')
  })

  it('sends the smart rule DSL through untransformed', async () => {
    create.mockResolvedValue({ data: { id: 'c1', name: 'Smart' } })
    const harness = mount(() => inSpace().useCreateAssetCollectionMutation())

    await harness.result.mutateAsync({ name: 'Smart', type: 'smart', rules: smartRules })

    expect(create).toHaveBeenCalledWith({ name: 'Smart', type: 'smart', rules: smartRules })
    // Same object identity: nothing clones or normalizes the conditions.
    expect(create.mock.calls[0][0].rules).toBe(smartRules)
  })

  it('does not invalidate the detail or asset keys on create', async () => {
    create.mockResolvedValue({ data: { id: 'c1', name: 'Hero' } })
    const harness = mount(() => inSpace().useCreateAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ name: 'Hero', type: 'manual' })

    expect(invalidate).toHaveBeenCalledTimes(1)
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('name taken'))
    const harness = mount(() => inSpace().useCreateAssetCollectionMutation())

    await expect(harness.result.mutateAsync({ name: 'Hero', type: 'manual' })).rejects.toThrow(
      'name taken'
    )
    expect(error).toHaveBeenCalledWith('Failed to create collection: name taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const harness = mount(() => inSpace().useCreateAssetCollectionMutation())

    await harness.result.mutateAsync({ name: 'Hero', type: 'manual' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create collection: Unknown error')
  })
})

describe('useUpdateAssetCollectionMutation', () => {
  it('invalidates lists, detail, collection assets and the asset grid', async () => {
    update.mockResolvedValue({ data: { id: 'c1', name: 'Renamed' } })
    const harness = mount(() => inSpace().useUpdateAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'c1', payload: { name: 'Renamed' } })

    expect(update).toHaveBeenCalledWith('c1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('c1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.assets('c1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Collection "Renamed" updated successfully')
  })

  it('keys the invalidations off the response id, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'x' } })
    const harness = mount(() => inSpace().useUpdateAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'c1', payload: {} })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('c1') })
  })

  it('sends changed rules verbatim so the smart membership can be recomputed', async () => {
    update.mockResolvedValue({ data: { id: 'c1', name: 'Smart' } })
    const harness = mount(() => inSpace().useUpdateAssetCollectionMutation())

    await harness.result.mutateAsync({ id: 'c1', payload: { rules: smartRules } })

    expect(update).toHaveBeenCalledWith('c1', { rules: smartRules })
  })

  it('accepts clearing the rules with null', async () => {
    update.mockResolvedValue({ data: { id: 'c1', name: 'Smart' } })
    const harness = mount(() => inSpace().useUpdateAssetCollectionMutation())

    await harness.result.mutateAsync({ id: 'c1', payload: { rules: null } })

    expect(update).toHaveBeenCalledWith('c1', { rules: null })
  })

  it('does not invalidate anything when the update fails', async () => {
    update.mockRejectedValue(new Error('invalid rules'))
    const harness = mount(() => inSpace().useUpdateAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'c1', payload: {} }).catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to update collection: invalid rules')
  })
})

describe('useDeleteAssetCollectionMutation', () => {
  it('invalidates the lists and drops the detail and asset caches', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteAssetCollectionMutation(), [
      [keys.detail('c1'), { id: 'c1' }],
      [keys.assetsList('c1', {}), { data: [] }],
    ])
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('c1')

    expect(destroy).toHaveBeenCalledWith('c1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.detail('c1') })
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.assets('c1') })
    expect(harness.queryClient.getQueryData(keys.detail('c1'))).toBeUndefined()
    expect(harness.queryClient.getQueryData(keys.assetsList('c1', {}))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Collection deleted successfully')
  })

  it('leaves the plain asset grid alone — deleting a collection keeps the assets', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('c1')

    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
  })

  it('reports the failure reason', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const harness = mount(() => inSpace().useDeleteAssetCollectionMutation())

    await harness.result.mutateAsync('c1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to delete collection: in use')
  })
})

describe('useAddAssetsToCollectionMutation', () => {
  it('adds the ids and invalidates every affected key', async () => {
    addAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useAddAssetsToCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1', 'a2'] })

    expect(addAssets).toHaveBeenCalledWith('c1', ['a1', 'a2'])
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.assets('c1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('c1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Added 2 assets to collection')
  })

  it('uses the singular copy for one asset', async () => {
    addAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useAddAssetsToCollectionMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] })

    expect(success).toHaveBeenCalledWith('Added 1 asset to collection')
  })

  it('still calls the API for an empty selection', async () => {
    addAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useAddAssetsToCollectionMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: [] })

    // No guard in the composable: the request goes out and the toast reads "0".
    expect(addAssets).toHaveBeenCalledWith('c1', [])
    expect(success).toHaveBeenCalledWith('Added 0 assets to collection')
  })

  it('reports the failure reason', async () => {
    addAssets.mockRejectedValue(new Error('smart collection'))
    const harness = mount(() => inSpace().useAddAssetsToCollectionMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to add assets to collection: smart collection')
  })
})

describe('useRemoveAssetsFromCollectionMutation', () => {
  it('removes the ids and invalidates every affected key', async () => {
    removeAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useRemoveAssetsFromCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1', 'a2', 'a3'] })

    expect(removeAssets).toHaveBeenCalledWith('c1', ['a1', 'a2', 'a3'])
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.assets('c1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Removed 3 assets from collection')
  })

  it('uses the singular copy for one asset', async () => {
    removeAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useRemoveAssetsFromCollectionMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] })

    expect(success).toHaveBeenCalledWith('Removed 1 asset from collection')
  })

  it('reports the failure reason', async () => {
    removeAssets.mockRejectedValue(new Error('nope'))
    const harness = mount(() => inSpace().useRemoveAssetsFromCollectionMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to remove assets from collection: nope')
  })
})

describe('useReorderCollectionAssetsMutation', () => {
  it('sends the full order and refreshes every view of the collection', async () => {
    reorderAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useReorderCollectionAssetsMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a2', 'a1'] })

    expect(reorderAssets).toHaveBeenCalledWith('c1', ['a2', 'a1'])
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.assets('c1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(invalidate).toHaveBeenCalledTimes(4)
  })

  it('stays silent on success — reordering is not worth a toast', async () => {
    reorderAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useReorderCollectionAssetsMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] })

    expect(success).not.toHaveBeenCalled()
  })

  it('refreshes the collection list and detail, whose cover asset the order decides', async () => {
    reorderAssets.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useReorderCollectionAssetsMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('c1') })
  })

  it('reports the failure reason', async () => {
    reorderAssets.mockRejectedValue(new Error('conflict'))
    const harness = mount(() => inSpace().useReorderCollectionAssetsMutation())

    await harness.result.mutateAsync({ collectionId: 'c1', assetIds: ['a1'] }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to reorder collection: conflict')
  })
})

describe('query key shape', () => {
  it('scopes every key to the space', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'asset-collections'])
    expect(queryKeys.assetCollections('a').lists()).not.toEqual(
      queryKeys.assetCollections('b').lists()
    )
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ type: 'smart' })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('makes assets(id) a prefix of assetsList(id, filters)', () => {
    const list = keys.assetsList('c1', { page: 2 })

    expect(list.slice(0, keys.assets('c1').length)).toEqual([...keys.assets('c1')])
  })

  it('keeps the assets key out of the lists() namespace, so list invalidation misses it', () => {
    expect(keys.assets('c1').slice(0, keys.lists().length)).not.toEqual([...keys.lists()])
  })

  it('invalidates only the current space', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace('space-2').useDeleteAssetCollectionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('c1')

    expect(invalidate).toHaveBeenCalledWith({
      queryKey: queryKeys.assetCollections('space-2').lists(),
    })
  })
})
