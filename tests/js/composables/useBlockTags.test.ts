import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { UpsertBlockTagPayload } from '~/api/resources/block-tags'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({
  blockTags: { index, get, create, update, delete: destroy },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useBlockTags } = await import('~/composables/useBlockTags')

const SPACE = 'space-1'
const keys = queryKeys.blockTags(SPACE)
const blockKeys = queryKeys.blocks(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useBlockTags>
type Mutations = {
  create: ReturnType<Composable['useCreateBlockTagMutation']>
  update: ReturnType<Composable['useUpdateBlockTagMutation']>
  remove: ReturnType<Composable['useDeleteBlockTagMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const tags = useBlockTags(spaceId)
      return {
        create: tags.useCreateBlockTagMutation(),
        update: tags.useUpdateBlockTagMutation(),
        remove: tags.useDeleteBlockTagMutation(),
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

describe('useBlockTagsQuery', () => {
  it('sorts by name ascending by default', async () => {
    withSetup(() => useBlockTags(SPACE).useBlockTagsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets the caller override the default sort', async () => {
    withSetup(() => useBlockTags(SPACE).useBlockTagsQuery({ sort: '-name' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-name' })
  })

  it('keeps the whole envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ name: 'layout' }], meta: { total: 1 } })

    const query = withSetup(() => useBlockTags(SPACE).useBlockTagsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ name: 'layout' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useBlockTags(SPACE).useBlockTagsQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  // No `enabled` guard: an empty space id still fires.
  it('fires even without a space id', async () => {
    withSetup(() => useBlockTags('').useBlockTagsQuery())
    await flush()

    expect(forSpace).toHaveBeenCalledWith('')
    expect(index).toHaveBeenCalled()
  })

  /**
   * The key is built eagerly rather than inside a computed, so a params ref is
   * captured as a ref. vue-query deep-unrefs the options, so the query still
   * lands on the unwrapped key.
   */
  it('resolves a ref of params to the plain key', async () => {
    const params = ref({ page: 2 })
    const local = withSetup(() => useBlockTags(SPACE).useBlockTagsQuery(params))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })
})

describe('useBlockTagQuery', () => {
  it('is keyed and fetched by tag name, not by id', async () => {
    get.mockResolvedValue({ data: { name: 'layout', blocks_count: 2 } })

    const local = withSetup(() => useBlockTags(SPACE).useBlockTagQuery('layout'))
    await flush()

    expect(get).toHaveBeenCalledWith('layout')
    expect(local.queryClient.getQueryData(keys.detail('layout'))).toEqual({
      name: 'layout',
      blocks_count: 2,
    })
    local.unmount()
  })

  it('stays idle without a tag name', async () => {
    const query = withSetup(() => useBlockTags(SPACE).useBlockTagQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('useCreateBlockTagMutation', () => {
  const payload = { name: 'layout' } as unknown as UpsertBlockTagPayload

  it('invalidates the tag lists and the block lists', async () => {
    create.mockResolvedValue({ data: { name: 'layout' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    // Blocks render their tags, so the block lists go stale too.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
    expect(success).toHaveBeenCalledWith('Tag "layout" created successfully')
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('name taken'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('name taken')
    expect(error).toHaveBeenCalledWith('Failed to create tag: name taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create tag: Unknown error')
  })
})

describe('useUpdateBlockTagMutation', () => {
  /**
   * A tag is identified by its name, so a rename moves its cache entry. Both the
   * old name (from the variables) and the new one (from the response) have to be
   * invalidated, or the renamed tag keeps serving the old payload.
   */
  it('invalidates both the old and the new tag name on a rename', async () => {
    update.mockResolvedValue({ data: { name: 'structure' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      tagName: 'layout',
      payload: { name: 'structure' } as unknown as UpsertBlockTagPayload,
    })

    expect(update).toHaveBeenCalledWith('layout', { name: 'structure' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('layout') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('structure') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
    expect(success).toHaveBeenCalledWith('Tag "structure" updated successfully')
  })

  it('invalidates the same name twice when nothing was renamed', async () => {
    update.mockResolvedValue({ data: { name: 'layout' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      tagName: 'layout',
      payload: { description: 'x' } as unknown as UpsertBlockTagPayload,
    })

    const detailCalls = invalidate.mock.calls.filter((call) =>
      (call[0] as { queryKey: readonly unknown[] }).queryKey.includes('layout')
    )

    expect(detailCalls).toHaveLength(2)
  })

  // The old name is only *invalidated*, never removed, so a renamed tag leaves a
  // stale entry behind that refetches into a 404.
  it('does not evict the old name from the cache', async () => {
    update.mockResolvedValue({ data: { name: 'structure' } })
    const { update: mutation } = setup(SPACE, [[keys.detail('layout'), { name: 'layout' }]])
    const removeQueries = vi.spyOn(harness!.queryClient, 'removeQueries')

    await mutation.mutateAsync({
      tagName: 'layout',
      payload: { name: 'structure' } as unknown as UpsertBlockTagPayload,
    })

    expect(removeQueries).not.toHaveBeenCalled()
    expect(harness?.queryClient.getQueryData(keys.detail('layout'))).toEqual({ name: 'layout' })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('conflict'))
    const { update: mutation } = setup()

    await mutation
      .mutateAsync({ tagName: 'layout', payload: {} as unknown as UpsertBlockTagPayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update tag: conflict')
  })
})

describe('useDeleteBlockTagMutation', () => {
  it('deletes by name, evicts that name and refreshes the blocks', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('layout'), { name: 'layout' }]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('layout')

    expect(destroy).toHaveBeenCalledWith('layout')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
    expect(harness?.queryClient.getQueryData(keys.detail('layout'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Tag deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('still in use'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('layout').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete tag: still in use')
  })
})

describe('key shape', () => {
  it('keys tags per space, so two spaces never share a list', () => {
    expect(queryKeys.blockTags('a').lists()).not.toEqual(queryKeys.blockTags('b').lists())
  })

  // The detail key holds the tag *name*, so a tag called 'list' would collide
  // with the lists() prefix — invalidating lists() would also match it.
  it('puts the tag name in the detail key', () => {
    expect(keys.detail('layout')).toEqual(['spaces', SPACE, 'block-tags', 'detail', 'layout'])
  })

  it('invalidates only the current space on delete', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('layout')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.blocks('space-2').lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
