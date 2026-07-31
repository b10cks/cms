import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const restore = vi.fn()

const blockVersions = vi.fn(() => ({ index, get, update, delete: destroy, restore }))
const forSpace = vi.fn(() => ({ blockVersions }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useBlockVersions } = await import('~/composables/useBlockVersions')

const SPACE = 'space-1'
const BLOCK = 'block-1'
const keys = queryKeys.blockVersions(SPACE, BLOCK)
const blockKeys = queryKeys.blocks(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const version = (id: string, commit_message: string | null = null) =>
  ({ id, commit_message, created_at: '2026-01-01T00:00:00Z' }) as BlockVersion

type Composable = ReturnType<typeof useBlockVersions>
type Mutations = {
  update: ReturnType<Composable['useUpdateBlockVersionMutation']>
  restore: ReturnType<Composable['useRestoreBlockVersionMutation']>
  remove: ReturnType<Composable['useDeleteBlockVersionMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (
  spaceId: MaybeRef<string> = SPACE,
  blockId: MaybeRef<string> = BLOCK,
  seed?: Array<[readonly unknown[], unknown]>
) => {
  harness = withSetup<Mutations>(
    () => {
      const versions = useBlockVersions(spaceId, blockId)
      return {
        update: versions.useUpdateBlockVersionMutation(),
        restore: versions.useRestoreBlockVersionMutation(),
        remove: versions.useDeleteBlockVersionMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, get, update, destroy, restore, success, error]) fn.mockReset()
  forSpace.mockClear()
  blockVersions.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useBlockVersionsQuery', () => {
  it('always sorts newest first and unwraps the envelope', async () => {
    index.mockResolvedValue({ data: [version('v2'), version('v1')] })

    const query = withSetup(() => useBlockVersions(SPACE, BLOCK).useBlockVersionsQuery()).result
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
    expect(query.data.value).toEqual([version('v2'), version('v1')])
  })

  /**
   * The spread order is inverted compared to the sibling modules: the caller's
   * params come first, so a caller-supplied `sort` is overwritten. History is
   * always newest-first, whatever the caller asks for.
   */
  it('overrides a caller-supplied sort instead of honouring it', async () => {
    withSetup(() => useBlockVersions(SPACE, BLOCK).useBlockVersionsQuery({ sort: '+created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('still forwards the other filters', async () => {
    withSetup(() => useBlockVersions(SPACE, BLOCK).useBlockVersionsQuery({ created_by: 'u1' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ created_by: 'u1', sort: '-created_at' })
  })

  it('scopes the versions API to the space and the block', async () => {
    withSetup(() => useBlockVersions(SPACE, BLOCK).useBlockVersionsQuery())
    await flush()

    expect(forSpace).toHaveBeenCalledWith(SPACE)
    expect(blockVersions).toHaveBeenCalledWith(BLOCK)
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useBlockVersions(SPACE, BLOCK).useBlockVersionsQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  // The list query has no `enabled` guard: it fires even with no block.
  it('fires even without a block id', async () => {
    withSetup(() => useBlockVersions(SPACE, '').useBlockVersionsQuery())
    await flush()

    expect(blockVersions).toHaveBeenCalledWith('')
    expect(index).toHaveBeenCalled()
  })

  it('rekeys when the block changes, so two blocks never share a history', async () => {
    const blockId = ref(BLOCK)
    const local = withSetup(() => useBlockVersions(SPACE, blockId).useBlockVersionsQuery())

    await flush()
    blockId.value = 'block-2'
    await nextTick()
    await flush()

    expect(blockVersions).toHaveBeenLastCalledWith('block-2')
    expect(
      local.queryClient.getQueryData(queryKeys.blockVersions(SPACE, 'block-2').list({}))
    ).toBeDefined()
    local.unmount()
  })
})

describe('useBlockVersionQuery', () => {
  it('unwraps the data envelope for a single version', async () => {
    get.mockResolvedValue({ data: version('v1', 'initial') })

    const query = withSetup(() => useBlockVersions(SPACE, BLOCK).useBlockVersionQuery('v1')).result
    await flush()

    expect(query.data.value).toEqual(version('v1', 'initial'))
    expect(get).toHaveBeenCalledWith('v1')
  })

  it.each([
    ['space', '', BLOCK, 'v1'],
    ['block', SPACE, '', 'v1'],
    ['version', SPACE, BLOCK, ''],
  ])('stays idle without a %s id', async (_what, spaceId, blockId, versionId) => {
    const query = withSetup(() =>
      useBlockVersions(spaceId, blockId).useBlockVersionQuery(versionId)
    ).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('useUpdateBlockVersionMutation', () => {
  it('invalidates the version lists and that version detail', async () => {
    update.mockResolvedValue({ data: version('v1', 'better message') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      id: 'v1',
      payload: { commit_message: 'better message' } as UpdateBlockVersionPayload,
    })

    expect(update).toHaveBeenCalledWith('v1', { commit_message: 'better message' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('v1') })
    expect(success).toHaveBeenCalledWith('Version updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: version('server-id') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'v1', payload: {} as UpdateBlockVersionPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('v1') })
  })

  // Only the commit message is editable, so the block itself is untouched.
  it('does not invalidate the block detail', async () => {
    update.mockResolvedValue({ data: version('v1') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'v1', payload: {} as UpdateBlockVersionPayload })

    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: blockKeys.detail(BLOCK) })
  })

  it('clears the commit message when passed an explicit null', async () => {
    update.mockResolvedValue({ data: version('v1') })
    const { update: mutation } = setup()

    await mutation.mutateAsync({
      id: 'v1',
      payload: { commit_message: null } as UpdateBlockVersionPayload,
    })

    expect(update).toHaveBeenCalledWith('v1', { commit_message: null })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('too long'))
    const { update: mutation } = setup()

    await expect(
      mutation.mutateAsync({ id: 'v1', payload: {} as UpdateBlockVersionPayload })
    ).rejects.toThrow('too long')
    expect(error).toHaveBeenCalledWith('Failed to update version: too long')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    update.mockRejectedValue(new Error(''))
    const { update: mutation } = setup()

    await mutation.mutateAsync({ id: 'v1', payload: {} as UpdateBlockVersionPayload }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update version: Unknown error')
  })
})

describe('useRestoreBlockVersionMutation', () => {
  it('invalidates the version lists and the restored block detail', async () => {
    restore.mockResolvedValue(version('v1'))
    const { restore: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync('v1')

    expect(restore).toHaveBeenCalledWith('v1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.detail(BLOCK) })
    expect(success).toHaveBeenCalledWith('Version restored successfully')
  })

  /**
   * The API resource already unwraps `restore`, so this mutation returns the
   * version itself, not an { data } envelope like its siblings.
   */
  it('returns the restored version unwrapped', async () => {
    restore.mockResolvedValue(version('v1', 'restored'))
    const { restore: mutation } = setup()

    expect(await mutation.mutateAsync('v1')).toEqual(version('v1', 'restored'))
  })

  // Restoring writes a new block schema, but the block *lists* are not
  // invalidated — a blocks table showing the schema stays stale.
  it('leaves the block lists stale', async () => {
    restore.mockResolvedValue(version('v1'))
    const { restore: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync('v1')

    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
  })

  it('reports the failure reason', async () => {
    restore.mockRejectedValue(new Error('version is corrupt'))
    const { restore: mutation } = setup()

    await mutation.mutateAsync('v1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to restore version: version is corrupt')
    expect(success).not.toHaveBeenCalled()
  })

  it('does not invalidate when the restore fails', async () => {
    restore.mockRejectedValue(new Error('nope'))
    const { restore: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync('v1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('useDeleteBlockVersionMutation', () => {
  it('invalidates the version lists', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('v1')

    expect(destroy).toHaveBeenCalledWith('v1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(success).toHaveBeenCalledWith('Version deleted successfully')
  })

  // Unlike its siblings, delete never evicts the detail entry, so a deleted
  // version stays readable from the cache.
  it('leaves the deleted version detail in the cache — no removeQueries', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, BLOCK, [[keys.detail('v1'), version('v1')]])
    const removeQueries = vi.spyOn(harness!.queryClient, 'removeQueries')

    await remove.mutateAsync('v1')

    expect(removeQueries).not.toHaveBeenCalled()
    expect(harness?.queryClient.getQueryData(keys.detail('v1'))).toEqual(version('v1'))
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('is the current version'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('v1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete version: is the current version')
  })
})

describe('key shape', () => {
  it('nests versions under their block', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'blocks', BLOCK, 'versions'])
  })

  it('keys versions per block, so two blocks never share a history', () => {
    expect(queryKeys.blockVersions(SPACE, 'a').lists()).not.toEqual(
      queryKeys.blockVersions(SPACE, 'b').lists()
    )
  })

  /**
   * The version namespace lives *under* the block detail path, so invalidating
   * the block detail on restore does not also match the version lists — the
   * composable has to invalidate both, and it does.
   */
  it('shares the blocks prefix without being matched by the block detail key', () => {
    expect(blockKeys.detail(BLOCK)).toEqual(['spaces', SPACE, 'blocks', 'detail', BLOCK])
    expect(keys.all()).not.toEqual(expect.arrayContaining(['detail']))
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
