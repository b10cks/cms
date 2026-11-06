import { describe, expect, it } from 'vitest'

import type { AiRunRecord } from '~/lib/aiRun'
import { retryNoticeOfEvent, retryNoticeOfRun, runCostStatus, toAiRunEvent } from '~/lib/aiRun'

const run = (overrides: Partial<AiRunRecord> = {}): Pick<AiRunRecord, 'usage' | 'provider_retry' | 'state'> => ({
  state: 'running',
  usage: { steps: 1, input_tokens: 1, output_tokens: 1, cached_tokens: 0, cost_usd: 0.1 },
  provider_retry: null,
  ...overrides,
})

const retry = (overrides: Partial<NonNullable<AiRunRecord['provider_retry']>> = {}) => ({
  attempts: 2,
  total: 3,
  unconfirmed_calls: 0,
  waited_seconds: 60,
  not_before: '2026-11-02T14:05:00Z',
  failure: { kind: 'unavailable' as const, status: 503, code: null },
  ...overrides,
})

describe('provider_retry_scheduled', () => {
  it('reaches consumers with its retry time and attempt', () => {
    const event = toAiRunEvent({
      type: 'provider_retry_scheduled',
      run_id: 'r1',
      seq: 4,
      attempt: 2,
      max_attempts: 3,
      retry_at: '2026-11-02T14:05:00Z',
      failure: { kind: 'rate_limited', status: 429, code: null },
      possibly_billed: false,
      unconfirmed_calls: 1,
    })

    expect(event?.type).toBe('provider_retry_scheduled')
    if (event?.type !== 'provider_retry_scheduled') return
    expect(retryNoticeOfEvent(event)).toMatchObject({ retry_at: '2026-11-02T14:05:00Z', attempt: 2, max_attempts: 3, failure: 'rate_limited', unconfirmed_calls: 1 })
  })
})

describe('retryNoticeOfRun', () => {
  it('shows a scheduled retry only while the run is running', () => {
    expect(retryNoticeOfRun(run({ provider_retry: retry() }))).toMatchObject({ retry_at: '2026-11-02T14:05:00Z', attempt: 2, max_attempts: null, retries: 3 })
    expect(retryNoticeOfRun(run({ state: 'failed', provider_retry: retry() }))).toBeNull()
  })

  it('keeps unconfirmed calls after the wait ended and stays quiet without either', () => {
    expect(retryNoticeOfRun(run({ state: 'completed', provider_retry: retry({ not_before: null, unconfirmed_calls: 1 }) }))).toMatchObject({ retry_at: null, unconfirmed_calls: 1 })
    expect(retryNoticeOfRun(run({ provider_retry: retry({ not_before: null }) }))).toBeNull()
    expect(retryNoticeOfRun(run())).toBeNull()
  })
})

describe('runCostStatus', () => {
  const confirmed = { steps: 1, input_tokens: 1, output_tokens: 1, cached_tokens: 0, cost_usd: 0.1, cost_confirmed_at: '2026-11-02T14:10:00Z' }

  it('stays estimated until reconciled', () => {
    expect(runCostStatus(run())).toBe('estimated')
  })

  it('does not claim full confirmation while an interrupted call is unaccounted for', () => {
    expect(runCostStatus(run({ usage: confirmed }))).toBe('confirmed')
    expect(runCostStatus(run({ usage: confirmed, provider_retry: retry({ unconfirmed_calls: 1 }) }))).toBe('confirmed_known_calls')
  })
})
