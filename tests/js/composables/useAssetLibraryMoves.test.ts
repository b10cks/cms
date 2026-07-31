import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const updateAsset = vi.fn(async () => ({}))
const updateFolder = vi.fn(async () => ({}))

// Only the two write paths are faked. useFolderStructure stays real, so the
// descendant checks under test run against a genuinely seeded query cache.
vi.mock('~/composables/useAssets', async () => {
  const actual = await vi.importActual<typeof import('~/composables/useAssets')>(
    '~/composables/useAssets'
  )

  return {
    ...actual,
    useAssets: (spaceId: Parameters<typeof actual.useAssets>[0]) => ({
      ...actual.useAssets(spaceId),
      useUpdateAssetMutation: () => ({ mutateAsync: updateAsset }),
    }),
  }
})

vi.mock('~/composables/useAssetFolders', async () => {
  const actual = await vi.importActual<typeof import('~/composables/useAssetFolders')>(
    '~/composables/useAssetFolders'
  )

  return {
    ...actual,
    useAssetFolders: (spaceId: Parameters<typeof actual.useAssetFolders>[0]) => ({
      ...actual.useAssetFolders(spaceId),
      useUpdateAssetFolderMutation: () => ({ mutateAsync: updateFolder }),
    }),
  }
})

const { useAssetLibraryMoves } = await import('~/composables/useAssetLibraryMoves')

const SPACE = 'space-1'

// a > a1 > a2, plus a standalone b
const folders = [
  { id: 'a', parent_id: null, name: 'a' },
  { id: 'a1', parent_id: 'a', name: 'a1' },
  { id: 'a2', parent_id: 'a1', name: 'a2' },
  { id: 'b', parent_id: null, name: 'b' },
] as unknown as AssetFolderResource[]

const asset = (id: string): AssetManagerDragItem => ({ id, type: 'asset' })
const folder = (id: string): AssetManagerDragItem => ({ id, type: 'folder' })

// Explicit, not ReturnType<typeof setup>: that would be circular, and TS would
// silently widen the composable's whole surface to `any`.
let harness: Harness<ReturnType<typeof useAssetLibraryMoves>> | undefined

const setup = (seeded: AssetFolderResource[] = folders) =>
  withSetup(() => useAssetLibraryMoves(SPACE), {
    seed: [[queryKeys.assetFolders(SPACE).list({}), seeded]],
  })

const moves = () => {
  harness = setup()
  return harness.result
}

