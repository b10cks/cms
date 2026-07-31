import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const history = vi.fn()
const timeseries = vi.fn()
const forSpace = vi.fn(() => ({ usage: { history, timeseries } }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const { useUsageHistory } = await import('~/composables/useUsageHistory')

const PERIODS = [{ id: 'p1' }, { id: 'p2' }]
const TIMESERIES = { buckets: [{ date: '2026-03-01', traffic: 1 }] }

let harness: Harness<unknown> | undefined

beforeEach(() => {
  history.mockReset().mockResolvedValue({ data: PERIODS })
  timeseries.mockReset().mockResolvedValue({ data: TIMESERIES })
  forSpace.mockClear()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useUsageHistoryQuery', () => {
  it('loads the billing periods and caches them under the list key', async () => {
    const run = withSetup(() => useUsageHistory('space-1').useUsageHistoryQuery())
    harness = run

    await vi.waitFor(() => expect(run.result.data.value).toEqual(PERIODS))

    expect(history).toHaveBeenCalledTimes(1)
    expect(run.queryClient.getQueryData(queryKeys.usageHistory('space-1').lists())).toEqual(PERIODS)
  })

  it('serves a seeded cache entry without calling the API', () => {
    const run = withSetup(() => useUsageHistory('space-1').useUsageHistoryQuery(), {
      seed: [[queryKeys.usageHistory('space-1').lists(), PERIODS]],
    })
    harness = run

    expect(run.result.data.value).toEqual(PERIODS)
    expect(history).not.toHaveBeenCalled()
  })

  it('stays disabled without a space id', () => {
    const run = withSetup(() => useUsageHistory('').useUsageHistoryQuery())
    harness = run

    expect(run.result.fetchStatus.value).toBe('idle')
    expect(history).not.toHaveBeenCalled()
  })

  it('follows a reactive space id to a new cache key', async () => {
    const spaceId = ref('space-1')
    const run = withSetup(() => useUsageHistory(spaceId).useUsageHistoryQuery())
    harness = run

    await vi.waitFor(() => expect(run.result.data.value).toEqual(PERIODS))

    history.mockResolvedValue({ data: [{ id: 'p9' }] })
    spaceId.value = 'space-2'

    await vi.waitFor(() => expect(run.result.data.value).toEqual([{ id: 'p9' }]))

    expect(run.queryClient.getQueryData(queryKeys.usageHistory('space-2').lists())).toEqual([
      { id: 'p9' },
    ])
    // The old entry is untouched, so switching back is instant.
    expect(run.queryClient.getQueryData(queryKeys.usageHistory('space-1').lists())).toEqual(PERIODS)
    expect(forSpace).toHaveBeenCalledWith('space-2')
  })

  it('surfaces an API failure as an error state', async () => {
    history.mockRejectedValue(new Error('boom'))
    const run = withSetup(() => useUsageHistory('space-1').useUsageHistoryQuery())
    harness = run

    await vi.waitFor(() => expect(run.result.isError.value).toBe(true))
  })
})

describe('useUsageTimeseriesQuery', () => {
  it('loads the timeseries for a period and keys it by period id', async () => {
    const run = withSetup(() => useUsageHistory('space-1').useUsageTimeseriesQuery('p1'))
    harness = run

    await vi.waitFor(() => expect(run.result.data.value).toEqual(TIMESERIES))

    expect(timeseries).toHaveBeenCalledWith('p1')
    expect(
      run.queryClient.getQueryData(queryKeys.usageHistory('space-1').timeseries('p1'))
    ).toEqual(TIMESERIES)
  })

  it('stays disabled until a period is selected', async () => {
    const periodId = ref<string | null>(null)
    const run = withSetup(() => useUsageHistory('space-1').useUsageTimeseriesQuery(periodId))
    harness = run

    expect(run.result.fetchStatus.value).toBe('idle')
    expect(timeseries).not.toHaveBeenCalled()

    periodId.value = 'p2'

    await vi.waitFor(() => expect(run.result.data.value).toEqual(TIMESERIES))
    expect(timeseries).toHaveBeenCalledWith('p2')
  })

  it('keys a null period as the empty string, so the disabled entry cannot collide', () => {
    const run = withSetup(() => useUsageHistory('space-1').useUsageTimeseriesQuery(null))
    harness = run

    expect(run.result.fetchStatus.value).toBe('idle')
    expect(queryKeys.usageHistory('space-1').timeseries('')).toEqual([
      'spaces',
      'space-1',
      'usage-history',
      'timeseries',
      '',
    ])
  })

  it('refetches under a new key when the period changes', async () => {
    const periodId = ref<string | null>('p1')
    const run = withSetup(() => useUsageHistory('space-1').useUsageTimeseriesQuery(periodId))
    harness = run

    await vi.waitFor(() => expect(run.result.data.value).toEqual(TIMESERIES))

    timeseries.mockResolvedValue({ data: { buckets: [] } })
    periodId.value = 'p2'

    await vi.waitFor(() => expect(run.result.data.value).toEqual({ buckets: [] }))

    expect(timeseries.mock.calls).toEqual([['p1'], ['p2']])
    expect(
      run.queryClient.getQueryData(queryKeys.usageHistory('space-1').timeseries('p1'))
    ).toEqual(TIMESERIES)
  })

  it('stays disabled without a space id even with a period', () => {
    const run = withSetup(() => useUsageHistory('').useUsageTimeseriesQuery('p1'))
    harness = run

    expect(run.result.fetchStatus.value).toBe('idle')
    expect(timeseries).not.toHaveBeenCalled()
  })
})
