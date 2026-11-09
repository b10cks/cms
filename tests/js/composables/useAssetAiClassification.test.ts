import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { withSetup, type Harness } from '../support/harness'

const classifyAssets = vi.fn()
const getClassificationRun = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ ai: { classifyAssets, getClassificationRun } }) } }))

const success = vi.fn()
const warning = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, warning, loading: vi.fn(), info: vi.fn(), error: vi.fn() } }))

const { useAssetAiClassification } = await import('~/composables/useAssetAiClassification')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const progress = (fieldReport: { partial: number; none: number } | null) => ({
  data: { run_id: 'run-1', queued: 1, pending: 0, updated: 1, skipped: 0, failed: 0, complete: true, field_report: fieldReport },
})

describe('useAssetAiClassification', () => {
  let harness: Harness<ReturnType<ReturnType<typeof useAssetAiClassification>['useClassifyAssetsMutation']>>

  beforeEach(() => {
    vi.clearAllMocks()
    classifyAssets.mockResolvedValue({ data: { run_id: 'run-1', queued: 1, skipped: 0 } })
    harness = withSetup(() => useAssetAiClassification('space-1').useClassifyAssetsMutation())
  })

  afterEach(() => harness.unmount())

  const finish = async () => {
    harness.result.mutate({ scope: 'selection', asset_ids: ['a1'] })
    await flush()
    await flush()
  }

  it('reports a finished run with every value settled as a success', async () => {
    getClassificationRun.mockResolvedValue(progress({ partial: 0, none: 0 }))

    await finish()

    expect(success).toHaveBeenCalledTimes(2)
    expect(warning).not.toHaveBeenCalled()
  })

  it('warns when images have values left open', async () => {
    getClassificationRun.mockResolvedValue(progress({ partial: 1, none: 1 }))

    await finish()

    expect(warning).toHaveBeenCalledTimes(1)
    expect(warning.mock.calls[0][0]).toContain('2 images have fields left open')
  })
})