beforeEach(() => {
  updateAsset.mockClear()
  updateFolder.mockClear()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('normalizeFolderIdsForMove', () => {
  it('drops a folder whose ancestor is also being moved', () => {
    expect(moves().normalizeFolderIdsForMove(['a', 'a1'])).toEqual(['a'])
  })

  it('drops a deeper descendant too', () => {
    expect(moves().normalizeFolderIdsForMove(['a', 'a2'])).toEqual(['a'])
  })

  it('keeps unrelated folders', () => {
    expect(moves().normalizeFolderIdsForMove(['a', 'b'])).toEqual(['a', 'b'])
  })

  it('keeps a descendant moved on its own', () => {
    expect(moves().normalizeFolderIdsForMove(['a2'])).toEqual(['a2'])
  })

  it('deduplicates ids', () => {
    expect(moves().normalizeFolderIdsForMove(['a', 'a'])).toEqual(['a'])
  })

  it('collapses a whole chain to its topmost folder', () => {
    expect(moves().normalizeFolderIdsForMove(['a2', 'a1', 'a'])).toEqual(['a'])
  })

  it('returns nothing for an empty list', () => {
    expect(moves().normalizeFolderIdsForMove([])).toEqual([])
  })

  it('keeps an id that is not a known folder', () => {
    expect(moves().normalizeFolderIdsForMove(['ghost'])).toEqual(['ghost'])
  })
})

describe('getMoveValidation', () => {
  it('accepts moving a folder into an unrelated folder', () => {
    expect(moves().getMoveValidation([folder('a')], 'b')).toEqual({
      invalidFolderIds: [],
      normalizedFolderIds: ['a'],
      valid: true,
    })
  })

  it('rejects moving a folder into itself', () => {
    const result = moves().getMoveValidation([folder('a')], 'a')

    expect(result.valid).toBe(false)
    expect(result.invalidFolderIds).toEqual(['a'])
  })

  it('rejects moving a folder into its own child', () => {
    expect(moves().getMoveValidation([folder('a')], 'a1').valid).toBe(false)
  })

  it('rejects moving a folder into a deeper descendant', () => {
    expect(moves().getMoveValidation([folder('a')], 'a2').valid).toBe(false)
  })

  it('accepts moving a child up to the root', () => {
    expect(moves().getMoveValidation([folder('a1')], null).valid).toBe(true)
  })

  it('accepts moving a child into its own parent — a no-op, not a cycle', () => {
    expect(moves().getMoveValidation([folder('a1')], 'a').valid).toBe(true)
  })

  it('reports only the offending folder in a mixed batch', () => {
    const result = moves().getMoveValidation([folder('a'), folder('b')], 'a2')

    expect(result.valid).toBe(false)
    expect(result.invalidFolderIds).toEqual(['a'])
    expect(result.normalizedFolderIds).toEqual(['a', 'b'])
  })

  it('ignores assets when validating, since they cannot cycle', () => {
    expect(moves().getMoveValidation([asset('img-1')], 'a')).toEqual({
      invalidFolderIds: [],
      normalizedFolderIds: [],
      valid: true,
    })
  })

  it('validates against the normalized set, not the raw one', () => {
    // a1 is dropped as a descendant of a, so only a is checked against a2.
    const result = moves().getMoveValidation([folder('a'), folder('a1')], 'a2')

    expect(result.normalizedFolderIds).toEqual(['a'])
    expect(result.invalidFolderIds).toEqual(['a'])
  })

  it('treats an unknown target as unrelated', () => {
    expect(moves().getMoveValidation([folder('a')], 'ghost').valid).toBe(true)
  })
})

describe('canMoveItems', () => {
  it('rejects an empty drag', () => {
    expect(moves().canMoveItems([], 'a')).toBe(false)
  })

  it('follows the validation result', () => {
    const result = moves()

    expect(result.canMoveItems([folder('a')], 'b')).toBe(true)
    expect(result.canMoveItems([folder('a')], 'a1')).toBe(false)
  })

  it('accepts an asset-only drag anywhere', () => {
    const result = moves()

    expect(result.canMoveItems([asset('img-1')], 'a2')).toBe(true)
    expect(result.canMoveItems([asset('img-1')], null)).toBe(true)
  })
})

describe('moveItemsToFolder', () => {
  it('reparents each folder and asset to the target', async () => {
    await moves().moveItemsToFolder([folder('a'), asset('img-1')], 'b')

    expect(updateFolder).toHaveBeenCalledWith({ id: 'a', payload: { parent_id: 'b' } })
    expect(updateAsset).toHaveBeenCalledWith({ id: 'img-1', payload: { folder_id: 'b' } })
  })

  it('moves to the root with a null target', async () => {
    await moves().moveItemsToFolder([folder('a1'), asset('img-1')], null)

    expect(updateFolder).toHaveBeenCalledWith({ id: 'a1', payload: { parent_id: null } })
    expect(updateAsset).toHaveBeenCalledWith({ id: 'img-1', payload: { folder_id: null } })
  })

  it('moves only the topmost folder — descendants travel with it', async () => {
    await moves().moveItemsToFolder([folder('a'), folder('a1'), folder('a2')], 'b')

    expect(updateFolder).toHaveBeenCalledTimes(1)
    expect(updateFolder).toHaveBeenCalledWith({ id: 'a', payload: { parent_id: 'b' } })
  })

  it('deduplicates repeated assets', async () => {
    await moves().moveItemsToFolder([asset('img-1'), asset('img-1')], 'b')

    expect(updateAsset).toHaveBeenCalledTimes(1)
  })

  it('throws and writes nothing when the move would cycle', async () => {
    await expect(moves().moveItemsToFolder([folder('a')], 'a2')).rejects.toThrow(
      'invalid-folder-move:a'
    )
    expect(updateFolder).not.toHaveBeenCalled()
    expect(updateAsset).not.toHaveBeenCalled()
  })

  it('rejects the whole batch when one folder would cycle, sparing the assets too', async () => {
    await expect(
      moves().moveItemsToFolder([folder('a'), folder('b'), asset('img-1')], 'a2')
    ).rejects.toThrow('invalid-folder-move:a')
    expect(updateAsset).not.toHaveBeenCalled()
  })

  it('does nothing for an empty drag', async () => {
    await moves().moveItemsToFolder([], 'b')

    expect(updateFolder).not.toHaveBeenCalled()
    expect(updateAsset).not.toHaveBeenCalled()
  })

  it('propagates a failing mutation', async () => {
    updateAsset.mockRejectedValueOnce(new Error('403'))

    await expect(moves().moveItemsToFolder([asset('img-1')], 'b')).rejects.toThrow('403')
  })
})

describe('without a loaded folder list', () => {
  it('refuses a folder move it cannot verify, instead of risking a cycle', () => {
    harness = setup([])

    // isDescendantOf has no folder map to walk, so a cycle cannot be ruled
    // out. Failing closed here matters: an allowed move would reparent a
    // folder into its own subtree with only the server left to object.
    expect(harness.result.canMoveItems([folder('a')], 'a1')).toBe(false)
  })

  it('reports every moved folder as invalid, not just the provable ones', () => {
    harness = setup([])

    const result = harness.result.getMoveValidation([folder('a'), folder('b')], 'a1')

    expect(result.valid).toBe(false)
    expect(result.invalidFolderIds).toEqual(['a', 'b'])
  })

  it('still rejects moving a folder into itself, which needs no lineage', () => {
    harness = setup([])

    expect(harness.result.canMoveItems([folder('a')], 'a')).toBe(false)
  })

  it('still allows moving to the root, where no cycle is possible', () => {
    harness = setup([])

    expect(harness.result.canMoveItems([folder('a')], null)).toBe(true)
  })

  it('still allows an asset-only move, which cannot cycle either', () => {
    harness = setup([])

    expect(harness.result.canMoveItems([asset('img-1')], 'a1')).toBe(true)
  })

  it('allows the same legal move once the folder list has resolved', () => {
    harness = setup()

    expect(harness.result.canMoveItems([folder('a')], 'b')).toBe(true)
  })
})
