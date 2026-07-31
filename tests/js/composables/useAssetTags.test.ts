import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const assign = vi.fn()
const assetsIndex = vi.fn()

const forSpace = vi.fn(() => ({
  assetTags: { index, get, create, update, delete: destroy, assign },
  assets: { index: assetsIndex },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAssetTags } = await import('~/composables/useAssetTags')

const SPACE = 'space-1'
const keys = queryKeys.assetTags(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const mounted: Array<() => void> = []

/** Factories call useQuery/useMutation, so they must be built inside setup(). */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const inSpace = (spaceId: MaybeRef<string> = SPACE) => useAssetTags(spaceId)

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, assign, assetsIndex, success, error]) {
    fn.mockReset()
  }
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
  assetsIndex.mockResolvedValue({ data: [] })
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
})

describe('useAssetTagsQuery', () => {
  it('sorts by name ascending by default', async () => {
    mount(() => inSpace().useAssetTagsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets caller params override the default sort', async () => {
    mount(() => inSpace().useAssetTagsQuery({ sort: '-created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('caches under the filter-scoped list key', async () => {
    const harness = mount(() => inSpace().useAssetTagsQuery({ q: 'hero' }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ q: 'hero' }))).toBeDefined()
  })

  it('keeps the previous page visible while the next one loads', async () => {
    const params = ref({ page: 1 })
    index.mockResolvedValue({ data: [{ id: 't1' }], meta: { total: 1 } })
    const harness = mount(() => inSpace().useAssetTagsQuery(params))
    await flush()

    let release = () => {}
    index.mockImplementation(() => new Promise((resolve) => (release = () => resolve({ data: [] }))))
    params.value = { page: 2 }
    await nextTick()

    expect(harness.result.data.value).toEqual({ data: [{ id: 't1' }], meta: { total: 1 } })
    expect(harness.result.isPlaceholderData.value).toBe(true)

    release()
    await flush()
  })

  it('stays idle for an empty space id rather than requesting /spaces//asset-tags', async () => {
    mount(() => inSpace('').useAssetTagsQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()
  })
})

describe('useAssetTagQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: { id: 't1', name: 'Hero' } })

    const query = mount(() => inSpace().useAssetTagQuery('t1')).result
    await flush()

    expect(get).toHaveBeenCalledWith('t1')
    expect(query.data.value).toEqual({ id: 't1', name: 'Hero' })
  })

  it('unwraps a ref id into the key, so invalidation by plain id still matches', async () => {
    get.mockResolvedValue({ data: { id: 't1', name: 'Hero' } })
    // detail() keeps the MaybeRef as-is; vue-query deep-unrefs the key for us.
    const harness = mount(() => inSpace().useAssetTagQuery(ref('t1')))
    await flush()

    expect(harness.queryClient.getQueryData(keys.detail('t1'))).toEqual({ id: 't1', name: 'Hero' })
  })

  it('fetches even for an empty id — there is no enabled guard', async () => {
    get.mockResolvedValue({ data: null })

    mount(() => inSpace().useAssetTagQuery(''))
    await flush()

    expect(get).toHaveBeenCalledWith('')
  })
})

describe('useAssetsForTagQuery', () => {
  it('filters the asset index by the single tag', async () => {
    assetsIndex.mockResolvedValue({ data: [{ id: 'a1' }] })

    const query = mount(() => inSpace().useAssetsForTagQuery('t1')).result
    await flush()

    expect(assetsIndex).toHaveBeenCalledWith({ tags: ['t1'] })
    expect(query.data.value).toEqual([{ id: 'a1' }])
  })

  it('caches under the asset lists key with a tag filter, so tag mutations refresh it', async () => {
    const harness = mount(() => inSpace().useAssetsForTagQuery('t1'))
    await flush()

    const key = [...queryKeys.assets(SPACE).lists(), { tag: 't1' }]

    expect(harness.queryClient.getQueryData(key)).toBeDefined()
  })
})

