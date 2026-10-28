import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'

import { useAiRun } from '~/composables/useAiRun'
import type { AiRunEvent } from '~/lib/aiRun'

const api = vi.hoisted(() => ({ forSpace: vi.fn(), getRunEvents: vi.fn(), cancelRun: vi.fn() }))
vi.mock('~/api', () => ({
  api: {
    forSpace: (space: string) => {
      api.forSpace(space)
      return { ai: { getRunEvents: api.getRunEvents, cancelRun: api.cancelRun } }
    },
  },
}))
vi.mock('~/composables/useAuth', async () => {
  const { ref: vueRef } = await import('vue')
  return { useAuth: () => ({ user: vueRef({ id: 'user-1' }) }) }
})
vi.mock('~/composables/useEcho', () => ({ useEcho: () => null }))

const encoder = new TextEncoder()
const fetchMock = vi.fn()

const sse = (events: Record<string, unknown>[]) =>
  new Response(
    new ReadableStream<Uint8Array>({
      start(controller) {
        events.forEach((event) => controller.enqueue(encoder.encode(`data: ${JSON.stringify(event)}\n\n`)))
        controller.close()
      },
    }),
    { headers: { 'Content-Type': 'text/event-stream' } }
  )

const run = { id: 'run-1', state: 'completed', partial: false, result: null, questionnaire: null }

describe('useAiRun', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=csrf-token-1'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.clearAllMocks()
  })

  it('follows a run into the background and keeps its events in order', async () => {
    fetchMock.mockResolvedValue(
      sse([
        { type: 'run_started', run_id: 'run-1', seq: 1, skill: null, surface: 'cmdk' },
        { type: 'text_delta', run_id: 'run-1', seq: 2, text: 'Hello' },
        { type: 'moved_to_background', run_id: 'run-1', seq: 3 },
      ])
    )
    const result = { text: 'Hello world', data: null, glossary_findings: [] }
    api.getRunEvents.mockResolvedValue({
      data: [
        { type: 'moved_to_background', run_id: 'run-1', seq: 3 },
        { type: 'text_delta', run_id: 'run-1', seq: 4, text: ' world' },
        { type: 'completed', run_id: 'run-1', seq: 5, result, partial: false, usage: {} },
      ],
      run,
    })

    const seen: number[] = []
    const scope = effectScope()
    const aiRun = scope.run(() => useAiRun(ref('space-1')))!

    const outcome = await aiRun.start(
      { surface: 'cmdk', prompt: 'Hi' },
      { onEvent: (event: AiRunEvent) => seen.push(event.seq) }
    )
    scope.stop()

    expect(seen).toEqual([1, 2, 3, 4, 5])
    expect(api.getRunEvents).toHaveBeenCalledWith('run-1', 3)
    expect(aiRun.text.value).toBe('Hello world')
    expect(outcome).toMatchObject({ status: 'completed', result })
  })

  it('lets go of a background run when the space changes and keeps it on its own space', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    fetchMock.mockResolvedValue(
      sse([
        { type: 'run_started', run_id: 'run-1', seq: 1, skill: null, surface: 'cmdk' },
        { type: 'moved_to_background', run_id: 'run-1', seq: 2 },
      ])
    )
    api.getRunEvents.mockResolvedValue({ data: [], run: { ...run, state: 'running' } })

    const spaceId = ref('space-1')
    const scope = effectScope()
    const aiRun = scope.run(() => useAiRun(spaceId))!
    const outcome = aiRun.start({ surface: 'cmdk', prompt: 'Hi' })
    await vi.waitFor(() => expect(api.getRunEvents).toHaveBeenCalled())

    spaceId.value = 'space-2'
    await nextTick()
    const polled = api.getRunEvents.mock.calls.length
    vi.advanceTimersByTime(10_000)
    scope.stop()
    vi.useRealTimers()

    expect(await outcome).toMatchObject({ status: 'cancelled' })
    expect(aiRun.status.value).toBe('idle')
    expect(api.getRunEvents).toHaveBeenCalledTimes(polled)
    expect(api.cancelRun).not.toHaveBeenCalled()
    expect(new Set(api.forSpace.mock.calls.map(([space]) => space))).toEqual(new Set(['space-1']))
    expect(fetchMock.mock.calls[0][0]).toContain('space-1')
  })

  it('collects the proposals a run records into its changeset', async () => {
    const proposal = { changeset_id: 'cs-1', item_id: 'item-1', target_type: 'selection', target_id: null, path: null }
    const result = { text: 'Shorter.', data: null, glossary_findings: [] }
    fetchMock.mockResolvedValue(
      sse([
        { type: 'run_started', run_id: 'run-1', seq: 1, skill: 'shorten', surface: 'selection' },
        { type: 'proposal', run_id: 'run-1', seq: 2, proposal },
        { type: 'completed', run_id: 'run-1', seq: 3, result, partial: false, usage: {} },
      ])
    )

    const scope = effectScope()
    const aiRun = scope.run(() => useAiRun(ref('space-1')))!
    await aiRun.start({ surface: 'selection', skill: 'shorten' })
    scope.stop()

    expect(aiRun.proposals.value).toEqual([proposal])
  })
})
