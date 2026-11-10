import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiChangeset, AiChangesetItem, AiReviewGroup } from '~/lib/aiChangesets'

const acceptChangeset = vi.fn()
const getOperationReview = vi.fn()
const getOperationChanges = vi.fn().mockResolvedValue({ data: [], summary: { changed: 0, conflicts: 0 }, meta: { total: 0, page: 1, per_page: 25 } })
const getOperationReviewItems = vi.fn()
const acceptOperationGroup = vi.fn()

const toast = vi.hoisted(() => ({ info: vi.fn(), warning: vi.fn(), success: vi.fn(), error: vi.fn() }))
vi.mock('vue-sonner', () => ({ toast }))

vi.mock('~/api', () => ({
  api: { forSpace: () => ({ ai: { getOperationChanges, acceptChangeset, getOperationReview, getOperationReviewItems, acceptOperationGroup } }) },
}))

const AiThreadChangeset = (await import('~/components/ai/threads/AiThreadChangeset.vue')).default

const item = (id: string, overrides: Partial<AiChangesetItem> = {}): AiChangesetItem => ({
  id,
  operation: 'update',
  target_type: 'data_entry',
  target_id: `entry-${id}`,
  target_label: `Entry ${id}`,
  base_version_id: null,
  path: 'title',
  field_type: 'text',
  before: 'Alt',
  after: 'Proposed',
  final: null,
  current: null,
  status: 'pending',
  decided_at: null,
  ...overrides,
})

const changeset = (items: AiChangesetItem[]): AiChangeset => ({
  id: 'changeset-1',
  title: 'Review',
  status: 'open',
  inline: false,
  item_count: items.length,
  pending_count: items.length,
  items,
  created_at: '2026-11-02T10:00:00Z',
})

const accepted = (extra: object = {}) => ({ data: {}, accepted: 1, conflicts: 0, failed: 0, blocked: 0, ...extra })
const leftOpen = (extra: object = {}) => ({ data: {}, accepted: 0, conflicts: 0, failed: 0, blocked: 0, ...extra })

