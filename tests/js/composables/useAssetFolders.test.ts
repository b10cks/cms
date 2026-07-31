import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const assetFolders = {
  index: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
}

const success = vi.fn()
const failure = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ assetFolders }) } }))
vi.mock('vue-sonner', () => ({ toast: { success, error: failure } }))

const { useAssetFolders } = await import('~/composables/useAssetFolders')

const SPACE = 'space-1'

const folder = (id: string, parentId: string | null = null) =>
  ({ id, parent_id: parentId, name: id }) as unknown as AssetFolderResource

// a > a1 > a2, plus a standalone b
const tree = [folder('a'), folder('a1', 'a'), folder('a2', 'a1'), folder('b')]

type FolderStructure = ReturnType<ReturnType<typeof useAssetFolders>['useFolderStructure']>

let harness: Harness<ReturnType<typeof mountFolders>> | undefined
let treeHarness: Harness<FolderStructure> | undefined

const mountFolders = () => {
  const composable = useAssetFolders(SPACE)

  return {
    ...composable,
    create: composable.useCreateAssetFolderMutation(),
    update: composable.useUpdateAssetFolderMutation(),
    remove: composable.useDeleteAssetFolderMutation(),
  }
}

const setup = () => {
  harness = withSetup(mountFolders)
  return harness
}

const mutations = () => setup().result

const spyInvalidate = () => vi.spyOn((harness as Harness<unknown>).queryClient, 'invalidateQueries')

const invalidatedKeys = (spy: ReturnType<typeof spyInvalidate>) =>
  spy.mock.calls.map(([filters]) => (typeof filters === 'function' ? filters() : filters)?.queryKey)

const structure = (seeded: AssetFolderResource[] | null = tree) => {
  treeHarness = withSetup(() => useAssetFolders(SPACE).useFolderStructure(), {
    seed: seeded ? [[queryKeys.assetFolders(SPACE).list({}), seeded]] : [],
  })
  return treeHarness.result
}

beforeEach(() => {
  for (const fn of Object.values(assetFolders)) fn.mockReset()
  success.mockReset()
  failure.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  treeHarness?.unmount()
  treeHarness = undefined
})

describe('useAssetFoldersQuery', () => {
  it('caches the unwrapped array with a default name sort', async () => {
    assetFolders.index.mockResolvedValue({ data: tree })

    const { queryClient } = withSetup(() => useAssetFolders(SPACE).useAssetFoldersQuery())

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.assetFolders(SPACE).list({}))).toEqual(tree)
    )
    // The sort reaches the API but not the key, so the plain `{}` key is what
    // every other composable seeds and reads.
    expect(assetFolders.index).toHaveBeenCalledWith({ sort: '+name' })
    queryClient.clear()
  })

  it('keys by the caller filters and forwards them', async () => {
    assetFolders.index.mockResolvedValue({ data: [] })

    const { queryClient } = withSetup(() =>
      useAssetFolders(SPACE).useAssetFoldersQuery({ parent_id: 'a' })
    )

    await vi.waitFor(() =>
      expect(
        queryClient.getQueryData(queryKeys.assetFolders(SPACE).list({ parent_id: 'a' }))
      ).toEqual([])
    )
    expect(assetFolders.index).toHaveBeenCalledWith({ sort: '+name', parent_id: 'a' })
    queryClient.clear()
  })

  it('lets a caller filter override the default sort', async () => {
    assetFolders.index.mockResolvedValue({ data: [] })

    const { queryClient } = withSetup(() =>
      useAssetFolders(SPACE).useAssetFoldersQuery({ sort: '-name' })
    )

    await vi.waitFor(() => expect(assetFolders.index).toHaveBeenCalledWith({ sort: '-name' }))
    queryClient.clear()
  })
})

