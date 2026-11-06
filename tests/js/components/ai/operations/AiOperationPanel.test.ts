import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { AiOperation, AiOperationItem } from '~/lib/aiOperations'

const approve = vi.fn()
const refreshScope = vi.fn()
const cancel = vi.fn()
const savePlan = vi.fn()
const extendBudget = vi.fn()
const getOperationReview = vi.fn()
const getOperationChanges = vi.fn()
const items = vi.fn()
const getRun = vi.fn()
const blocksIndex = vi.fn()
const questions = vi.fn()
const questionMembers = vi.fn()
const answeredQuestions = vi.fn()
const answerQuestions = vi.fn()
const folders = [
  { id: 'folder-root', name: 'Products', parent_id: null, settings: {} },
  { id: 'folder-shoes', name: 'Shoes', parent_id: 'folder-root', settings: { additional_fields: [{ key: 'credit', label: 'Photo credit', required: false }] } },
  { id: 'folder-press', name: 'Press', parent_id: null, settings: { additional_fields: [{ key: 'source', label: 'Source', required: false }] } },
]

vi.mock('~/api', () => ({
  api: {
    forSpace: () => ({
      aiOperations: { approve, refreshScope, cancel, savePlan, extendBudget, items, questions, questionMembers, answeredQuestions, answerQuestions, list: vi.fn().mockResolvedValue({ data: [] }) },
      ai: { getRun, getOperationReview, getOperationChanges },
      blocks: { index: blocksIndex },
      assetFolders: { index: vi.fn().mockResolvedValue({ data: folders }) },
    }),
  },
}))

const AiOperationPanel = (await import('~/components/ai/operations/AiOperationPanel.vue')).default
const { queryKeys } = await import('~/composables/useQueryClient')

const operation = (overrides: Partial<AiOperation> = {}): AiOperation => ({
  id: 'op-1',
  kind: 'translation',
  state: 'draft',
  pause_reason: null,
  pause_requested: false,
  error: null,
  skill: null,
  surface: 'bulk:content',
  prompt: 'Translate the shop into German',
  conversation_id: 'thread-1',
  schedule_id: null,
  scope: { type: 'content', ids: ['folder-1'], recursive: true },
  counts: {
    total: 1200,
    supported: 1200,
    unsupported: 0,
    groups: { page: 1000, product: 200 },
    tokens: 100,
    estimate: { input_tokens: 10, output_tokens: 10, cost_usd: 2 },
    scope_hash: 's'.repeat(64),
  },
  plan: { summary: 'Translate every page', rules: [], options: [], locales: ['de'], include: ['missing'] },
  plan_hash: 'a'.repeat(64),
  plan_run_id: null,
  approved_plan: null,
  approved_plan_hash: null,
  approved_at: null,
  budget: { max_usd: 0, spent_usd: 0, remaining_usd: 0 },
  usage: { input_tokens: 0, output_tokens: 0 },
  progress: {
    by_status: { pending: 1200, running: 0, written: 0, proposed: 0, conflict: 0, unchanged: 0, skipped: 0, failed: 0 },
    owed: 1200,
    reasons: {},
    batches: 0,
  },
  current_run: null,
  changeset_id: null,
  changeset: null,
  created_at: '2026-10-31T10:00:00Z',
  finished_at: null,
  ...overrides,
})

