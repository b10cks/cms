import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { withSetup, type Harness } from '../support/harness'

const get = vi.fn()

vi.mock('~/api', () => ({ api: { client: { get } } }))

const { useSpaceUsage } = await import('~/composables/useSpaceUsage')
const { queryKeys } = await import('~/composables/useQueryClient')

const spaceUsageKey = (id: string) => queryKeys.spaceUsage(id).all()

const SPACE = 'space-1'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Query = ReturnType<ReturnType<typeof useSpaceUsage>['useUsageQuery']>

let harness: Harness<Query> | undefined

const setup = (
  spaceId: MaybeRefOrGetter<string | null> = SPACE,
  seed?: Array<[readonly unknown[], unknown]>
) => {
  harness = withSetup<Query>(() => useSpaceUsage(spaceId).useUsageQuery(), { seed })
  return harness.result
}

beforeEach(() => {
  get.mockReset()
  get.mockResolvedValue({ data: {} })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useUsageQuery', () => {
  it('hits the management usage endpoint for the space', async () => {
    setup()
    await flush()

    expect(get).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/usage`)
  })

  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: { contents: { used: 12, limit: 100 } } })

    const query = setup()
    await flush()

    expect(query.data.value).toEqual({ contents: { used: 12, limit: 100 } })
  })

  it('keys under the space, so a ["spaces", id, …] invalidation reaches it', async () => {
    setup()
    await flush()

    expect(harness?.queryClient.getQueryData(spaceUsageKey(SPACE))).toBeDefined()
    // The key now comes from the shared factory, so a ['spaces', id, …] sweep reaches it.
    expect(spaceUsageKey(SPACE)).toEqual(['spaces', SPACE, 'usage'])
  })

  it('stays idle for a null space id', async () => {
    const query = setup(null)
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('stays idle for an empty space id', async () => {
    const query = setup('')
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('rekeys and refetches when the space id changes', async () => {
    const spaceId = ref<string | null>(SPACE)

    setup(spaceId)
    await flush()
    spaceId.value = 'space-2'
    await nextTick()
    await flush()

    expect(get).toHaveBeenLastCalledWith('/mgmt/v1/spaces/space-2/usage')
    expect(harness?.queryClient.getQueryData(spaceUsageKey('space-2'))).toBeDefined()
  })

  it('accepts a getter as the space id', async () => {
    setup(() => 'space-getter')
    await flush()

    expect(get).toHaveBeenCalledWith('/mgmt/v1/spaces/space-getter/usage')
  })

  it('serves a seeded entry without refetching — 60s of staleTime', async () => {
    const query = setup(SPACE, [[spaceUsageKey(SPACE), { contents: { used: 1 } }]])
    await flush()

    expect(query.data.value).toEqual({ contents: { used: 1 } })
    expect(get).not.toHaveBeenCalled()
  })

  it('surfaces a failed usage lookup as an error rather than null', async () => {
    get.mockRejectedValue(new Error('403'))

    const query = setup()
    await flush()

    expect(query.error.value).toEqual(new Error('403'))
    expect(query.data.value).toBeUndefined()
  })
})