describe('useAssetFolderQuery', () => {
  it('unwraps the envelope under the detail key', async () => {
    assetFolders.get.mockResolvedValue({ data: folder('a') })

    const { queryClient } = withSetup(() => useAssetFolders(SPACE).useAssetFolderQuery('a'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.assetFolders(SPACE).detail('a'))).toMatchObject({
        id: 'a',
      })
    )
    queryClient.clear()
  })

  it('stays disabled for an empty id instead of requesting one', async () => {
    // A caller that renders before its id resolves must not fire
    // GET …/asset-folders/ — same guard useAssetQuery has.
    assetFolders.get.mockResolvedValue({ data: null })

    const { queryClient, result } = withSetup(() => useAssetFolders(SPACE).useAssetFolderQuery(''))

    expect(result.isFetching.value).toBe(false)
    expect(assetFolders.get).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useCreateAssetFolderMutation', () => {
  it('invalidates only the folder lists', async () => {
    assetFolders.create.mockResolvedValue({ data: folder('new') })
    const { create } = mutations()
    const invalidate = spyInvalidate()

    await create.mutateAsync({ name: 'new' } as never)

    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.assetFolders(SPACE).lists()])
    expect(success).toHaveBeenCalledWith('Folder "new" created successfully')
  })

  it('reports failure', async () => {
    assetFolders.create.mockRejectedValue(new Error('duplicate'))

    await expect(mutations().create.mutateAsync({ name: 'x' } as never)).rejects.toThrow('duplicate')
    expect(failure).toHaveBeenCalledWith('Failed to create folder: duplicate')
  })

  it('falls back to "Unknown error" for an empty message', async () => {
    assetFolders.create.mockRejectedValue(new Error(''))

    await expect(mutations().create.mutateAsync({ name: 'x' } as never)).rejects.toThrow()
    expect(failure).toHaveBeenCalledWith('Failed to create folder: Unknown error')
  })
})

describe('useUpdateAssetFolderMutation', () => {
  it('invalidates the lists and the moved folder detail', async () => {
    assetFolders.update.mockResolvedValue({ data: folder('a1', 'b') })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'a1', payload: { parent_id: 'b' } as never })

    expect(assetFolders.update).toHaveBeenCalledWith('a1', { parent_id: 'b' })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.assetFolders(SPACE).lists(),
      queryKeys.assetFolders(SPACE).detail('a1'),
      queryKeys.assets(SPACE).lists(),
    ])
    expect(success).toHaveBeenCalledWith('Folder "a1" updated successfully')
  })

  it('refreshes the asset lists after a folder move', async () => {
    // Reparenting a folder changes which folder its assets are browsed under.
    assetFolders.update.mockResolvedValue({ data: folder('a1', 'b') })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'a1', payload: {} as never })

    expect(invalidatedKeys(invalidate)).toContainEqual(queryKeys.assets(SPACE).lists())
  })

  it('reports failure', async () => {
    assetFolders.update.mockRejectedValue(new Error('cycle'))

    await expect(
      mutations().update.mutateAsync({ id: 'a1', payload: {} as never })
    ).rejects.toThrow('cycle')
    expect(failure).toHaveBeenCalledWith('Failed to update folder: cycle')
  })
})

describe('useDeleteAssetFolderMutation', () => {
  it('drops the detail cache and invalidates the lists', async () => {
    assetFolders.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync('a')

    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.assetFolders(SPACE).lists()])
    expect(removeQueries).toHaveBeenCalledWith({
      queryKey: queryKeys.assetFolders(SPACE).detail('a'),
    })
    expect(success).toHaveBeenCalledWith('Folder deleted successfully')
  })

  it('really evicts the seeded detail', async () => {
    assetFolders.delete.mockResolvedValue(undefined)
    const detail = queryKeys.assetFolders(SPACE).detail('a')

    harness = withSetup(mountFolders, { seed: [[detail, folder('a')]] })

    await harness.result.remove.mutateAsync('a')

    expect(harness.queryClient.getQueryData(detail)).toBeUndefined()
  })

  it('leaves a deleted child folder detail in the cache', async () => {
    // Pinned: deleting a parent cascades server-side, but only the requested
    // id is evicted — a child detail read from cache still looks alive.
    assetFolders.delete.mockResolvedValue(undefined)
    const child = queryKeys.assetFolders(SPACE).detail('a1')

    harness = withSetup(mountFolders, { seed: [[child, folder('a1', 'a')]] })

    await harness.result.remove.mutateAsync('a')

    expect(harness.queryClient.getQueryData(child)).toMatchObject({ id: 'a1' })
  })

  it('reports failure', async () => {
    assetFolders.delete.mockRejectedValue(new Error('not empty'))

    await expect(mutations().remove.mutateAsync('a')).rejects.toThrow('not empty')
    expect(failure).toHaveBeenCalledWith('Failed to delete folder: not empty')
  })
})

