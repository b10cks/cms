import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import AiInboxList from '~/components/ai/AiInboxList.vue'
import type { AiInbox } from '~/lib/aiChangesets'
import { aiInboxItems } from '~/lib/aiInbox'
import type { AiRunRecord } from '~/lib/aiRun'

const run = (id: string, state: AiRunRecord['state']): AiRunRecord => ({
  id,
  surface: 'bulk:content',
  skill: 'translate-fields',
  model: null,
  state,
  mode: 'background',
  partial: false,
  questionnaire: state === 'awaiting_input' ? { questions: [] } : null,
  result: null,
  error: state === 'failed' ? 'Provider timed out' : null,
  event_seq: 0,
  usage: { steps: 0, input_tokens: 0, output_tokens: 0, cost_usd: 0 },
  started_at: '2026-10-27T09:00:00Z',
})

const inbox: AiInbox = {
  changesets: [
    {
      id: 'changeset-1',
      title: 'Summaries',
      status: 'open',
      inline: false,
      item_count: 34,
      pending_count: 34,
      entry_count: 12,
      created_at: '2026-10-27T10:00:00Z',
    },
  ],
  suggestions: [
    {
      id: 'suggestion-1',
      source_locale: 'en',
      term: 'basket',
      translations: { de: 'Warenkorb' },
      forbidden: [],
      note: null,
      evidence: null,
      actor: null,
      created_at: '2026-10-27T08:00:00Z',
    },
  ],
  runs: [run('run-question', 'awaiting_input'), run('run-failed', 'failed')],
  glossaries: [],
  count: 3,
}

const router = createRouter({
  history: createMemoryHistory(),
  routes: ['/', '/:space/inbox/:changeset', '/:space/settings/ai-skills', '/:space/settings/ai-knowledge'].map(
    (path, index) => ({
      path,
      name: ['home', 'space-ai-inbox-changeset', 'space-settings-ai-skills', 'space-settings-ai-knowledge'][index],
      component: { template: '<div />' },
    })
  ),
})

describe('AiInboxList', () => {
  it('filters the rows by kind with the filter chips', async () => {
    const wrapper = mount(AiInboxList, {
      props: { spaceId: 'space-1', items: aiInboxItems(inbox), glossaries: [] },
      global: { plugins: [router], stubs: { Icon: true } },
    })
    const kinds = () => wrapper.findAll('li[data-kind]').map((row) => row.attributes('data-kind'))

    expect(kinds()).toEqual(['changes', 'questions', 'failed', 'suggestions'])
    expect(wrapper.text()).toContain('12 entries, 34 fields')

    for (const [filter, expected] of [
      ['changes', ['changes']],
      ['suggestions', ['suggestions']],
      ['questions', ['questions']],
      ['failed', ['failed']],
    ] as const) {
      await wrapper.get(`button[data-filter="${filter}"]`).trigger('click')
      expect(kinds()).toEqual(expected)
      expect(wrapper.get(`button[data-filter="${filter}"]`).attributes('aria-pressed')).toBe('true')
    }
  })
})
