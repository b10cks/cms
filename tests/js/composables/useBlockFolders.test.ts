import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { BlockFolderResource, UpsertBlockFolderPayload } from '~/api/resources/block-folders'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({
  blockFolders: { index, get, create, update, delete: destroy },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useBlockFolders } = await import('~/composables/useBlockFolders')

const SPACE = 'space-1'
const keys = queryKeys.blockFolders(SPACE)
const blockKeys = queryKeys.blocks(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const folder = (id: string, parent_id: string | null = null, name = id) =>
  ({ id, name, parent_id }) as BlockFolderResource

type Composable = ReturnType<typeof useBlockFolders>
type Structure = ReturnType<Composable['useFolderStructure']>
type Mutations = {
  create: ReturnType<Composable['useCreateBlockFolderMutation']>
  update: ReturnType<Composable['useUpdateBlockFolderMutation']>
  remove: ReturnType<Composable['useDeleteBlockFolderMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const folders = useBlockFolders(spaceId)
      return {
        create: folders.useCreateBlockFolderMutation(),
        update: folders.useUpdateBlockFolderMutation(),
        remove: folders.useDeleteBlockFolderMutation(),
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

describe('useBlockFoldersQuery', () => {
  it('sorts by name ascending by default and unwraps the envelope', async () => {
    index.mockResolvedValue({ data: [folder('f1')] })

    const query = withSetup(() => useBlockFolders(SPACE).useBlockFoldersQuery()).result
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
    expect(query.data.value).toEqual([folder('f1')])
  })

  it('lets the caller override the default sort', async () => {
    withSetup(() => useBlockFolders(SPACE).useBlockFoldersQuery({ sort: '-name' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-name' })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useBlockFolders(SPACE).useBlockFoldersQuery({ parent_id: 'f1' }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ parent_id: 'f1' }))).toBeDefined()
    local.unmount()
  })

  // No `enabled` guard: an empty space id still resolves an API client and fires.
  it('fires even without a space id', async () => {
    withSetup(() => useBlockFolders('').useBlockFoldersQuery())
    await flush()

    expect(forSpace).toHaveBeenCalledWith('')
    expect(index).toHaveBeenCalled()
  })

  /**
   * The key is built eagerly, not inside a computed, so a reactive space id is
   * captured as a ref. vue-query deep-unrefs the options, so the key still
   * resolves to the current space — but the *seeded* key must use the same
   * unwrapped shape.
   */
  it('resolves a ref space id to the plain key', async () => {
    const spaceId = ref(SPACE)
    const local = withSetup(() => useBlockFolders(spaceId).useBlockFoldersQuery())
    await flush()

    expect(local.queryClient.getQueryData(keys.list({}))).toBeDefined()
    local.unmount()
  })
})

describe('useBlockFolderQuery', () => {
  it('unwraps the data envelope for a single folder', async () => {
    get.mockResolvedValue({ data: folder('f1') })

    const query = withSetup(() => useBlockFolders(SPACE).useBlockFolderQuery('f1')).result
    await flush()

    expect(query.data.value).toEqual(folder('f1'))
    expect(get).toHaveBeenCalledWith('f1')
  })

  it('stays idle without an id', async () => {
    const query = withSetup(() => useBlockFolders(SPACE).useBlockFolderQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('useCreateBlockFolderMutation', () => {
  const payload = { name: 'Layout', parent_id: null } as UpsertBlockFolderPayload

  it('invalidates the folder lists and the block lists', async () => {
    create.mockResolvedValue({ data: folder('f1', null, 'Layout') })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    // Blocks render their folder, so the block lists go stale too.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
    expect(success).toHaveBeenCalledWith('Folder "Layout" created successfully')
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('name taken'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('name taken')
    expect(error).toHaveBeenCalledWith('Failed to create folder: name taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create folder: Unknown error')
  })
})

describe('useUpdateBlockFolderMutation', () => {
  it('invalidates the folder lists, the detail and the block lists', async () => {
    update.mockResolvedValue({ data: folder('f1', null, 'Renamed') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      folderId: 'f1',
      payload: { name: 'Renamed' } as UpsertBlockFolderPayload,
    })

    expect(update).toHaveBeenCalledWith('f1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('f1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
    expect(success).toHaveBeenCalledWith('Folder "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: folder('server-id') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ folderId: 'f1', payload: {} as UpsertBlockFolderPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('f1') })
  })

  // Moving a folder re-parents its children, but their detail entries are not
  // invalidated — only the flat list is.
  it('leaves a child folder detail stale after a move', async () => {
    update.mockResolvedValue({ data: folder('f1', 'f9') })
    const { update: mutation } = setup(SPACE, [[keys.detail('child'), folder('child', 'f1')]])

    await mutation.mutateAsync({
      folderId: 'f1',
      payload: { parent_id: 'f9' } as UpsertBlockFolderPayload,
    })

    expect(harness?.queryClient.getQueryData(keys.detail('child'))).toEqual(folder('child', 'f1'))
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('cycle detected'))
    const { update: mutation } = setup()

    await mutation
      .mutateAsync({ folderId: 'f1', payload: {} as UpsertBlockFolderPayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update folder: cycle detected')
  })
})

describe('useDeleteBlockFolderMutation', () => {
  it('invalidates the lists, evicts the detail and refreshes the blocks', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('f1'), folder('f1')]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('f1')

    expect(destroy).toHaveBeenCalledWith('f1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: blockKeys.lists() })
    expect(harness?.queryClient.getQueryData(keys.detail('f1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Folder deleted successfully')
  })

  it('evicts only the deleted folder, not its children', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [
      [keys.detail('f1'), folder('f1')],
      [keys.detail('child'), folder('child', 'f1')],
    ])

    await remove.mutateAsync('f1')

    expect(harness?.queryClient.getQueryData(keys.detail('child'))).toEqual(folder('child', 'f1'))
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('not empty'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('f1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete folder: not empty')
  })
})

describe('useFolderStructure', () => {
  const tree = [
    folder('root-a'),
    folder('root-b'),
    folder('child-a', 'root-a'),
    folder('grandchild', 'child-a'),
    folder('orphan', 'missing-parent'),
  ]

  let local: Harness<Structure> | undefined

  const structure = async (folders = tree) => {
    index.mockResolvedValue({ data: folders })
    local = withSetup<Structure>(() => useBlockFolders(SPACE).useFolderStructure())
    await flush()
    return local.result
  }

  afterEach(() => {
    local?.unmount()
    local = undefined
  })

  it('exposes only the parentless folders as roots', async () => {
    const result = await structure()

    expect(result.rootFolders.value.map((entry) => entry.id)).toEqual(['root-a', 'root-b'])
  })

  it('lists the direct children of a folder', async () => {
    const result = await structure()

    expect(result.getChildrenOfFolder('root-a').map((entry) => entry.id)).toEqual(['child-a'])
    expect(result.getChildrenOfFolder('grandchild')).toEqual([])
  })

  it('treats null as "the roots" for getChildrenOfFolder', async () => {
    const result = await structure()

    expect(result.getChildrenOfFolder(null).map((entry) => entry.id)).toEqual(['root-a', 'root-b'])
  })

  it('builds breadcrumbs from the root down to the folder', async () => {
    const result = await structure()

    expect(result.getBreadcrumbs('grandchild').map((entry) => entry.id)).toEqual([
      'root-a',
      'child-a',
      'grandchild',
    ])
  })

  it('returns a single crumb for a root folder', async () => {
    const result = await structure()

    expect(result.getBreadcrumbs('root-a').map((entry) => entry.id)).toEqual(['root-a'])
  })

  it('returns nothing for an unknown folder', async () => {
    const result = await structure()

    expect(result.getBreadcrumbs('nope')).toEqual([])
  })

  // A dangling parent_id stops the walk instead of looping or throwing; the
  // partial trail keeps the orphan itself.
  it('stops the walk at a parent that is not in the list', async () => {
    const result = await structure()

    expect(result.getBreadcrumbs('orphan').map((entry) => entry.id)).toEqual(['orphan'])
  })

  it('is empty before the folders have loaded', () => {
    index.mockReturnValue(new Promise(() => {}))
    const pending = withSetup<Structure>(() => useBlockFolders(SPACE).useFolderStructure())

    expect(pending.result.rootFolders.value).toEqual([])
    expect(pending.result.getChildrenOfFolder(null)).toEqual([])
    expect(pending.result.getBreadcrumbs('root-a')).toEqual([])
    pending.unmount()
  })

  // NOTE: getBreadcrumbs has no cycle guard. A folder whose parent_id points at
  // itself (or any parent_id cycle) spins forever, so that case is deliberately
  // not exercised here — it would hang the runner rather than fail.
})

describe('space scoping', () => {
  it('keys folders per space, so two spaces never share a list', () => {
    expect(queryKeys.blockFolders('a').lists()).not.toEqual(queryKeys.blockFolders('b').lists())
  })

  it('invalidates only the current space on delete', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('f1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.blocks('space-2').lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