describe('useFolderStructure', () => {
  it('indexes every folder by id', () => {
    const { folderMap } = structure()

    expect([...folderMap.value.keys()]).toEqual(['a', 'a1', 'a2', 'b'])
    expect(folderMap.value.get('a1')).toMatchObject({ parent_id: 'a' })
  })

  it('lists only the parentless folders as roots', () => {
    expect(structure().rootFolders.value.map((entry) => entry.id)).toEqual(['a', 'b'])
  })

  it('treats an undefined parent_id as a root', () => {
    const orphan = { id: 'o', name: 'o' } as unknown as AssetFolderResource

    expect(structure([orphan]).rootFolders.value.map((entry) => entry.id)).toEqual(['o'])
  })

  it('returns the direct children of a folder', () => {
    const { getChildrenOfFolder } = structure()

    expect(getChildrenOfFolder('a').map((entry) => entry.id)).toEqual(['a1'])
    expect(getChildrenOfFolder('a2')).toEqual([])
  })

  it('returns the roots for a null parent', () => {
    expect(structure().getChildrenOfFolder(null).map((entry) => entry.id)).toEqual(['a', 'b'])
  })

  it('treats a missing parent_id as a root, exactly like rootFolders does', () => {
    // A payload that omits parent_id used to be a root that was nobody's child.
    const orphan = { id: 'o', name: 'o' } as unknown as AssetFolderResource
    const folders = structure([orphan])

    expect(folders.getChildrenOfFolder(null).map((entry) => entry.id)).toContain('o')
    expect(folders.rootFolders.value.map((entry) => entry.id)).toContain('o')
  })

  it('builds a breadcrumb trail from the root down to the folder', () => {
    expect(structure().getBreadcrumbs('a2').map((entry) => entry.id)).toEqual(['a', 'a1', 'a2'])
  })

  it('returns just the folder itself for a root', () => {
    expect(structure().getBreadcrumbs('a').map((entry) => entry.id)).toEqual(['a'])
  })

  it('returns nothing for an unknown folder', () => {
    expect(structure().getBreadcrumbs('ghost')).toEqual([])
  })

  it('stops at a missing ancestor instead of failing', () => {
    const detached = [folder('lost', 'gone')]

    expect(structure(detached).getBreadcrumbs('lost').map((entry) => entry.id)).toEqual(['lost'])
  })

  it('terminates on a parent cycle instead of building an endless trail', () => {
    // A corrupt chain (self-parent, or x → y → x) used to spin forever and
    // grow the breadcrumb array without bound, hanging the tab.
    const selfParent = structure([folder('loop', 'loop')])
    expect(selfParent.getBreadcrumbs('loop').map((entry) => entry.id)).toEqual(['loop'])

    const pair = structure([folder('x', 'y'), folder('y', 'x')])
    expect(pair.getBreadcrumbs('x').map((entry) => entry.id)).toEqual(['y', 'x'])
  })

  it('terminates isDescendantOf on a parent cycle', () => {
    // Same guard, and the one that matters: this backs the folder-move cycle
    // check, so a hang here would freeze a drag-drop.
    expect(structure([folder('loop', 'loop')]).isDescendantOf('loop', 'other')).toBe(false)

    const pair = structure([folder('x', 'y'), folder('y', 'x')])
    expect(pair.isDescendantOf('x', 'other')).toBe(false)
    // A real ancestor inside the cycle is still found.
    expect(pair.isDescendantOf('x', 'y')).toBe(true)
  })

  it('walks the ancestry to decide descendancy', () => {
    const { isDescendantOf } = structure()

    expect(isDescendantOf('a1', 'a')).toBe(true)
    expect(isDescendantOf('a2', 'a')).toBe(true)
    expect(isDescendantOf('a', 'a1')).toBe(false)
    expect(isDescendantOf('b', 'a')).toBe(false)
  })

  it('does not treat a folder as its own descendant', () => {
    expect(structure().isDescendantOf('a', 'a')).toBe(false)
  })

  it('returns false for folders it has never heard of', () => {
    const { isDescendantOf } = structure()

    expect(isDescendantOf('ghost', 'a')).toBe(false)
    expect(isDescendantOf('a2', 'ghost')).toBe(false)
  })

  it('cannot judge anything before the list loads', () => {
    // The guard is only as good as the cached list: with nothing loaded every
    // move looks legal, which is what useAssetLibraryMoves inherits.
    const { isDescendantOf, rootFolders, getBreadcrumbs } = structure([])

    expect(isDescendantOf('a2', 'a')).toBe(false)
    expect(rootFolders.value).toEqual([])
    expect(getBreadcrumbs('a')).toEqual([])
  })

  it('exposes the query loading state and error', async () => {
    assetFolders.index.mockReturnValue(new Promise(() => {}))

    const { isLoading, error, folders } = structure(null)

    await vi.waitFor(() => expect(isLoading.value).toBe(true))
    expect(error.value).toBeNull()
    expect(folders.value).toBeUndefined()
  })
})
