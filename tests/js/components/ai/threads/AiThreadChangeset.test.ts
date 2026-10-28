import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiChangeset, AiChangesetItem } from '~/lib/aiChangesets'

const acceptChangeset = vi.fn().mockResolvedValue({ data: {} })
const rejectChangeset = vi.fn().mockResolvedValue({ data: {} })

vi.mock('~/api', () => ({ api: { forSpace: () => ({ ai: { acceptChangeset, rejectChangeset } }) } }))

const AiThreadChangeset = (await import('~/components/ai/threads/AiThreadChangeset.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

const item = (id: string, entry: string, overrides: Partial<AiChangesetItem> = {}): AiChangesetItem => ({
  id,
  operation: 'update',
  target_type: 'content',
  target_id: entry,
  target_label: `Entry ${entry}`,
  base_version_id: null,
  path: 'title',
  field_type: 'text',
  before: 'Old title',
  after: 'New title',
  final: null,
  current: null,
  status: 'pending',
  glossary_findings: [],
  decided_at: null,
  ...overrides,
})

const changeset = (items: AiChangesetItem[]): AiChangeset => ({
  id: 'changeset-1',
  title: 'Summaries',
  status: 'open',
  inline: false,
  item_count: items.length,
  pending_count: items.filter((entry) => entry.status === 'pending').length,
  items,
  created_at: '2026-10-27T10:00:00Z',
})

const mountChangeset = async (value: AiChangeset) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  queryClient.setQueryData(queryKeys.spaces.detail('space-1'), {
    id: 'space-1',
    settings: { default_language: 'en', languages: [{ code: 'de', name: 'Deutsch' }] },
  })
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
  const wrapper = mount(AiThreadChangeset, {
    props: { spaceId: 'space-1', changeset: value },
    global: { plugins: [router, [VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
  })
  await flushPromises()
  return wrapper
}

describe('AiThreadChangeset', () => {
  it('accepts one field from its entry card; only decided fields show a status', async () => {
    acceptChangeset.mockClear()
    const wrapper = await mountChangeset(
      changeset([item('item-1', 'a'), item('item-2', 'a', { path: 'intro', status: 'accepted' }), item('item-3', 'b')])
    )

    expect(wrapper.findAll('[data-entry]')).toHaveLength(2)
    expect(wrapper.get('[data-item="item-1"]').text()).not.toContain('Open')
    expect(wrapper.get('[data-item="item-2"]').text()).toContain('Accepted')
    expect(wrapper.find('[data-item="item-2"] [data-action="accept"]').exists()).toBe(false)

    await wrapper.get('[data-item="item-1"] [data-action="accept"]').trigger('click')
    await flushPromises()

    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { item_ids: ['item-1'], overwrite: false })
  })

  it('shows source and translation side by side for a cross-locale field', async () => {
    const wrapper = await mountChangeset(
      changeset([item('item-1', 'a', { target_type: 'asset', path: 'fields.de.alt', before: null, after: 'Ein Hund', source: 'A dog' })])
    )

    const columns = wrapper.get('[data-item="item-1"]').text()
    expect(columns).toContain('English')
    expect(columns).toContain('A dog')
    expect(columns).toContain('Deutsch')
    expect(columns).toContain('Ein Hund')
  })

  it('folds more than five entries into a summary card that accepts everything', async () => {
    acceptChangeset.mockClear()
    const wrapper = await mountChangeset(
      changeset(['a', 'b', 'c', 'd', 'e', 'f'].map((entry) => item(`item-${entry}`, entry)))
    )

    expect(wrapper.find('[data-summary]').exists()).toBe(true)
    expect(wrapper.text()).toContain('6 entries, 6 open, 0 accepted, 0 rejected')
    expect(wrapper.findAll('[data-item]')).toHaveLength(0)

    await wrapper.findAll('button').find((button) => button.text() === 'Accept all')?.trigger('click')
    await flushPromises()

    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', {})
  })
})
