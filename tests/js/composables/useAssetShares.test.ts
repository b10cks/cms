import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AssetShareResource } from '~/types/asset-distribution'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const revoke = vi.fn()

const forSpace = vi.fn(() => ({
  assetShares: { index, get, create, update, delete: destroy, revoke },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAssetShares, buildShareUrl } = await import('~/composables/useAssetShares')

const SPACE = 'space-1'
const keys = queryKeys.assetShares(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const share = (extra: Record<string, unknown> = {}) =>
  ({ id: 's1', name: 'Press kit', token: 'tok_abc', ...extra }) as unknown as AssetShareResource

const mounted: Array<() => void> = []

/** Factories call useQuery/useMutation, so they must be built inside setup(). */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const inSpace = (spaceId: MaybeRef<string> = SPACE) => useAssetShares(spaceId)

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, revoke, success, error]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
})

describe('buildShareUrl', () => {
  it('includes the space id, because shares live in the space database', () => {
    expect(buildShareUrl(SPACE, { token: 'tok_abc' })).toBe(
      `${window.location.origin}/share/space-1/tok_abc`
    )
  })

  it('does not escape the token — it is expected to be URL-safe already', () => {
    expect(buildShareUrl(SPACE, { token: 'a/b?c' })).toBe(
      `${window.location.origin}/share/space-1/a/b?c`
    )
  })
})

describe('useAssetSharesQuery', () => {
  it('passes the caller params straight through — no default sort', async () => {
    mount(() => inSpace().useAssetSharesQuery({ source_type: 'collection' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ source_type: 'collection' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('caches under the filter-scoped list key', async () => {
    const harness = mount(() => inSpace().useAssetSharesQuery({ q: 'press' }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ q: 'press' }))).toBeDefined()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ q: 'a' })
    const harness = mount(() => inSpace().useAssetSharesQuery(params))
    await flush()

    params.value = { q: 'b' }
    await nextTick()
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ q: 'b' }))).toBeDefined()
  })

  it('stays idle while the space id is empty', async () => {
    const query = mount(() => inSpace('').useAssetSharesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useAssetSharesQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [share()], meta: { total: 1 } })

    const query = mount(() => inSpace().useAssetSharesQuery()).result
    await flush()

    expect(query.data.value?.meta).toEqual({ total: 1 })
  })
})

describe('useAssetShareQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: share() })

    const query = mount(() => inSpace().useAssetShareQuery('s1')).result
    await flush()

    expect(get).toHaveBeenCalledWith('s1')
    expect(query.data.value?.token).toBe('tok_abc')
  })

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['an empty string', ''],
  ])('stays idle for %s id', async (_label, id) => {
    const query = mount(() => inSpace().useAssetShareQuery(id)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useAssetShareQuery('s1', false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })
})

describe('useCreateAssetShareMutation', () => {
  it('invalidates the lists and the new detail, and names the share', async () => {
    create.mockResolvedValue({ data: share() })
    const harness = mount(() => inSpace().useCreateAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync({
      name: 'Press kit',
      source_type: 'collection',
      collection_id: 'c1',
    })

    expect(create).toHaveBeenCalledWith({
      name: 'Press kit',
      source_type: 'collection',
      collection_id: 'c1',
    })
    expect(result.token).toBe('tok_abc')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('s1') })
    expect(success).toHaveBeenCalledWith('Share link "Press kit" created successfully')
  })

  it('sends a password and expiry through unmodified', async () => {
    create.mockResolvedValue({ data: share() })
    const harness = mount(() => inSpace().useCreateAssetShareMutation())

    await harness.result.mutateAsync({
      name: 'Press kit',
      source_type: 'selection',
      asset_ids: ['a1'],
      password: 'hunter2',
      expires_at: '2026-12-31T00:00:00Z',
      download_limit: 5,
    })

    expect(create).toHaveBeenCalledWith(
      expect.objectContaining({
        password: 'hunter2',
        expires_at: '2026-12-31T00:00:00Z',
        download_limit: 5,
      })
    )
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('collection missing'))
    const harness = mount(() => inSpace().useCreateAssetShareMutation())

    await expect(
      harness.result.mutateAsync({ name: 'x', source_type: 'collection', collection_id: 'c1' })
    ).rejects.toThrow('collection missing')
    expect(error).toHaveBeenCalledWith('Failed to create share link: collection missing')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const harness = mount(() => inSpace().useCreateAssetShareMutation())

    await harness.result
      .mutateAsync({ name: 'x', source_type: 'collection', collection_id: 'c1' })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create share link: Unknown error')
  })
})

