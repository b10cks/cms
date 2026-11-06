import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiChangeset, AiChangesetItem, AiReviewGroup } from '~/lib/aiChangesets'

const acceptChangeset = vi.fn().mockResolvedValue({ data: {} })
const rejectChangeset = vi.fn().mockResolvedValue({ data: {} })
const getOperationReview = vi.fn()
const getOperationReviewItems = vi.fn()
const getOperationChanges = vi.fn()
const getOperationEntryItems = vi.fn()
const acceptOperationGroup = vi
  .fn()
  .mockResolvedValue({ data: { accepted: 2, conflicts: 0, failed: 0, blocked: 0, individual: 1, queued: 0 } })

const toast = vi.hoisted(() => ({ info: vi.fn(), warning: vi.fn(), success: vi.fn(), error: vi.fn() }))
vi.mock('vue-sonner', () => ({ toast }))

vi.mock('~/api', () => ({
  api: {
    forSpace: () => ({
      ai: { acceptChangeset, rejectChangeset, getOperationReview, getOperationReviewItems, getOperationChanges, getOperationEntryItems, acceptOperationGroup },
    }),
  },
}))

const AiThreadChangeset = (await import('~/components/ai/threads/AiThreadChangeset.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

const item = (id: string, entry: string, overrides: Partial<AiChangesetItem> = {}): AiChangesetItem => ({
  id,
  operation: 'update',
  target_type: 'data_entry',
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

const noChanges = { data: [], summary: { changed: 0, conflicts: 0, flagged: 0 }, meta: { total: 0, page: 1, per_page: 25 } }

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

const reviewGroup = (key: string, overrides: Partial<AiReviewGroup> = {}): AiReviewGroup => ({
  key,
  label: null,
  operation: 'update',
  target_type: 'content',
  path: 'seo.*.title',
  field_type: 'text',
  counts: { pending: 3, accepted: 0, rejected: 0, conflict: 0 },
  bulk: 2,
  individual: 1,
  queued: null,
  examples: [item(`${key}-example`, 'a')],
  ...overrides,
})

afterEach(() => vi.useRealTimers())

describe('AiThreadChangeset', () => {
  it('says what happened to content, puts what needs a person first, and offers no decision on what is written', async () => {
    const written = { target_type: 'content' as const, status: 'accepted' as const }
    const wrapper = await mountChangeset(
      changeset([
        item('item-1', 'a', written),
        item('item-2', 'b', { ...written, glossary_findings: [{ id: 'f', type: 'forbidden_variant', term: 'cart', found: 'trolley' }] }),
        item('item-3', 'c', { target_type: 'content', status: 'conflict', current: 'Typed by a person' }),
      ])
    )

    expect(wrapper.get('[data-changes-summary]').text()).toBe('Changed 2 entries, 1 conflict, 1 flagged. Nothing is published.')
    expect(wrapper.findAll('[data-entry]').map((entry) => entry.attributes('data-entry'))).toEqual(['content:c', 'content:b', 'content:a'])

    const done = wrapper.get('[data-item="item-1"]')
    expect(done.text()).toContain('Written to draft')
    expect(done.find('[data-action="accept"]').exists()).toBe(false)
    expect(done.find('[data-action="reject"]').exists()).toBe(false)
    expect(wrapper.get('[data-entry="content:a"] h3 a').attributes('href')).toBe('/space-1/content/a')
    expect(wrapper.get('[data-entry="content:a"] [data-entry-history]').attributes('href')).toBe('/space-1/content/a/versions')

    // Flagged wording shows with no reason to give, and a conflict keeps its resolve flow.
    const flagged = wrapper.get('[data-item="item-2"]')
    expect(flagged.text()).toContain('trolley')
    expect(flagged.find('[data-override-reason]').exists()).toBe(false)
    expect(wrapper.get('[data-item="item-3"] [data-action="accept"]').attributes('title')).toBe('Use AI version')
    expect(wrapper.get('[data-item="item-3"] [data-action="reject"]').attributes('title')).toBe('Keep current')
  })

  it('accepts one proposed field from its entry card; only decided fields show a status', async () => {
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

  it('folds more than five entries into a summary card whose Accept all leaves flagged changes open', async () => {
    acceptChangeset.mockClear()
    const wrapper = await mountChangeset(
      changeset([
        ...['a', 'b', 'c', 'd', 'e'].map((entry) => item(`item-${entry}`, entry)),
        item('item-f', 'f', { flags: [{ code: 'uncertain', message: 'Two topics.' }] }),
      ])
    )

    expect(wrapper.find('[data-summary]').exists()).toBe(true)
    expect(wrapper.text()).toContain('1 change left for you')
    expect(wrapper.findAll('[data-item]')).toHaveLength(0)

    await wrapper.findAll('button').find((button) => button.text() === 'Accept all')?.trigger('click')
    await flushPromises()

    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { bulk: true })
  })

  it('shows a stale translation with its source change, the current and the proposed translation', async () => {
    const wrapper = await mountChangeset(
      changeset([
        item('item-1', 'a', {
          before: 'Willkommen',
          after: 'Willkommen zurück',
          translation_units: [{ unit_id: 'title', reason: 'stale', source: 'Welcome back', previous_source: 'Welcome' }],
        }),
      ])
    )

    const unit = wrapper.get('[data-unit="title"]').text()
    expect(unit).toContain('Source changed')
    expect(unit).toContain('Source, changed since the current translation')
    expect(unit).toContain('back')
    expect(unit).toContain('Current translation')
    expect(unit).toContain('Willkommen')
    expect(unit).toContain('Willkommen zurück')
  })

  it('names the tags an asset proposal adds and shows why the AI is unsure', async () => {
    const wrapper = await mountChangeset(
      changeset([
        item('item-1', 'asset-1', {
          target_type: 'asset',
          target_label: 'shoe.jpg',
          path: 'tags',
          field_type: 'asset_tags',
          before: [],
          after: { add: ['new:item-9', 'tag-1'], remove: [] },
          review: {
            group: 'Shoes',
            reason: 'A sneaker on white.',
            flags: [{ code: 'uncertain', message: 'Could be a boot.' }],
            labels: { 'new:item-9': 'Sneakers', 'tag-1': 'Footwear' },
          },
        }),
      ])
    )

    const text = wrapper.get('[data-item="item-1"]').text()
    expect(text).toContain('Tags')
    expect(text).toContain('Sneakers')
    expect(text).toContain('Footwear')
    expect(text).toContain('A sneaker on white.')
    expect(text).toContain('The AI is unsure: Could be a boot.')
  })

  it('lists an operation\'s changed entries with conflicts first and offers no group decision on them', async () => {
    getOperationReview.mockResolvedValue({ data: [] })
    getOperationEntryItems.mockReset().mockResolvedValue({
      data: [item('item-1', 'a', { target_type: 'content', status: 'conflict', current: 'Typed' })],
      meta: { total: 1, page: 1, per_page: 20 },
    })
    getOperationChanges.mockResolvedValue({
      data: [
        { target_id: 'a', label: 'Entry a', written: 1, conflicts: 1, flagged: 0, flagged_wording: [] },
        { target_id: 'b', label: 'Entry b', written: 2, conflicts: 0, flagged: 3, flagged_wording: ['klimaneutral', 'gratis'] },
        { target_id: 'c', label: 'Entry c', written: 1, conflicts: 0, flagged: 0, flagged_wording: [] },
      ],
      summary: { changed: 3, conflicts: 1, flagged: 3 },
      meta: { total: 3, page: 1, per_page: 25 },
    })
    const wrapper = await mountChangeset({ ...changeset([]), operation_id: 'operation-1', item_count: 7, pending_count: 1 })

    expect(wrapper.get('[data-changes-summary]').text()).toBe('Changed 3 entries, 1 conflict, 3 flagged. Nothing is published.')
    const rows = wrapper.findAll('[data-changed-entry]')
    expect(rows.map((row) => row.attributes('data-changed-entry'))).toEqual(['a', 'b', 'c'])
    expect(rows[0].get('[data-entry-conflicts]').text()).toBe('1 conflict')
    expect(rows[1].get('[data-entry-flagged]').text()).toBe('Flagged: "klimaneutral", "gratis"')
    expect(rows[2].get('[data-entry-history]').attributes('href')).toBe('/space-1/content/c/versions')
    expect(wrapper.find('[data-action="accept-groups"]').exists()).toBe(false)
    expect(getOperationEntryItems).not.toHaveBeenCalled()

    await rows[0].get('button').trigger('click')
    await flushPromises()

    expect(getOperationEntryItems).toHaveBeenCalledWith('operation-1', { target_id: 'a', page: 1, per_page: 20 })
    expect(wrapper.get('[data-item="item-1"] [data-action="accept"]').attributes('title')).toBe('Use AI version')
  })

  it('reviews an operation\'s proposals in groups: no change loads until asked, and only selected groups are accepted', async () => {
    acceptOperationGroup.mockClear()
    getOperationReviewItems.mockReset()
    const group = (key: string, overrides: Partial<AiReviewGroup> = {}): AiReviewGroup => ({
      key,
      label: null,
      operation: 'update',
      target_type: 'data_entry',
      path: 'seo.*.title',
      field_type: 'text',
      counts: { pending: 3, accepted: 0, rejected: 0, conflict: 0 },
      bulk: 2,
      individual: 1,
      queued: null,
      examples: [item(`${key}-example`, 'a')],
      ...overrides,
    })
    getOperationReview.mockResolvedValue({
      data: [group('structure', { operation: 'create', target_type: 'asset_tag', path: null, bulk: 0 }), group('update|data_entry|seo.*.title|text')],
    })
    getOperationChanges.mockResolvedValue(noChanges)
    const wrapper = await mountChangeset({ ...changeset([]), operation_id: 'operation-1', item_count: 3000, pending_count: 2400 })

    expect(wrapper.get('[data-proposals-summary]').text()).toBe('6 proposals wait for you')
    expect(wrapper.get('[data-group="structure"]').text()).toContain('New folders and tags')
    expect(wrapper.find('[data-group="structure"] [role="checkbox"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-item]')).toHaveLength(0)
    expect(getOperationReviewItems).not.toHaveBeenCalled()

    const seo = wrapper.get('[data-group="update|data_entry|seo.*.title|text"]')
    expect(seo.text()).toContain('seo → title')
    expect(seo.text()).toContain('1 to check')
    expect(wrapper.get('[data-action="accept-groups"]').attributes('disabled')).toBeDefined()

    await seo.get('[role="checkbox"]').trigger('click')
    await wrapper.get('[data-action="accept-groups"]').trigger('click')
    await flushPromises()

    expect(acceptOperationGroup).toHaveBeenCalledTimes(1)
    expect(acceptOperationGroup).toHaveBeenCalledWith('operation-1', 'update|data_entry|seo.*.title|text')
  })

  it('reports a queued group accept as running in the background and polls until it is done', async () => {
    vi.useFakeTimers({ toFake: ['setInterval'] })
    toast.info.mockClear()
    toast.warning.mockClear()
    const key = 'update|data_entry|seo.*.title|text'
    const counts = { pending: 300, accepted: 0, rejected: 0, conflict: 0 }
    getOperationChanges.mockResolvedValue(noChanges)
    getOperationReview.mockReset().mockResolvedValue({ data: [reviewGroup(key, { counts, bulk: 300, individual: 0 })] })
    acceptOperationGroup.mockResolvedValueOnce({
      data: { accepted: 0, conflicts: 0, failed: 0, blocked: 0, individual: 0, queued: 300 },
    })
    const wrapper = await mountChangeset({ ...changeset([]), operation_id: 'operation-1', item_count: 300, pending_count: 300 })

    getOperationReview.mockResolvedValue({ data: [reviewGroup(key, { counts, bulk: 300, individual: 0, queued: 'accept' })] })
    await wrapper.get(`[data-group="${key}"] [role="checkbox"]`).trigger('click')
    await wrapper.get('[data-action="accept-groups"]').trigger('click')
    await flushPromises()

    expect(toast.info).toHaveBeenCalledWith('Accepting 300 changes in the background. The groups update when they are done.')
    expect(toast.warning).not.toHaveBeenCalled()
    const group = wrapper.get(`[data-group="${key}"]`)
    expect(group.get('[data-queued]').text()).toContain('Accepting in the background, 300 open')
    expect(group.get('[role="checkbox"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-action="accept-groups"]').attributes('disabled')).toBeDefined()

    const done = { pending: 0, accepted: 300, rejected: 0, conflict: 0 }
    getOperationReview.mockResolvedValue({ data: [reviewGroup(key, { counts: done, bulk: 0, individual: 0 })] })
    const calls = getOperationReview.mock.calls.length
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(getOperationReview.mock.calls.length).toBeGreaterThan(calls)
    expect(wrapper.find(`[data-group="${key}"] [data-queued]`).exists()).toBe(false)
    expect(wrapper.get(`[data-group="${key}"]`).text()).toContain('0 of 300 open')
  })
})
