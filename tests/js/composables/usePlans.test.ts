import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const spacePlans = vi.fn()

vi.mock('~/api', () => ({
  api: {
    plans: { index },
    forSpace: () => ({ subscriptions: { plans: spacePlans } }),
  },
}))

const { usePlans } = await import('~/composables/usePlans')

const SPACE = 'space-1'

/** The query factories call useQuery, so they only work inside setup(). */
type Queries = {
  publicPlans: ReturnType<ReturnType<typeof usePlans>['usePlansQuery']>
  spacePlans: ReturnType<ReturnType<typeof usePlans>['useSpacePlansQuery']>
}

let harness: Harness<Queries> | undefined

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: HarnessSeed) => {
  harness = withSetup<Queries>(
    () => {
      const plans = usePlans()
      return {
        publicPlans: plans.usePlansQuery(),
        spacePlans: plans.useSpacePlansQuery(spaceId),
      }
    },
    { seed }
  )
  return harness.result
}

type HarnessSeed = Array<[readonly unknown[], unknown]>

beforeEach(() => {
  index.mockReset()
  spacePlans.mockReset()
  index.mockResolvedValue({ data: [] })
  spacePlans.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('usePlansQuery', () => {
  it('unwraps the data envelope', async () => {
    index.mockResolvedValue({ data: [{ id: 'free' }] })

    const { publicPlans } = setup()
    await flush()

    expect(publicPlans.data.value).toEqual([{ id: 'free' }])
  })

  it('caches the public plan list under the plans list key', async () => {
    index.mockResolvedValue({ data: [{ id: 'free' }] })

    setup()
    await flush()

    expect(harness?.queryClient.getQueryData(queryKeys.plans.lists())).toEqual([{ id: 'free' }])
  })

  it('serves a seeded cache entry without calling the API', async () => {
    const { publicPlans } = setup(SPACE, [[queryKeys.plans.lists(), [{ id: 'seeded' }]]])
    await flush()

    expect(publicPlans.data.value).toEqual([{ id: 'seeded' }])
    expect(index).not.toHaveBeenCalled()
  })
})

describe('useSpacePlansQuery', () => {
  it('asks the space-scoped endpoint, so custom agency plans are included', async () => {
    spacePlans.mockResolvedValue({ data: [{ id: 'agency' }] })

    const queries = setup()
    await flush()

    expect(queries.spacePlans.data.value).toEqual([{ id: 'agency' }])
    expect(harness?.queryClient.getQueryData(queryKeys.plans.forSpace(SPACE))).toEqual([
      { id: 'agency' },
    ])
  })

  it('keys the space list separately from the public list', () => {
    expect(queryKeys.plans.forSpace(SPACE)).not.toEqual(queryKeys.plans.lists())
  })

  it('nests the space list under the plans namespace, so it shares invalidation', () => {
    expect(queryKeys.plans.forSpace(SPACE).slice(0, 1)).toEqual([...queryKeys.plans.all()])
  })

  it('stays idle while the space id is empty', async () => {
    const { spacePlans: query } = setup('')
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(spacePlans).not.toHaveBeenCalled()
  })

  it('refetches under a new key when the space id ref changes', async () => {
    const spaceId = ref(SPACE)

    setup(spaceId)
    await flush()
    spaceId.value = 'space-2'
    await nextTick()
    await flush()

    expect(harness?.queryClient.getQueryData(queryKeys.plans.forSpace('space-2'))).toEqual([])
  })
})
