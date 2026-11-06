import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiChangeset, AiChangesetItem, AiReviewGroup } from '~/lib/aiChangesets'

const acceptChangeset = vi.fn()
const getOperationReview = vi.fn()
const getOperationChanges = vi.fn().mockResolvedValue({ data: [], summary: { changed: 0, conflicts: 0, flagged: 0 }, meta: { total: 0, page: 1, per_page: 25 } })
const getOperationReviewItems = vi.fn()
const acceptOperationGroup = vi.fn()

const toast = vi.hoisted(() => ({ info: vi.fn(), warning: vi.fn(), success: vi.fn(), error: vi.fn() }))
vi.mock('vue-sonner', () => ({ toast }))

vi.mock('~/api', () => ({
  api: { forSpace: () => ({ ai: { getOperationChanges, acceptChangeset, getOperationReview, getOperationReviewItems, acceptOperationGroup } }) },
}))

const AiThreadChangeset = (await import('~/components/ai/threads/AiThreadChangeset.vue')).default

const FINDING = { id: 'f1', type: 'forbidden_variant', term: 'Trial', expected: 'Probe', found: 'Test' } as const
const HINT = { id: 'h1', type: 'missing_term', term: 'partner', expected: 'Partner' } as const

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
  glossary_findings: [],
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
const missingReason = (itemId: string, findingId: string, message: string) =>
  Object.assign(new Error('The given data was invalid.'), { status: 422, data: { errors: { [`overrides.${itemId}.${findingId}`]: [message] } } })

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
      changeset([item('a', { review: { policy: { snapshot_id: 'snapshot-1', fingerprint: 'x', terms: [] } } })])
    )

    expect(wrapper.find('[data-item-provenance]').exists()).toBe(false)
    await wrapper.get('[data-item="a"] [data-item-review] button[aria-expanded]').trigger('click')
    expect(wrapper.get('[data-item-provenance]').text()).toContain('snapshot')
  })
})

describe('a stored glossary hint', () => {
  it('is not listed, asks no reason, counts as no warning and goes out with a bulk accept', async () => {
    const wrapper = await mountWith(changeset([item('a', { glossary_findings: [HINT] })]))

    expect(wrapper.text()).not.toContain('Partner')
    expect(wrapper.find('[data-finding-overrides]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('glossary warning')

    acceptChangeset.mockResolvedValue(accepted())
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', expect.not.objectContaining({ overrides: expect.anything() }))
  })
})

describe('keeping a deterministic finding', () => {
  it('sends nothing without a written reason, then sends the reason with the decision', async () => {
    const wrapper = await mountWith(changeset([item('a', { glossary_findings: [FINDING] })]))

    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).not.toHaveBeenCalled()
    expect(wrapper.find('[data-override-missing]').exists()).toBe(true)

    await wrapper.get('[data-override-reason]').setValue('   ')
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).not.toHaveBeenCalled()

    acceptChangeset.mockResolvedValue(accepted())
    await wrapper.get('[data-override-reason]').setValue(' Supplier wording. ')
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', {
      item_ids: ['a'],
      overwrite: false,
      overrides: { a: [{ finding_id: 'f1', reason: 'Supplier wording.' }] },
    })
  })

  it('collects a reason for a finding only the 422 of an edit names, without losing the edit', async () => {
    const wrapper = await mountWith(changeset([item('a')]))
    await startEdit(wrapper, 'a', 'Mein Text')

    acceptChangeset.mockRejectedValueOnce(missingReason('a', 'new1', 'forbidden_variant: Test (expected Probe) needs a correction.'))
    await clickAccept(wrapper, 'a')

    expect(toast.error).not.toHaveBeenCalled()
    expect(wrapper.get('[data-finding="new1"]').text()).toContain('forbidden_variant: Test')
    expect((wrapper.get('[data-item="a"] textarea').element as HTMLTextAreaElement).value).toBe('Mein Text')

    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenCalledTimes(1)

    acceptChangeset.mockResolvedValueOnce(accepted())
    await wrapper.get('[data-override-reason]').setValue('Kept on purpose.')
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenLastCalledWith('changeset-1', {
      item_ids: ['a'],
      overwrite: false,
      values: { a: 'Mein Text' },
      overrides: { a: [{ finding_id: 'new1', reason: 'Kept on purpose.' }] },
    })
  })

  it('does not hold an edit to the findings of the text it replaced', async () => {
    const wrapper = await mountWith(changeset([item('a', { glossary_findings: [FINDING] })]))
    await wrapper.get('[data-override-reason]').setValue('For the proposal.')
    await startEdit(wrapper, 'a', 'Korrigiert')

    expect(wrapper.find('[data-finding-overrides]').exists()).toBe(false)
    expect(wrapper.find('[data-findings-of-proposal]').exists()).toBe(true)

    acceptChangeset.mockResolvedValue(accepted())
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenCalledWith('changeset-1', { item_ids: ['a'], overwrite: false, values: { a: 'Korrigiert' } })
  })

  it('keeps the reason through a conflict, for the repost that overwrites', async () => {
    const pending = item('a', { glossary_findings: [FINDING] })
    const wrapper = await mountWith(changeset([pending]))
    await wrapper.get('[data-override-reason]').setValue('Supplier wording.')

    acceptChangeset.mockResolvedValueOnce(leftOpen({ conflicts: 1 }))
    await clickAccept(wrapper, 'a')
    await wrapper.setProps({ changeset: changeset([{ ...pending, status: 'conflict', current: 'Changed' }]) })

    acceptChangeset.mockResolvedValueOnce(accepted())
    await clickAccept(wrapper, 'a')
    expect(acceptChangeset).toHaveBeenLastCalledWith('changeset-1', {
      item_ids: ['a'],
      overwrite: true,
      overrides: { a: [{ finding_id: 'f1', reason: 'Supplier wording.' }] },
    })
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

  it('shows the kept reasons on an accepted edit', async () => {
    const wrapper = await mountWith(
      changeset([
        item('a', {
          status: 'accepted',
          final: text,
          review: { overrides: [{ finding_id: 'f1', finding: FINDING, reason: 'Supplier wording.' }] },
        }),
      ])
    )

    expect(wrapper.get('[data-override-kept]').text()).toContain('Supplier wording.')
    expect(wrapper.find('[data-override-reason]').exists()).toBe(false)
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
        item('a', { glossary_findings: [FINDING] }),
        item('b', { flags: [{ code: 'invalid', message: 'Invalid.' }] }),
        item('c', { status: 'conflict', current: 'Changed' }),
        item('d', { glossary_findings: [HINT] }),
        item('e'),
        item('f'),
      ])
    )

    expect(wrapper.text()).toContain('3 changes left for you')
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

  it('holds a group accept back while an edit in it needs a reason, so the proposal cannot replace it', async () => {
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

    acceptChangeset.mockRejectedValueOnce(missingReason('a', 'new1', 'Needs a reason.'))
    await wrapper.get('[data-action="accept-groups"]').trigger('click')
    await flushPromises()

    expect(acceptOperationGroup).not.toHaveBeenCalled()
    expect(toast.warning).toHaveBeenCalled()

    acceptChangeset.mockResolvedValueOnce(accepted())
    await wrapper.get('[data-override-reason]').setValue('Kept on purpose.')
    await wrapper.get('[data-action="accept-groups"]').trigger('click')
    await flushPromises()
    expect(acceptOperationGroup).toHaveBeenCalledTimes(1)
    expect(acceptChangeset).toHaveBeenCalledTimes(2)
  })
})
