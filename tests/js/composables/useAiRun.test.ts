import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, ref } from 'vue'

import { useAiRun } from '~/composables/useAiRun'
import type { AiRunEvent } from '~/lib/aiRun'

const api = vi.hoisted(() => ({ getRunEvents: vi.fn(), cancelRun: vi.fn() }))
vi.mock('~/api', () => ({
  api: { forSpace: () => ({ ai: { getRunEvents: api.getRunEvents, cancelRun: api.cancelRun } }) },
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
})
