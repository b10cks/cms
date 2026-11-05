import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

import type { AiPreflight } from '~/lib/aiChangesets'

const preflight = vi.fn()
const startBackgroundRun = vi.fn()

vi.mock('~/api', () => ({
  api: { forSpace: () => ({ ai: { preflight, startBackgroundRun, getContext: vi.fn().mockResolvedValue({ data: { skills: [] } }) } }) },
}))
vi.mock('~/lib/aiDock', () => ({ openDock: vi.fn() }))

const operationDialog = vi.hoisted(() => vi.fn())
vi.mock('~/components/ai/operations/AiOperationDialog.vue', () => ({
  default: defineComponent({
    props: ['open', 'initialPrompt'],
    setup: (props) => {
      operationDialog(props)
      return () => null
    },
  }),
}))

const AiBulkDialog = (await import('~/components/ai/AiBulkDialog.vue')).default

const answer = (overrides: Partial<AiPreflight>): AiPreflight => ({
  type: 'content',
  count: 1,
  ids: ['a'],
  entries: [{ id: 'a', name: 'Home' }],
  model: 'openai:gpt',
  input_tokens: 10,
  output_tokens: 10,
  cost_usd: 0.5,
  max_usd: 2,
  background: false,
  inline_entry_limit: 5,
  truncated: false,
  max_entries: 500,
  ...overrides,
})

const mounted: Array<{ unmount: () => void }> = []
afterEach(() => mounted.splice(0).forEach((wrapper) => wrapper.unmount()))
beforeEach(() => {
  preflight.mockReset()
  startBackgroundRun.mockReset().mockResolvedValue({ data: { conversation_id: 'thread' } })
  operationDialog.mockReset()
})

const openDialog = async (value: AiPreflight, surface = 'bulk:content') => {
  preflight.mockResolvedValue({ data: value })
  const wrapper = mount(AiBulkDialog, {
    props: { open: false, 'onUpdate:open': () => {}, spaceId: 'space', surface, scope: { type: value.type, ids: value.ids } },
    attachTo: document.body,
    global: { plugins: [[VueQueryPlugin, { queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }) }]] },
  })
  mounted.push(wrapper)
  await wrapper.setProps({ open: true })
  await flushPromises()
  const input = document.querySelector<HTMLInputElement | HTMLTextAreaElement>('#ai-bulk-prompt')!
  input.value = 'Improve the titles'
  input.dispatchEvent(new Event('input'))
  await flushPromises()

  return document.querySelector<HTMLButtonElement>('[data-bulk-start]')!
}

describe('AiBulkDialog', () => {
  it('says content goes to drafts and proposals wait, per surface', async () => {
    await openDialog(answer({}))
    expect(document.body.textContent).toContain('written to the drafts straight away')
    expect(document.body.textContent).not.toContain('until you accept')
    mounted.splice(0).forEach((wrapper) => wrapper.unmount())

    await openDialog(answer({ type: 'asset' }), 'bulk:assets')
    expect(document.body.textContent).toContain('wait there for you to accept them')
  })

  it('runs a small task on one Start, with the singular for one entry', async () => {
    const start = await openDialog(answer({}))

    expect(start.textContent).toContain('Start on 1 entry')
    expect(start.textContent).not.toContain('entries')
    expect(document.querySelector('[data-plan-why]')).toBeNull()

    start.click()
    await flushPromises()
    expect(startBackgroundRun).toHaveBeenCalledOnce()
    expect(operationDialog).not.toHaveBeenCalled()
  })

  it.each([
    ['cost', { cost_usd: 3.5 }, 'Estimated above $2.00'],
    ['entries', { truncated: true, count: 500 }, 'More than 500 entries match'],
  ])('sends a task above the %s limit to the plan, and says so before the click', async (_reason, overrides, why) => {
    const start = await openDialog(answer(overrides))

    expect(start.textContent).toContain('Review plan')
    expect(document.querySelector('[data-plan-why]')?.textContent).toContain(why)

    start.click()
    await flushPromises()
    expect(startBackgroundRun).not.toHaveBeenCalled()
    expect(operationDialog).toHaveBeenCalled()
  })
})