const mountPanel = async (value: AiOperation) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  queryClient.setQueryData(queryKeys.spaces.detail('space-1'), {
    id: 'space-1',
    settings: {
      default_language: 'en',
      languages: [{ code: 'de', name: 'Deutsch' }],
      asset_fields: [
        { key: 'alt', label: 'Alt text', required: true },
        { key: 'title', label: 'Title', required: false },
      ],
    },
  })
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] })
  const wrapper = mount(AiOperationPanel, {
    props: { spaceId: 'space-1', operation: value, changeset: null },
    global: { plugins: [router, [VueQueryPlugin, { queryClient }]], stubs: { Icon: true } },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountPanel>>, text: string) => {
  const found = wrapper.findAll('button').find((candidate) => candidate.text().includes(text))
  if (!found) throw new Error(`No button "${text}"`)
  return found
}

const scopeChangedError = Object.assign(new Error('The scope changed'), {
  status: 409,
  data: { reason: 'scope_changed', added: 3, removed: 1 },
})

const assetOperation = (overrides: Partial<AiOperation> = {}) =>
  operation({
    kind: 'assets',
    surface: 'bulk:assets',
    scope: { type: 'asset', root_id: 'folder-root', recursive: true },
    plan: {
      summary: 'Describe every product shot',
      rules: [],
      options: [],
      permissions: { overwrite: false, remove_tags: false, move: false },
      overwrite_fields: [],
      languages: [],
      structure: { folders: [], tags: [] },
    },
    ...overrides,
  })

const labelled = (wrapper: Awaited<ReturnType<typeof mountPanel>>, selector: string, text: string) => {
  const found = wrapper.findAll(`${selector} label`).find((candidate) => candidate.text().includes(text))
  if (!found) throw new Error(`No option "${text}" in ${selector}`)
  return found
}

const META = { current_page: 1, last_page: 1, per_page: 20, total: 1 }

const questionPage = (data: unknown[], summary: Record<string, unknown> = {}) => ({
  data,
  meta: META,
  summary: { accepting: true, questions: data.length, outputs: 3, entries: 2, answered: 0, resuming: 0, ...summary },
})

const modelQuestion = {
  id: 'q'.repeat(32),
  question: 'Which mattress model is shown?',
  note: 'The image does not show a label.',
  fingerprint: 'f'.repeat(64),
  outputs: 3,
  entries: 2,
}

const member = (id: string, label: string, part: string) => ({
  id,
  item_id: 1,
  target_type: 'content',
  target_id: `entry-${id}`,
  label,
  part,
  question: modelQuestion.question,
  note: modelQuestion.note,
})

const finishedOperation = (overrides: Partial<AiOperation> = {}) =>
  operation({
    state: 'completed',
    approved_plan: { summary: 'Fill metadata', rules: [], options: [], metadata: { keys: ['title'], overwrite_keys: [], fields: [], locales: [] } },
    budget: { max_usd: 5, spent_usd: 2, remaining_usd: 3 },
    finished_at: '2026-11-02T10:00:00Z',
    ...overrides,
  })

beforeEach(() => {
  approve.mockReset()
  refreshScope.mockReset()
  cancel.mockReset()
  savePlan.mockReset()
  extendBudget.mockReset()
  getOperationReview.mockReset()
  getOperationChanges.mockReset().mockResolvedValue({ data: [], summary: { changed: 0, conflicts: 0, flagged: 0 }, meta: { total: 0, page: 1, per_page: 25 } })
  items.mockReset()
  getRun.mockReset()
  blocksIndex.mockReset()
  questionMembers.mockReset()
  answeredQuestions.mockReset()
  answerQuestions.mockReset()
  questions.mockReset()
  questions.mockResolvedValue(questionPage([], { questions: 0, outputs: 0, entries: 0 }))
  blocksIndex.mockResolvedValue({
    data: [
      { id: 'b1', slug: 'page', schema: { seo: { type: 'meta', name: 'Search listing' }, title: { type: 'text', name: 'Title' } } },
      { id: 'b2', slug: 'product', schema: { share: { type: 'meta', name: 'Sharing' } } },
      { id: 'b3', slug: 'unused', schema: { other: { type: 'meta', name: 'Not in scope' } } },
    ],
  })
})

describe('AiOperationPanel', () => {
  it('shows the frozen counts and approves the shown plan hash with the budget', async () => {
    approve.mockResolvedValue({ data: operation({ state: 'running' }) })
    const wrapper = await mountPanel(operation())

    expect(wrapper.text()).toContain('1,200')
    expect(wrapper.text()).toContain('The selection and everything below it. Approval locks the item list;')

    await button(wrapper, 'Approve and start on 1,200 entries').trigger('click')
    await flushPromises()

    expect(approve).toHaveBeenCalledWith('op-1', { plan_hash: 'a'.repeat(64), scope_hash: 's'.repeat(64), max_usd: 2.5 })
    wrapper.unmount()
  })

  it('offers a recount when the scope changed and approves again only on request, with the new hash', async () => {
    approve.mockRejectedValueOnce(scopeChangedError)
    const recounted = operation({ counts: { ...operation().counts, total: 1202, supported: 1202, scope_hash: 't'.repeat(64) } })
    refreshScope.mockResolvedValue({ data: recounted })
    const wrapper = await mountPanel(operation())

    await button(wrapper, 'Approve and start').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-scope-changed]').text()).toContain('3 added, 1 removed')
    expect(button(wrapper, 'Approve and start').attributes('disabled')).toBeDefined()

    await button(wrapper, 'Recount the scope').trigger('click')
    await flushPromises()
    await wrapper.setProps({ operation: recounted })

    expect(refreshScope).toHaveBeenCalledWith('op-1')
    expect(wrapper.text()).toContain('Recounted: 1,202 entries, 1,200 before.')
    expect(approve).toHaveBeenCalledTimes(1)

    await button(wrapper, 'Approve and start on 1,202 entries').trigger('click')
    await flushPromises()

    expect(approve).toHaveBeenLastCalledWith('op-1', { plan_hash: 'a'.repeat(64), scope_hash: 't'.repeat(64), max_usd: 2.5 })
    wrapper.unmount()
  })

  it('keeps approval closed when the recounted scope is too large and offers to cancel', async () => {
    approve.mockRejectedValueOnce(scopeChangedError)
    refreshScope.mockRejectedValue(
      Object.assign(new Error('Too large'), { status: 422, data: { reason: 'scope_too_large', count: 10400, limit: 10000 } })
    )
    cancel.mockResolvedValue({ data: operation({ state: 'cancelled' }) })
    const wrapper = await mountPanel(operation())

    await button(wrapper, 'Approve and start').trigger('click')
    await flushPromises()
    await button(wrapper, 'Recount the scope').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('The scope now holds 10,400 entries, more than the 10,000 one operation takes.')
    expect(button(wrapper, 'Approve and start').attributes('disabled')).toBeDefined()

    await button(wrapper, 'Cancel operation').trigger('click')
    await flushPromises()

    expect(cancel).toHaveBeenCalledWith('op-1')
    expect(approve).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('lets an asset plan name the fields an overwrite may replace and the languages it writes', async () => {
    savePlan.mockResolvedValue({ data: assetOperation() })
    const wrapper = await mountPanel(assetOperation())

    expect(wrapper.find('[data-overwrite-fields]').exists()).toBe(false)
    await labelled(wrapper, 'fieldset', 'Replace existing values').get('[role="switch"]').trigger('click')

    const fields = wrapper.get('[data-overwrite-fields]').text()
    expect(fields).toContain('Alt text')
    expect(fields).toContain('Photo credit')
    expect(fields).not.toContain('Source')
    expect(fields).toContain('Pick the fields whose existing values the AI may replace.')

    await labelled(wrapper, '[data-overwrite-fields]', 'Photo credit').get('[role="checkbox"]').trigger('click')
    await labelled(wrapper, '[data-languages]', 'English (default)').get('[role="checkbox"]').trigger('click')
    await button(wrapper, 'Save plan').trigger('click')
    await flushPromises()

    expect(savePlan).toHaveBeenCalledWith('op-1', {
      summary: 'Describe every product shot',
      rules: [],
      options: {},
      permissions: { overwrite: true, remove_tags: false, move: false },
      overwrite_fields: ['credit'],
      languages: ['_default'],
      structure: { folders: [], tags: [] },
    })
    wrapper.unmount()
  })

  it('shows the approved asset plan with field and language names', async () => {
    getOperationReview.mockResolvedValue({ data: [] })
    const approved = {
      ...assetOperation().plan!,
      permissions: { overwrite: true, remove_tags: false, move: false },
      overwrite_fields: ['alt', 'credit'],
      languages: [],
    }
    const wrapper = await mountPanel(assetOperation({ state: 'completed', approved_plan: approved }))

    await button(wrapper, 'Approved plan').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-overwrite-fields]').text()).toContain('Alt text, Photo credit')
    expect(wrapper.get('[data-languages]').text()).toContain('Every language')
    expect(wrapper.find('[data-languages] [role="checkbox"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('reviews an operation without a planning turn from its changeset summary and refetches as proposals arrive', async () => {
    getOperationReview.mockResolvedValue({ data: [] })
    const summary = {
      id: 'changeset-1',
      title: 'Rewrite the summaries',
      status: 'open' as const,
      inline: false,
      operation_id: 'op-1',
      item_count: 40,
      pending_count: 40,
      created_at: '2026-10-31T10:00:00Z',
    }
    const running = (proposed: number, changeset: typeof summary) =>
      operation({
        state: 'running',
        approved_plan: operation().plan,
        changeset_id: 'changeset-1',
        changeset,
        progress: {
          by_status: { pending: 1200 - proposed, running: 0, written: 0, proposed, conflict: 0, unchanged: 0, skipped: 0, failed: 0 },
          owed: 1200 - proposed,
          reasons: {},
          batches: proposed / 20,
        },
      })
    const wrapper = await mountPanel(running(40, summary))

    expect(wrapper.find('[data-review]').exists()).toBe(true)
    expect(getOperationReview).toHaveBeenCalledTimes(1)

    await wrapper.setProps({ operation: running(60, { ...summary, item_count: 60, pending_count: 60 }) })
    await flushPromises()

    expect(wrapper.text()).toContain('Rewrite the summaries')
    expect(getOperationReview).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('blocks approval while the plan has unsaved edits and saves only the keys of its kind', async () => {
    savePlan.mockResolvedValue({
      data: operation({ plan: { summary: 'Translate every page, keep brand names', rules: [], options: [], locales: ['de'], include: ['missing'] }, plan_hash: 'b'.repeat(64) }),
    })
    const wrapper = await mountPanel(operation())

    await wrapper.get('textarea[name="ai-operation-summary"]').setValue('Translate every page, keep brand names')
    expect(button(wrapper, 'Approve and start').attributes('disabled')).toBeDefined()

    await button(wrapper, 'Save plan').trigger('click')
    await flushPromises()

    expect(savePlan).toHaveBeenCalledWith('op-1', {
      summary: 'Translate every page, keep brand names',
      rules: [],
      options: {},
      locales: ['de'],
      include: ['missing'],
    })
    wrapper.unmount()
  })

  const ledgerItem = (overrides: Partial<AiOperationItem>): AiOperationItem => ({
    id: 'item-1',
    position: 0,
    target_type: 'asset',
    target_id: 'asset-1',
    label: 'hero.jpg',
    group: 'image',
    status: 'proposed',
    reason: null,
    note: null,
    version_changed: false,
    attempts: 1,
    batch: 1,
    run_id: null,
    parts: [],
    ...overrides,
  })
  const openLedger = async (wrapper: Awaited<ReturnType<typeof mountPanel>>) => {
    await button(wrapper, 'All 1,200 entries').trigger('click')
    await flushPromises()
  }

  it('lets an asset plan name its outputs and sends them, starting a new plan explicit', async () => {
    savePlan.mockResolvedValue({ data: assetOperation() })
    const wrapper = await mountPanel(assetOperation({ plan: null, plan_hash: null }))
    await button(wrapper, 'Write it myself').trigger('click')

    await labelled(wrapper, '[data-asset-outputs]', 'Alt text').get('[role="checkbox"]').trigger('click')
    await wrapper.get('textarea[name="ai-operation-summary"]').setValue('Describe')
    await button(wrapper, 'Save plan').trigger('click')
    await flushPromises()

    expect(savePlan.mock.calls[0][1].outputs).toEqual({ fields: ['alt'], tags: true })
    wrapper.unmount()
  })

  it('keeps an AI-drafted asset plan without outputs as it is and says it is not verified', async () => {
    const wrapper = await mountPanel(assetOperation())

    expect(wrapper.get('[data-asset-outputs]').text()).toContain("don't show whether each field was filled")
    await wrapper.get('[data-asset-outputs] [role="switch"]').trigger('click')
    expect(wrapper.get('[data-asset-outputs]').text()).toContain('Each asset should end up with')
    await wrapper.get('[data-asset-outputs] [role="switch"]').trigger('click')

    expect(wrapper.findAll('button').some((candidate) => candidate.text().includes('Save plan'))).toBe(false)
    wrapper.unmount()
  })

  it('lets a metadata plan pick values, replacements and scope, offering only meta fields in scope', async () => {
    const plan: AiOperation['plan'] = { summary: 'Write metadata', rules: [], options: [] }
    savePlan.mockResolvedValue({ data: operation({ kind: 'general', plan }) })
    const wrapper = await mountPanel(operation({ kind: 'general', plan }))

    await wrapper.get('[data-metadata-outputs] [role="switch"]').trigger('click')
    await flushPromises()

    const fields = wrapper.get('[data-metadata-fields]').text()
    expect(fields).toContain('Search listing')
    expect(fields).toContain('Sharing')
    expect(fields).not.toContain('Not in scope')
    expect(fields).toContain('None picked: every metadata field on these entries.')

    await labelled(wrapper, '[data-metadata-outputs]', 'Social title').get('[role="checkbox"]').trigger('click')
    await labelled(wrapper, '[data-metadata-overwrite]', 'Title').get('[role="checkbox"]').trigger('click')
    await labelled(wrapper, '[data-metadata-fields]', 'Search listing').get('[role="checkbox"]').trigger('click')
    await labelled(wrapper, '[data-metadata-locales]', 'Deutsch').get('[role="checkbox"]').trigger('click')
    await button(wrapper, 'Save plan').trigger('click')
    await flushPromises()

    expect(savePlan.mock.calls[0][1].metadata).toEqual({
      keys: ['title', 'description', 'ogTitle'],
      overwrite_keys: ['title'],
      fields: ['seo'],
      locales: ['de'],
    })
    wrapper.unmount()
  })

  it('links metadata to a translation plan with its target locales and names the open decision', async () => {
    const plan: AiOperation['plan'] = { summary: 'Translate', rules: [], options: [], locales: ['de'], include: ['missing'] }
    savePlan.mockResolvedValue({ data: operation({ plan }) })
    const wrapper = await mountPanel(operation({ plan }))

    await wrapper.get('[data-metadata-outputs] [role="switch"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-metadata-locales]').text()).toContain('Deutsch')
    expect(wrapper.get('[data-metadata-locales]').text()).not.toContain('English')

    await button(wrapper, 'Save plan').trigger('click')
    await flushPromises()
    expect(savePlan.mock.calls[0][1].metadata).toEqual({ keys: ['title', 'description'], overwrite_keys: [], fields: [], locales: [] })
    wrapper.unmount()
  })

  it('shows the approved outputs and metadata requirements in the plan summary', async () => {
    getOperationReview.mockResolvedValue({ data: [] })
    const assets = await mountPanel(
      assetOperation({ state: 'completed', approved_plan: { ...assetOperation().plan!, outputs: { fields: ['alt', 'credit'], tags: true } } })
    )
    await button(assets, 'Approved plan').trigger('click')
    await flushPromises()
    expect(assets.get('[data-asset-outputs]').text()).toContain('Alt text, Photo credit')
    expect(assets.get('[data-asset-outputs]').text()).toContain('Tags')
    assets.unmount()

    const metadata = await mountPanel(
      operation({
        kind: 'general',
        state: 'completed',
        approved_plan: { summary: 'M', rules: [], options: [], metadata: { keys: ['title', 'ogTitle'], overwrite_keys: [], fields: [], locales: ['de'] } },
      })
    )
    await button(metadata, 'Approved plan').trigger('click')
    await flushPromises()
    const shown = metadata.get('[data-metadata-outputs]').text()
    expect(shown).toContain('Title, Social title')
    expect(shown).toContain('None. Only empty values are filled.')
    expect(shown).toContain('All metadata fields used in this scope')
    expect(shown).toContain('Deutsch')
    metadata.unmount()
  })

  it('tells complete, partial and unverified coverage apart whatever the state says', async () => {
    const finished = (coverage: NonNullable<AiOperation['counts']['coverage']> | undefined, approved_plan: AiOperation['approved_plan']) =>
      assetOperation({
        state: 'completed',
        approved_plan,
        counts: { ...assetOperation().counts, coverage },
        progress: { by_status: { pending: 0, running: 0, written: 0, proposed: 1200, conflict: 0, unchanged: 0, skipped: 0, failed: 0 }, owed: 0, reasons: {}, batches: 3 },
      })
    const named = { ...assetOperation().plan!, outputs: { fields: ['alt'], tags: true } }

    const complete = await mountPanel(finished({ verified: true, complete: true, outputs: { proposed: 5, satisfied: 2 }, entries: { proposed: 7 } }, named))
    expect(complete.get('[data-coverage-state]').text()).toBe('Every requested output is accounted for')
    expect(complete.text()).toContain('Done')
    complete.unmount()

    const partial = await mountPanel(finished({ verified: true, complete: false, outputs: { proposed: 5, uninferable: 1, blocked: 2 }, entries: { proposed: 7, skipped: 1 } }, named))
    expect(partial.get('[data-coverage-state]').text()).toBe('Not every requested output was fulfilled')
    expect(partial.text()).toContain('Done with the queue, but some requested outputs are missing')
    expect(partial.text()).toContain("Couldn't be determined")
    expect(partial.text()).toContain('1 skipped and 0 failed entries count as missing.')
    expect(partial.get('[data-coverage-questions]').text()).toContain('The questions are listed below')
    expect(partial.findAll('button').some((candidate) => /answer/i.test(candidate.text()))).toBe(false)
    partial.unmount()

    const unverified = await mountPanel(finished({ verified: false, complete: null, outputs: {}, entries: { proposed: 7 } }, assetOperation().plan))
    expect(unverified.get('[data-coverage-state]').text()).toBe("Coverage isn't verified")
    expect(unverified.get('[data-operation-receipt]').text()).not.toContain('accounted for')
    unverified.unmount()
  })

  it('says what happened in the receipt: changed entries, conflicts, flagged wording and what waits', async () => {
    getOperationReview.mockResolvedValue({
      data: [
        { key: 'a', operation: 'update', target_type: 'asset', path: 'title', label: null, field_type: 'text', counts: { pending: 5, conflict: 1, accepted: 3, rejected: 1 }, bulk: 5, individual: 1, queued: null, examples: [] },
      ],
    })
    getOperationChanges.mockResolvedValue({ data: [], summary: { changed: 412, conflicts: 3, flagged: 7 }, meta: { total: 412, page: 1, per_page: 1 } })
    const changeset = { id: 'c1', title: 'Change', status: 'open' as const, inline: false, operation_id: 'op-1', item_count: 10, pending_count: 6, created_at: '2026-10-31T10:00:00Z' }
    const wrapper = await mountPanel(operation({ state: 'completed', approved_plan: operation().plan, changeset_id: 'c1', changeset }))
    await flushPromises()
    const receipt = wrapper.get('[data-operation-receipt]')

    expect(receipt.get('[data-changes-happened]').text()).toBe('Changed 412 entries, 3 conflicts, 7 flagged')
    expect(receipt.get('[data-proposals-waiting]').text()).toContain('6 asset or data entry changes wait for you')
    expect(receipt.text()).toContain('Nothing is published')
    wrapper.unmount()
  })

  it('says once before approving what happens to content', async () => {
    const wrapper = await mountPanel(operation())
    await flushPromises()

    expect(wrapper.findAll('[data-writes-notice]')).toHaveLength(1)
    expect(wrapper.get('[data-writes-notice]').text()).toContain('written to drafts straight away and nothing is published')
    wrapper.unmount()
  })

  it('shows field outcomes by language with reason, note and question, only once opened', async () => {
    items.mockResolvedValue({
      data: [
        ledgerItem({
          expected_parts: ['de.alt', 'de.title', 'tags'],
          parts: {
            'de.alt': { outcome: 'blocked', reason: 'missing_fact', note: 'Name is not on the image', question: 'Which product is this?' },
            'de.title': { outcome: 'satisfied', reason: 'has_value' },
            tags: { outcome: 'proposed' },
          },
        }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })
    const wrapper = await mountPanel(assetOperation({ state: 'completed', approved_plan: { ...assetOperation().plan!, outputs: { fields: ['alt', 'title'], tags: true } } }))
    await openLedger(wrapper)

    const summary = wrapper.get('[data-output-summary]')
    expect(summary.text()).toContain('2 of 3 outputs accounted for. Needs an answer: 1')
    expect(wrapper.find('[data-output-row]').exists()).toBe(false)

    await summary.trigger('click')
    const rows = wrapper.findAll('[data-output-row]').map((row) => row.text())
    expect(rows[0]).toContain('Alt text')
    expect(rows[0]).toContain('Needs an answer')
    expect(rows[0]).toContain('Name is not on the image')
    expect(rows[0]).toContain('Which product is this?')
    expect(rows[1]).toContain('Already filled')
    expect(rows[1]).toContain('It already has a value')
    expect(rows[2]).toContain('Tags')
    expect(wrapper.get('[role="group"]').text()).toContain('Deutsch')
    wrapper.unmount()
  })

  it('shows a unit settled alone and nested metadata under their language', async () => {
    items.mockResolvedValue({
      data: [
        ledgerItem({
          expected_parts: ['de', 'de.body.block-1.seo.title'],
          parts: {
            de: { outcome: 'proposed' },
            'de#summary': { outcome: 'blocked', reason: 'agent', question: 'Which model?' },
            'de.body.block-1.seo.title': { outcome: 'skipped', reason: 'awaiting_translation' },
          },
        }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })
    const wrapper = await mountPanel(
      operation({
        state: 'completed',
        approved_plan: { summary: 'T', rules: [], options: [], locales: ['de'], include: ['missing'], metadata: { keys: ['title'], overwrite_keys: [], fields: [], locales: [] } },
      })
    )
    await openLedger(wrapper)
    await wrapper.get('[data-output-summary]').trigger('click')

    const rows = wrapper.findAll('[data-output-row]').map((row) => row.text())
    expect(rows.some((row) => row.includes('Unit summary') && row.includes('Which model?'))).toBe(true)
    expect(rows.some((row) => row.includes('(body block-1)') && row.includes('Waits for the translation'))).toBe(true)
    wrapper.unmount()
  })

  it('shows per-locale translation units and metadata values by name', async () => {
    items.mockResolvedValueOnce({
      data: [ledgerItem({ parts: { de: { outcome: 'proposed' } }, status: 'running', units: { de: { written: ['title', 'intro'], remaining: ['body.0.text'] } } })],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })
    const translation = await mountPanel(operation({ state: 'completed', approved_plan: operation().plan }))
    await openLedger(translation)
    await translation.get('[data-output-summary]').trigger('click')

    const locale = translation.get('[data-output-row]').text()
    expect(locale).toContain('Deutsch')
    expect(locale).toContain('2 written, 1 remaining')
    expect(locale).toContain('Still open: body.0.text')
    translation.unmount()

    items.mockResolvedValueOnce({
      data: [ledgerItem({ status: 'pending', expected_parts: ['de.seo.title'], overwritable: ['de.seo.title'] })],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })
    const metadata = await mountPanel(
      operation({ kind: 'general', state: 'completed', approved_plan: { summary: 'M', rules: [], options: [], metadata: { keys: ['title'], overwrite_keys: ['title'], fields: [], locales: [] } } })
    )
    await openLedger(metadata)
    await flushPromises()
    await metadata.get('[data-output-summary]').trigger('click')

    const value = metadata.get('[data-output-row]').text()
    expect(value).toContain('Search listing: Title')
    expect(value).toContain('Waiting')
    expect(value).toContain('May replace the existing value')
    metadata.unmount()
  })

  it('shows a provider wait with its time and attempt, and unconfirmed calls beside the spend', async () => {
    getRun.mockResolvedValue({
      data: {
        id: 'run-1',
        state: 'running',
        usage: { steps: 1, input_tokens: 1, output_tokens: 1, cached_tokens: 0, cost_usd: 0.1, cost_confirmed_at: '2026-11-02T10:00:00Z' },
        provider_retry: {
          attempts: 2,
          total: 2,
          unconfirmed_calls: 1,
          waited_seconds: 60,
          not_before: new Date(Date.now() + 120_000).toISOString(),
          failure: { kind: 'rate_limited', status: 429, code: null },
        },
      },
    })
    const wrapper = await mountPanel(
      operation({
        state: 'running',
        approved_plan: operation().plan,
        current_run: { id: 'run-1', state: 'running' },
        budget: { max_usd: 2, spent_usd: 1, remaining_usd: 1 },
        counts: { ...operation().counts, provider_retries: 1, unconfirmed_provider_calls: 1 },
      })
    )
    await flushPromises()
    const notice = wrapper.get('[data-provider-retry]').text()

    expect(notice).toContain('The AI provider is limiting requests.')
    expect(notice).toContain('Trying again at')
    expect(notice).toContain('Attempt 2.')
    expect(notice).toContain('The provider needed 3 retries so far.')
    expect(notice).toContain('2 interrupted calls may have cost more than the spend shown.')
    expect(notice).not.toContain('Known costs are confirmed')
    wrapper.unmount()
  })

  it('says nothing about providers when nothing went wrong and localizes a stored provider error', async () => {
    const quiet = await mountPanel(operation({ state: 'completed', approved_plan: operation().plan }))
    expect(quiet.find('[data-provider-retry]').exists()).toBe(false)
    quiet.unmount()

    const failed = await mountPanel(operation({ state: 'paused', pause_reason: 'batch_failed', error: 'provider_unavailable: 01JABC', approved_plan: operation().plan }))
    expect(failed.text()).toContain('The AI provider is currently unavailable.')
    expect(failed.text()).not.toContain('01JABC')
    failed.unmount()
  })

  it('follows the app language where it has copy and falls back to English elsewhere', async () => {
    const { i18n, loadLocaleMessages } = await import('~/plugins/i18n')
    const composer = i18n.global as unknown as { locale: { value: string } }
    await loadLocaleMessages('de')
    await loadLocaleMessages('fr')
    const counted = { verified: false, complete: null, outputs: {}, entries: {} }
    try {
      composer.locale.value = 'de'
      const german = await mountPanel(operation({ state: 'completed', approved_plan: operation().plan, counts: { ...operation().counts, coverage: counted } }))
      expect(german.get('[data-coverage-state]').text()).toBe('Vollständigkeit nicht geprüft')
      german.unmount()

      composer.locale.value = 'fr'
      const french = await mountPanel(operation({ state: 'completed', approved_plan: operation().plan, counts: { ...operation().counts, coverage: counted } }))
      expect(french.get('[data-coverage-state]').text()).toBe("Coverage isn't verified")
      french.unmount()
    } finally {
      composer.locale.value = 'en'
    }
  })

  it('reports a budget pause and continues only after an explicit new total', async () => {
    extendBudget.mockResolvedValue({ data: operation({ state: 'running' }) })
    const wrapper = await mountPanel(
      operation({
        state: 'paused',
        pause_reason: 'budget',
        approved_plan: operation().plan,
        budget: { max_usd: 2, spent_usd: 2, remaining_usd: 0 },
        progress: {
          by_status: { pending: 700, running: 0, written: 0, proposed: 480, conflict: 0, unchanged: 10, skipped: 6, failed: 4 },
          owed: 700,
          reasons: { skipped: { deleted: 6 }, failed: { not_processed: 4 } },
          batches: 25,
        },
      })
    )

    expect(wrapper.text()).toContain('Paused: the budget is spent')
    expect(wrapper.text()).toContain('500 of 1,200 entries worked on')
    expect(wrapper.text()).toContain('Not processed after two attempts')
    expect(wrapper.findAll('button').some((candidate) => candidate.text() === 'Continue')).toBe(false)

    await button(wrapper, 'Extend and continue').trigger('click')
    await flushPromises()

    expect(extendBudget).toHaveBeenCalledWith('op-1', 3)
    wrapper.unmount()
  })

  it('groups the questions and sends a group answer and a single-output answer at the shown membership', async () => {
    questions.mockResolvedValue(questionPage([modelQuestion]))
    questionMembers.mockResolvedValue({ data: [member('m1', 'Comfort page', 'en.seo.title'), member('m2', 'Deluxe page', 'en.seo.title')], meta: META })
    const resumed = finishedOperation({ state: 'running' })
    answerQuestions.mockResolvedValue({ data: { operation: resumed, results: [
      { question_id: modelQuestion.id, status: 'recorded', recorded: 1, duplicate: 0, stale: 0 },
      { question_id: modelQuestion.id, status: 'recorded', recorded: 2, duplicate: 0, stale: 0 },
    ] } })
    const wrapper = await mountPanel(finishedOperation())

    const section = wrapper.get('[data-operation-questions]')
    expect(section.text()).toContain('Which mattress model is shown?')
    expect(section.text()).toContain('3 outputs in 2 entries')
    expect(section.text()).toContain('What you leave blank stays blocked.')
    expect(section.text()).toContain('$3.00')
    const sendButton = wrapper.get('[data-questions-send]')
    expect(sendButton.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-question-answer]').setValue('  The Comfort 200.  ')
    await button(wrapper, 'Show entries').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[data-question-member]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Deluxe page')
    await wrapper.findAll('[data-member-answer]')[1].setValue('The Deluxe 300.')
    expect(sendButton.text()).toContain('Send 2 answers and continue')

    await sendButton.trigger('click')
    await flushPromises()

    expect(answerQuestions).toHaveBeenCalledWith('op-1', [
      { question_id: modelQuestion.id, answer: 'The Deluxe 300.', member_ids: ['m2'] },
      { question_id: modelQuestion.id, answer: 'The Comfort 200.', fingerprint: modelQuestion.fingerprint },
    ])
    wrapper.unmount()
  })

  it('keeps the text of a stale answer and tells the editor to check it', async () => {
    questions.mockResolvedValue(questionPage([modelQuestion]))
    answerQuestions.mockResolvedValue({ data: { operation: finishedOperation(), results: [
      { question_id: modelQuestion.id, status: 'stale', recorded: 0, duplicate: 0, stale: 1 },
    ] } })
    const wrapper = await mountPanel(finishedOperation())

    await wrapper.get('[data-question-answer]').setValue('The Comfort 200.')
    await wrapper.get('[data-questions-send]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-questions-notice]').text()).toContain('Your text is still here')
    expect((wrapper.get('[data-question-answer]').element as HTMLTextAreaElement).value).toBe('The Comfort 200.')
    wrapper.unmount()
  })

  it('shows questions read-only until the work has finished, and lists answers given with who and when', async () => {
    questions.mockResolvedValue(questionPage([modelQuestion], { accepting: false, answered: 1, resuming: 1 }))
    answeredQuestions.mockResolvedValue({
      data: [{ question: 'Which warranty applies?', answer: 'Ten years.', answered_by: { id: 'u1', name: 'Mike' }, answered_at: '2026-11-02T10:00:00Z', outputs: 2, entries: 1 }],
      meta: META,
    })
    const wrapper = await mountPanel(finishedOperation({ state: 'running' }))

    const section = wrapper.get('[data-operation-questions]')
    expect(section.text()).toContain('You can answer once it has finished.')
    expect(section.text()).toContain('1 answered output is waiting to run.')
    expect(wrapper.find('[data-question-answer]').exists()).toBe(false)
    expect(wrapper.find('[data-questions-send]').exists()).toBe(false)

    await button(wrapper, 'Answered (1)').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-answered]').text()).toContain('Ten years.')
    expect(wrapper.get('[data-answered]').text()).toContain('Mike')
    expect(wrapper.get('[data-answered]').text()).toContain('2 outputs')
    wrapper.unmount()
  })
})
