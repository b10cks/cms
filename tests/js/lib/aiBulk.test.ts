import { describe, expect, it } from 'vitest'

import { bulkEstimateLabel, bulkPlanReason } from '~/lib/aiBulk'

const t = (key: string, named?: Record<string, unknown>) => `${key}:${named?.amount ?? ''}`
const usd = (value: number) => `$${value.toFixed(2)}`

describe('bulkPlanReason', () => {
  it('lets a small task just run', () => {
    expect(bulkPlanReason({ cost_usd: 1.99, max_usd: 2, truncated: false })).toBeNull()
    expect(bulkPlanReason({ cost_usd: 2, max_usd: 2, truncated: false })).toBeNull()
  })

  it('shows the plan first above the entry limit or the run stop', () => {
    expect(bulkPlanReason({ cost_usd: 0.5, max_usd: 2, truncated: true })).toBe('entries')
    expect(bulkPlanReason({ cost_usd: 2.01, max_usd: 2, truncated: false })).toBe('cost')
  })
})

describe('bulkEstimateLabel', () => {
  it('never reads $0.00', () => {
    expect(bulkEstimateLabel(0, t, usd)).toBe('components.aiBulk.estimateUnknown:')
    expect(bulkEstimateLabel(0.003, t, usd)).toBe('components.aiBulk.estimateTiny:$0.01')
    expect(bulkEstimateLabel(1.25, t, usd)).toBe('$1.25')
  })
})
