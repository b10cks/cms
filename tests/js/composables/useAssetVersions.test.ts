import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const restore = vi.fn()

const assetVersions = vi.fn(() => ({ index, restore }))
const forSpace = vi.fn(() => ({ assetVersions }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAssetVersions } = await import('~/composables/useAssetVersions')

const SPACE = 'space-1'
const ASSET = 'asset-1'
const keys = queryKeys.assetVersions(SPACE, ASSET)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const mounted: Array<() => void> = []

/** Factories call useQuery/useMutation, so they must be built inside setup(). */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const forAsset = (
  assetId: MaybeRef<string | null | undefined> = ASSET,
  spaceId: MaybeRef<string> = SPACE
) => useAssetVersions(spaceId, assetId)

beforeEach(() => {
  for (const fn of [index, restore, success, error]) fn.mockReset()
  assetVersions.mockClear()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
})

describe('useAssetVersionsQuery', () => {
  it('always asks for newest-version-first', async () => {
    mount(() => forAsset().useAssetVersionsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-version_number' })
  })

  it('lets a caller sort win over the newest-first default', async () => {
    mount(() => forAsset().useAssetVersionsQuery({ sort: '+created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+created_at' })
  })

  it('keeps other caller params', async () => {
    mount(() => forAsset().useAssetVersionsQuery({ page: 2, per_page: 10 }))
    await flush()

    expect(index).toHaveBeenCalledWith({ page: 2, per_page: 10, sort: '-version_number' })
  })

  it('scopes the versions API to the space and the asset', async () => {
    mount(() => forAsset().useAssetVersionsQuery())
    await flush()

    expect(forSpace).toHaveBeenCalledWith(SPACE)
    expect(assetVersions).toHaveBeenCalledWith(ASSET)
  })

  it('caches under the asset-scoped list key', async () => {
    const harness = mount(() => forAsset().useAssetVersionsQuery({ page: 2 }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'v1' }], meta: { total: 1 } })

    const query = mount(() => forAsset().useAssetVersionsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'v1' }], meta: { total: 1 } })
  })

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['an empty string', ''],
  ])('stays idle for %s asset id', async (_label, assetId) => {
    const query = mount(() => useAssetVersions(SPACE, assetId).useAssetVersionsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => forAsset().useAssetVersionsQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('keys a missing asset id as the empty string', () => {
    const harness = mount(() => forAsset(null).useAssetVersionsQuery())
    const key = queryKeys.assetVersions(SPACE, '').list({})

    expect(harness.queryClient.getQueryCache().find({ queryKey: key })).toBeDefined()
  })

  it('starts fetching once the asset id appears', async () => {
    const assetId = ref<string | null>(null)
    const harness = mount(() => forAsset(assetId).useAssetVersionsQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()

    assetId.value = ASSET
    await nextTick()
    await flush()

    expect(index).toHaveBeenCalledTimes(1)
    expect(harness.result.fetchStatus.value).toBe('idle')
  })

  it('keeps the previous versions visible while a new page loads', async () => {
    const params = ref({ page: 1 })
    index.mockResolvedValue({ data: [{ id: 'v1' }] })
    const harness = mount(() => forAsset().useAssetVersionsQuery(params))
    await flush()

    let release = () => {}
    index.mockImplementation(() => new Promise((resolve) => (release = () => resolve({ data: [] }))))
    params.value = { page: 2 }
    await nextTick()

    expect(harness.result.data.value).toEqual({ data: [{ id: 'v1' }] })
    expect(harness.result.isPlaceholderData.value).toBe(true)

    release()
    await flush()
  })
})

describe('useRestoreAssetVersionMutation', () => {
  it('restores the version and refreshes versions, asset lists and the asset detail', async () => {
    restore.mockResolvedValue({ id: ASSET, filename: 'restored.png' })
    const harness = mount(() => forAsset().useRestoreAssetVersionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync('v2')

    expect(restore).toHaveBeenCalledWith('v2')
    expect(result).toEqual({ id: ASSET, filename: 'restored.png' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).detail(ASSET) })
    expect(invalidate).toHaveBeenCalledTimes(3)
    expect(success).toHaveBeenCalledWith('Asset version restored successfully')
  })

  it('returns the restored asset as the mutation result', async () => {
    restore.mockResolvedValue({ id: ASSET })
    const harness = mount(() => forAsset().useRestoreAssetVersionMutation())

    // onSuccess returns the asset too, but the mutation result is what callers see.
    await expect(harness.result.mutateAsync('v2')).resolves.toEqual({ id: ASSET })
  })

  it('does not invalidate when the restore fails', async () => {
    restore.mockRejectedValue(new Error('version gone'))
    const harness = mount(() => forAsset().useRestoreAssetVersionMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await expect(harness.result.mutateAsync('v2')).rejects.toThrow('version gone')

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to restore asset version: version gone')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    restore.mockRejectedValue(new Error(''))
    const harness = mount(() => forAsset().useRestoreAssetVersionMutation())

    await harness.result.mutateAsync('v2').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to restore asset version: Unknown error')
  })

  it('refuses to restore without an asset, rather than POSTing to /assets//versions', async () => {
    restore.mockResolvedValue({})
    const harness = mount(() => forAsset(null).useRestoreAssetVersionMutation())

    await expect(harness.result.mutateAsync('v2')).rejects.toThrow('No asset selected')

    expect(restore).not.toHaveBeenCalled()
  })
})

describe('query key shape', () => {
  it('scopes the key to both the space and the asset', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'assets', 'detail', ASSET, 'versions'])
    expect(queryKeys.assetVersions(SPACE, 'a').lists()).not.toEqual(
      queryKeys.assetVersions(SPACE, 'b').lists()
    )
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ page: 2 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('nests under the asset detail key, so invalidating the asset reaches its versions', () => {
    expect(keys.all()).toEqual([...queryKeys.assets(SPACE).detail(ASSET), 'versions'])
    expect(queryKeys.assets(SPACE).detail(ASSET)).toEqual([
      'spaces',
      SPACE,
      'assets',
      'detail',
      ASSET,
    ])
  })
})