const mountWith = async (value: AiChangeset) => {
  acceptChangeset.mockReset()
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
  const wrapper = mount(AiThreadChangeset, {
    props: { spaceId: 'space-1', changeset: value },
    global: { plugins: [router, [VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
  })
  await flushPromises()
  return wrapper
}

const clickAccept = async (wrapper: Awaited<ReturnType<typeof mountWith>>, id: string) => {
  await wrapper.get(`[data-item="${id}"] [data-action="accept"]`).trigger('click')
  await flushPromises()
}

const startEdit = async (wrapper: Awaited<ReturnType<typeof mountWith>>, id: string, text: string) => {
  await wrapper.get(`[data-item="${id}"] [aria-label="Edit"]`).trigger('click')
  await wrapper.get(`[data-item="${id}"] textarea`).setValue(text)
}

describe('what a proposal rests on', () => {
  it('shows the evidence of an asset proposal', async () => {
    const wrapper = await mountWith(
      changeset([
        item('seen', { target_type: 'asset', path: 'fields.en.alt', review: { evidence: { basis: 'image', run_id: 'r', message: 2, sha256: 'abc' } } }),
        item('asked', { target_type: 'asset', path: 'fields.en.title', review: { evidence: { basis: 'instruction', quote: 'Call it Hero' } } }),
      ])
    )

    expect(wrapper.get('[data-item="seen"] [data-item-evidence]').text()).toContain('reached the model')
    expect(wrapper.get('[data-item="asked"] [data-item-evidence]').text()).toContain('Call it Hero')
  })

  it('shows the policy a proposal was written under on request', async () => {
    const wrapper = await mountWith(
      changeset([item('a', { review: { policy: { snapshot_id: 'snapshot-1', fingerprint: 'x' } } })])
    )

    expect(wrapper.find('[data-item-provenance]').exists()).toBe(false)
    await wrapper.get('[data-item="a"] [data-item-review] button[aria-expanded]').trigger('click')
    expect(wrapper.get('[data-item-provenance]').text()).toContain('snapshot')
  })
})

describe('an edit', () => {
  const text = 'Mein Text'

  it('applies on the first accept, with its text', async () => {
    const wrapper = await mountWith(changeset([item('a')]))
    await startEdit(wrapper, 'a', text)

    acceptChangeset.mockResolvedValue(accepted())
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { item_ids: ['a'], overwrite: false, values: { a: text } })
  })

  it('accepts the proposal once the edit is discarded', async () => {
    const wrapper = await mountWith(changeset([item('a')]))
    await startEdit(wrapper, 'a', text)

    await wrapper.get('[data-item="a"] [aria-label="Discard edit"]').trigger('click')
    expect(wrapper.find('[data-item="a"] textarea').exists()).toBe(false)

    acceptChangeset.mockResolvedValue(accepted())
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { item_ids: ['a'], overwrite: false })
  })
})

describe('bulk acceptance', () => {
  const entries = ['b', 'c', 'd', 'e', 'f']
  const reviewGroup = (key: string, examples: AiChangesetItem[]): AiReviewGroup => ({
    key,
    label: null,
    operation: 'update',
    target_type: 'content',
    path: 'title',
    field_type: 'text',
    counts: { pending: 3, accepted: 0, rejected: 0, conflict: 0 },
    bulk: 2,
    individual: 1,
    queued: null,
    examples,
  })

  it('counts what the server leaves out as individual', async () => {
    const wrapper = await mountWith(
      changeset([
        item('a'),
        item('b', { flags: [{ code: 'invalid', message: 'Invalid.' }] }),
        item('c', { status: 'conflict', current: 'Changed' }),
        item('d'),
        item('e'),
        item('f'),
      ])
    )

    expect(wrapper.text()).toContain('2 changes left for you')
  })

  it('keeps an edit Accept all left open', async () => {
    const wrapper = await mountWith(changeset(entries.map((id) => item(id)).concat(item('a'))))
    await wrapper.get('[data-entry="data_entry:entry-a"] button[aria-expanded]').trigger('click')
    await startEdit(wrapper, 'a', 'Mein Text')

    acceptChangeset.mockResolvedValueOnce(leftOpen({ accepted: 5 }))
    await wrapper.findAll('button').find((button) => button.text() === 'Accept all')?.trigger('click')
    await flushPromises()

    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { bulk: true, values: { a: 'Mein Text' } })
    expect((wrapper.get('[data-item="a"] textarea').element as HTMLTextAreaElement).value).toBe('Mein Text')
  })

  it('sends an edit in a group before the group accept, so the proposal cannot replace it', async () => {
    const key = 'update|content|title|text'
    const example = item('a')
    getOperationReview.mockReset().mockResolvedValue({ data: [reviewGroup(key, [example])] })
    getOperationReviewItems.mockReset().mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 20 } })
    acceptOperationGroup.mockReset().mockResolvedValue({ data: { accepted: 2, conflicts: 0, failed: 0, blocked: 0, individual: 0, queued: 0 } })
    const wrapper = await mountWith({ ...changeset([]), operation_id: 'operation-1', item_count: 3, pending_count: 3 })
    const group = wrapper.get(`[data-group="${key}"]`)
    await group.get('button[aria-expanded]').trigger('click')
    await flushPromises()
    await startEdit(wrapper, 'a', 'Mein Text')
    await group.get('[role="checkbox"]').trigger('click')

    acceptChangeset.mockResolvedValueOnce(accepted())
    await wrapper.get('[data-action="accept-groups"]').trigger('click')
    await flushPromises()

    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { item_ids: ['a'], overwrite: false, values: { a: 'Mein Text' } })
    expect(acceptChangeset.mock.invocationCallOrder[0]).toBeLessThan(acceptOperationGroup.mock.invocationCallOrder[0])
  })
})
