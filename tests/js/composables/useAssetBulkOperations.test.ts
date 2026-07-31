import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

vi.mock('~/api', () => ({
  api: {
    forSpace: () => ({ assets: { index, update, delete: destroy } }),
  },
}))

const { useAssetBulkOperations } = await import('~/composables/useAssetBulkOperations')

const SPACE = 'space-1'

const asset = (id: string) => ({ id, filename: id }) as unknown as AssetResource

const page = (data: AssetResource[], meta: Record<string, number> = {}) => ({
  data,
  meta: { total: data.length, last_page: 1, ...meta },
})

/** The API client rejects with a shaped error object, not an Error. */
const apiError = (status: number, data?: Record<string, unknown>) => ({ status, data })

// Explicit, not ReturnType<typeof setup>: that would be circular, and TS would
// silently widen the composable's whole surface to `any`.
let harness: Harness<ReturnType<typeof useAssetBulkOperations>> | undefined

const setup = () => {
  harness = withSetup(() => useAssetBulkOperations(SPACE))
  return harness.result
}

beforeEach(() => {
  index.mockReset()
  update.mockReset()
  destroy.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('bulkUpdateAssets', () => {
  it('updates every asset and counts the successes', async () => {
    update.mockResolvedValue({})

    const result = await setup().bulkUpdateAssets([
      { id: 'a1', payload: { folder_id: 'f1' } },
      { id: 'a2', payload: { folder_id: 'f1' } },
    ])

    expect(update).toHaveBeenCalledTimes(2)
    expect(update).toHaveBeenCalledWith('a1', { folder_id: 'f1' })
    expect(result).toEqual({ succeeded: 2, failed: 0 })
  })

  it('counts failures without abandoning the rest', async () => {
    update.mockResolvedValueOnce({}).mockRejectedValueOnce(apiError(500)).mockResolvedValueOnce({})

    const result = await setup().bulkUpdateAssets([
      { id: 'a1', payload: {} },
      { id: 'a2', payload: {} },
      { id: 'a3', payload: {} },
    ])

    expect(update).toHaveBeenCalledTimes(3)
    expect(result).toEqual({ succeeded: 2, failed: 1 })
  })

  it('does nothing for an empty batch', async () => {
    expect(await setup().bulkUpdateAssets([])).toEqual({ succeeded: 0, failed: 0 })
    expect(update).not.toHaveBeenCalled()
  })

  it('invalidates the asset lists once, not once per asset', async () => {
    update.mockResolvedValue({})
    harness = withSetup(() => useAssetBulkOperations(SPACE))

    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.bulkUpdateAssets([{ id: 'a1', payload: {} }, { id: 'a2', payload: {} }])

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
  })
})

describe('bulkDeleteAssets', () => {
  it('reports the ids it deleted', async () => {
    destroy.mockResolvedValue({})

    const result = await setup().bulkDeleteAssets([asset('a1'), asset('a2')])

    expect(result).toEqual({ deletedIds: ['a1', 'a2'], conflicts: [], failed: 0 })
  })

  it('passes the force flag through', async () => {
    destroy.mockResolvedValue({})

    await setup().bulkDeleteAssets([asset('a1')], { force: true })

    expect(destroy).toHaveBeenCalledWith('a1', { force: true })
  })

  it('leaves force undefined when not asked for', async () => {
    destroy.mockResolvedValue({})

    await setup().bulkDeleteAssets([asset('a1')])

    expect(destroy).toHaveBeenCalledWith('a1', { force: undefined })
  })

  it('separates in-use conflicts from outright failures', async () => {
    destroy
      .mockResolvedValueOnce({})
      .mockRejectedValueOnce(apiError(409, { code: 'asset_in_use', linked_contents_count: 3 }))
      .mockRejectedValueOnce(apiError(500))

    const result = await setup().bulkDeleteAssets([asset('a1'), asset('a2'), asset('a3')])

    expect(result.deletedIds).toEqual(['a1'])
    expect(result.conflicts).toEqual([{ asset: expect.objectContaining({ id: 'a2' }), linkedContentsCount: 3 }])
    expect(result.failed).toBe(1)
  })

  it('defaults a conflict with no count to zero', async () => {
    destroy.mockRejectedValue(apiError(409, { code: 'asset_in_use' }))

    const result = await setup().bulkDeleteAssets([asset('a1')])

    expect(result.conflicts[0].linkedContentsCount).toBe(0)
  })

  it('treats a 409 with another code as a failure, not a conflict', async () => {
    destroy.mockRejectedValue(apiError(409, { code: 'something_else' }))

    const result = await setup().bulkDeleteAssets([asset('a1')])

    expect(result.conflicts).toEqual([])
    expect(result.failed).toBe(1)
  })

  it('treats a plain Error as a failure', async () => {
    destroy.mockRejectedValue(new Error('network'))

    expect((await setup().bulkDeleteAssets([asset('a1')])).failed).toBe(1)
  })

  it('keeps the conflict aligned with its own asset', async () => {
    destroy
      .mockRejectedValueOnce(apiError(409, { code: 'asset_in_use', linked_contents_count: 1 }))
      .mockResolvedValueOnce({})

    const result = await setup().bulkDeleteAssets([asset('first'), asset('second')])

    expect(result.conflicts[0].asset.id).toBe('first')
    expect(result.deletedIds).toEqual(['second'])
  })

  it('invalidates the lists even when everything failed', async () => {
    destroy.mockRejectedValue(apiError(500))
    harness = withSetup(() => useAssetBulkOperations(SPACE))

    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.bulkDeleteAssets([asset('a1')])

    expect(invalidate).toHaveBeenCalledTimes(1)
  })
})

describe('fetchAllMatchingAssets', () => {
  it('fetches a single page', async () => {
    index.mockResolvedValue(page([asset('a1'), asset('a2')]))

    const result = await setup().fetchAllMatchingAssets({})

    expect(result).toEqual({
      assets: [expect.objectContaining({ id: 'a1' }), expect.objectContaining({ id: 'a2' })],
      truncated: false,
      total: 2,
    })
    expect(index).toHaveBeenCalledWith({ page: 1, per_page: 500 })
  })

  it('forwards the caller filters alongside the paging params', async () => {
    index.mockResolvedValue(page([]))

    await setup().fetchAllMatchingAssets({ folder: 'f1', q: 'cat' })

    expect(index).toHaveBeenCalledWith({ folder: 'f1', q: 'cat', page: 1, per_page: 500 })
  })

  it('walks every page and concatenates the results', async () => {
    index
      .mockResolvedValueOnce(page([asset('a1')], { total: 3, last_page: 3 }))
      .mockResolvedValueOnce(page([asset('a2')], { total: 3, last_page: 3 }))
      .mockResolvedValueOnce(page([asset('a3')], { total: 3, last_page: 3 }))

    const result = await setup().fetchAllMatchingAssets({})

    expect(result.assets.map((entry) => entry.id)).toEqual(['a1', 'a2', 'a3'])
    expect(index).toHaveBeenLastCalledWith({ page: 3, per_page: 500 })
    expect(result.truncated).toBe(false)
  })

  it('stops at maxItems and reports the truncation', async () => {
    index.mockResolvedValue(page([asset('a1'), asset('a2'), asset('a3')], { total: 10, last_page: 4 }))

    const result = await setup().fetchAllMatchingAssets({}, { maxItems: 2 })

    expect(result.assets).toHaveLength(2)
    expect(result.truncated).toBe(true)
    expect(result.total).toBe(10)
    expect(index).toHaveBeenCalledTimes(1)
  })

  it('is not truncated when the cap happens to equal the total', async () => {
    index.mockResolvedValue(page([asset('a1'), asset('a2')], { total: 2, last_page: 1 }))

    expect((await setup().fetchAllMatchingAssets({}, { maxItems: 2 })).truncated).toBe(false)
  })

  it('falls back to the fetched count when the API sends no total', async () => {
    index.mockResolvedValue({ data: [asset('a1')] })

    expect(await setup().fetchAllMatchingAssets({})).toMatchObject({ total: 1, truncated: false })
  })

  it('handles an empty result set', async () => {
    index.mockResolvedValue(page([]))

    expect(await setup().fetchAllMatchingAssets({})).toEqual({
      assets: [],
      truncated: false,
      total: 0,
    })
  })

  it('propagates an API failure rather than returning a partial page', async () => {
    index
      .mockResolvedValueOnce(page([asset('a1')], { total: 2, last_page: 2 }))
      .mockRejectedValueOnce(apiError(500))

    await expect(setup().fetchAllMatchingAssets({})).rejects.toEqual(apiError(500))
  })
})
