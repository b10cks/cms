import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AiThreadWorkLog from '~/components/ai/threads/AiThreadWorkLog.vue'
import type { AiProviderRetryNotice } from '~/lib/aiRun'

const notice: AiProviderRetryNotice = {
  retry_at: new Date(Date.now() + 120_000).toISOString(),
  attempt: 2,
  max_attempts: 3,
  failure: 'unavailable',
  unconfirmed_calls: 0,
  retries: 0,
  cost_status: 'estimated',
}

describe('AiThreadWorkLog', () => {
  const mountLog = (providerRetry: AiProviderRetryNotice | null) =>
    mount(AiThreadWorkLog, {
      props: { steps: [], running: true, startedAt: Date.now(), finishedAt: null, providerRetry },
      global: { stubs: { Icon: true } },
    })

  it('names the wait with its attempt, and no longer once the time passed', () => {
    const waiting = mountLog(notice)
    expect(waiting.get('[data-provider-retry]').text()).toContain('The AI provider is temporarily unavailable.')
    expect(waiting.get('[data-provider-retry]').text()).toContain('Trying again at')
    expect(waiting.get('[data-provider-retry]').text()).toContain('Attempt 2 of 3.')
    waiting.unmount()

    const due = mountLog({ ...notice, retry_at: new Date(Date.now() - 1000).toISOString() })
    expect(due.get('[data-provider-retry]').text()).toContain('Trying again now.')
    due.unmount()
  })

  it('keeps the cost caveat after a confirmed reconcile instead of claiming the run is settled', () => {
    const wrapper = mountLog({ ...notice, retry_at: null, unconfirmed_calls: 1, cost_status: 'confirmed_known_calls' })

    expect(wrapper.text()).toContain('Known costs are confirmed, but 1 interrupted call may have cost more.')
    expect(wrapper.text()).not.toContain('Trying again')
    wrapper.unmount()
  })
})