describe('useUpdateAssetShareMutation', () => {
  it('invalidates the lists and that share detail', async () => {
    update.mockResolvedValue({ data: share({ name: 'Renamed' }) })
    const harness = mount(() => inSpace().useUpdateAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 's1', payload: { name: 'Renamed' } })

    expect(update).toHaveBeenCalledWith('s1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('s1') })
    expect(success).toHaveBeenCalledWith('Share link "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: share({ id: 'server-id' }) })
    const harness = mount(() => inSpace().useUpdateAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 's1', payload: {} })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('s1') })
  })

  it('passes a null password through, which clears the protection', async () => {
    update.mockResolvedValue({ data: share() })
    const harness = mount(() => inSpace().useUpdateAssetShareMutation())

    await harness.result.mutateAsync({ id: 's1', payload: { password: null } })

    expect(update).toHaveBeenCalledWith('s1', { password: null })
  })

  it('does not invalidate when the update fails', async () => {
    update.mockRejectedValue(new Error('revoked'))
    const harness = mount(() => inSpace().useUpdateAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 's1', payload: {} }).catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to update share link: revoked')
  })
})

describe('useRevokeAssetShareMutation', () => {
  it('revokes and refreshes both the list and the detail', async () => {
    revoke.mockResolvedValue({ data: share({ revoked_at: '2026-07-30T00:00:00Z' }) })
    const harness = mount(() => inSpace().useRevokeAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync('s1')

    expect(revoke).toHaveBeenCalledWith('s1')
    expect(result.revoked_at).toBe('2026-07-30T00:00:00Z')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('s1') })
    expect(success).toHaveBeenCalledWith('Share link "Press kit" revoked')
  })

  it('keeps the revoked share in the cache — revoking is not deleting', async () => {
    revoke.mockResolvedValue({ data: share() })
    const harness = mount(() => inSpace().useRevokeAssetShareMutation(), [
      [keys.detail('s1'), share()],
    ])
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('s1')

    expect(remove).not.toHaveBeenCalled()
    expect(harness.queryClient.getQueryData(keys.detail('s1'))).toBeDefined()
  })

  it('reports the failure reason', async () => {
    revoke.mockRejectedValue(new Error('already revoked'))
    const harness = mount(() => inSpace().useRevokeAssetShareMutation())

    await harness.result.mutateAsync('s1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to revoke share link: already revoked')
  })
})

describe('useDeleteAssetShareMutation', () => {
  it('invalidates the lists and drops the detail cache', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteAssetShareMutation(), [
      [keys.detail('s1'), share()],
    ])
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('s1')

    expect(destroy).toHaveBeenCalledWith('s1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    // Only the lists: the detail is removed rather than invalidated.
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.detail('s1') })
    expect(harness.queryClient.getQueryData(keys.detail('s1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Share link deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('gone'))
    const harness = mount(() => inSpace().useDeleteAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('s1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete share link: gone')
  })
})

describe('copyShareLink', () => {
  const withClipboard = (writeText: ReturnType<typeof vi.fn>) => {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText },
      configurable: true,
      writable: true,
    })
  }

  it('copies the public URL and confirms it', async () => {
    const writeText = vi.fn(async () => {})
    withClipboard(writeText)

    await mount(() => inSpace()).result.copyShareLink(share())

    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/share/space-1/tok_abc`)
    expect(success).toHaveBeenCalledWith('Share link copied to clipboard')
  })

  it('builds the URL from the current space, not the share payload', async () => {
    const writeText = vi.fn(async () => {})
    withClipboard(writeText)

    await mount(() => inSpace('space-2')).result.copyShareLink(share())

    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/share/space-2/tok_abc`)
  })

  it('rejects without a toast when the clipboard is denied', async () => {
    withClipboard(vi.fn(async () => Promise.reject(new Error('denied'))))

    await expect(mount(() => inSpace()).result.copyShareLink(share())).rejects.toThrow('denied')
    expect(success).not.toHaveBeenCalled()
    // No error toast either: the caller is left to handle it.
    expect(error).not.toHaveBeenCalled()
  })
})

describe('query key shape', () => {
  it('scopes every key to the space', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'asset-shares'])
    expect(queryKeys.assetShares('a').lists()).not.toEqual(queryKeys.assetShares('b').lists())
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ q: 'x' })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('invalidates only the current space', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace('space-2').useDeleteAssetShareMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('s1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assetShares('space-2').lists() })
  })
})