describe('useCreateAssetTagMutation', () => {
  it('invalidates the lists and names the tag in the toast', async () => {
    create.mockResolvedValue({ data: { id: 't1', name: 'Hero' } })
    const harness = mount(() => inSpace().useCreateAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync({ name: 'Hero' })

    expect(create).toHaveBeenCalledWith({ name: 'Hero' })
    expect(result).toEqual({ id: 't1', name: 'Hero' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(success).toHaveBeenCalledWith('Tag "Hero" created successfully')
  })

  it('sends icon and colour through', async () => {
    create.mockResolvedValue({ data: { id: 't1', name: 'Hero' } })
    const harness = mount(() => inSpace().useCreateAssetTagMutation())

    await harness.result.mutateAsync({ name: 'Hero', icon: 'lucide:star', color: '#ff0000' })

    expect(create).toHaveBeenCalledWith({ name: 'Hero', icon: 'lucide:star', color: '#ff0000' })
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('duplicate'))
    const harness = mount(() => inSpace().useCreateAssetTagMutation())

    await expect(harness.result.mutateAsync({ name: 'Hero' })).rejects.toThrow('duplicate')
    expect(error).toHaveBeenCalledWith('Failed to create tag: duplicate')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const harness = mount(() => inSpace().useCreateAssetTagMutation())

    await harness.result.mutateAsync({ name: 'Hero' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create tag: Unknown error')
  })
})

describe('useUpdateAssetTagMutation', () => {
  it('invalidates the lists and that tag detail', async () => {
    update.mockResolvedValue({ data: { id: 't1', name: 'Renamed' } })
    const harness = mount(() => inSpace().useUpdateAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 't1', payload: { name: 'Renamed' } })

    expect(update).toHaveBeenCalledWith('t1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('t1') })
    expect(success).toHaveBeenCalledWith('Tag "Renamed" updated successfully')
  })

  it('refreshes the asset lists, which render the tag label and colour', async () => {
    update.mockResolvedValue({ data: { id: 't1', name: 'Renamed' } })
    const harness = mount(() => inSpace().useUpdateAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 't1', payload: { name: 'Renamed' } })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'x' } })
    const harness = mount(() => inSpace().useUpdateAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 't1', payload: { name: 'x' } })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('t1') })
  })

  it('does not invalidate when the update fails', async () => {
    update.mockRejectedValue(new Error('nope'))
    const harness = mount(() => inSpace().useUpdateAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 't1', payload: { name: 'x' } }).catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to update tag: nope')
  })
})

describe('useDeleteAssetTagMutation', () => {
  it('refreshes the tag lists, the asset lists, and drops the detail', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteAssetTagMutation(), [
      [keys.detail('t1'), { id: 't1' }],
    ])
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('t1')

    expect(destroy).toHaveBeenCalledWith('t1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    // Assets carry the tag, so their lists go stale too.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.detail('t1') })
    expect(harness.queryClient.getQueryData(keys.detail('t1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Tag deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const harness = mount(() => inSpace().useDeleteAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('t1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete tag: in use')
  })
})

describe('useAssignTagToAssetsMutation', () => {
  it('assigns the tag and refreshes both the assets and the tag counts', async () => {
    assign.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useAssignTagToAssetsMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync({ tagId: 't1', assetIds: ['a1', 'a2'] })

    expect(assign).toHaveBeenCalledWith('t1', ['a1', 'a2'])
    expect(result).toEqual({ tagId: 't1', assetIds: ['a1', 'a2'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Tag assigned successfully')
  })

  it('does not name a count — the copy is fixed regardless of selection size', async () => {
    assign.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useAssignTagToAssetsMutation())

    await harness.result.mutateAsync({ tagId: 't1', assetIds: [] })

    // No guard: an empty selection still hits the API and still toasts success.
    expect(assign).toHaveBeenCalledWith('t1', [])
    expect(success).toHaveBeenCalledWith('Tag assigned successfully')
  })

  it('reports the failure reason', async () => {
    assign.mockRejectedValue(new Error('missing asset'))
    const harness = mount(() => inSpace().useAssignTagToAssetsMutation())

    await harness.result.mutateAsync({ tagId: 't1', assetIds: ['a1'] }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to assign tag: missing asset')
  })
})

describe('query key shape', () => {
  it('scopes every key to the space', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'asset-tags'])
    expect(queryKeys.assetTags('a').lists()).not.toEqual(queryKeys.assetTags('b').lists())
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ q: 'x' })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('invalidates only the current space', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace('space-2').useDeleteAssetTagMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('t1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assetTags('space-2').lists() })
  })
})
