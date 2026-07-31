import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { SseCallbacks } from '~/lib/sse'

const stream = vi.fn()
const cancelStream = vi.fn()
const isStreaming = ref(false)

// useAiStream is the transport: CSRF handshake, fetch and the SSE reader loop.
vi.mock('~/composables/useAiStream', () => ({
  useAiStream: () => ({ stream, cancelStream, isStreaming }),
}))

const { useDataEntryTranslation } = await import('~/composables/useDataEntryTranslation')

const url = (spaceId: string, dataSourceId: string) =>
  `/mgmt/v1/spaces/${spaceId}/data-sources/${dataSourceId}/entries/translate-missing-dimensions/stream`

const callbacks = (): SseCallbacks => ({ onError: vi.fn(), onDone: vi.fn() }) as SseCallbacks

beforeEach(() => {
  stream.mockReset()
  cancelStream.mockReset()
  isStreaming.value = false
})

describe('streamMissingDimensionsTranslation', () => {
  it('posts the target dimension to the entry translation stream', async () => {
    const cb = callbacks()
    const { streamMissingDimensionsTranslation } = useDataEntryTranslation('space-1', 'ds-1')

    await streamMissingDimensionsTranslation('de', cb)

    expect(stream).toHaveBeenCalledWith(url('space-1', 'ds-1'), { target_dimension: 'de' }, cb)
  })

  it('unwraps refs at call time, not at composable setup', async () => {
    const spaceId = ref('space-1')
    const dataSourceId = ref('ds-1')
    const { streamMissingDimensionsTranslation } = useDataEntryTranslation(spaceId, dataSourceId)

    spaceId.value = 'space-2'
    dataSourceId.value = 'ds-2'
    await streamMissingDimensionsTranslation('fr', callbacks())

    expect(stream.mock.calls[0][0]).toBe(url('space-2', 'ds-2'))
  })

  it('passes an empty target dimension through — the backend validates it', async () => {
    const { streamMissingDimensionsTranslation } = useDataEntryTranslation('space-1', 'ds-1')

    await streamMissingDimensionsTranslation('', callbacks())

    expect(stream.mock.calls[0][1]).toEqual({ target_dimension: '' })
  })

  it.each([
    ['', 'ds-1'],
    ['space-1', ''],
    ['', ''],
  ])('reports a missing space (%s) or data source (%s) without streaming', async (space, ds) => {
    const cb = callbacks()
    const { streamMissingDimensionsTranslation } = useDataEntryTranslation(space, ds)

    await streamMissingDimensionsTranslation('de', cb)

    expect(cb.onError).toHaveBeenCalledWith(
      'Missing space or data source. Please reload the page and try again.'
    )
    expect(stream).not.toHaveBeenCalled()
  })

  it('guards on the current ref values', async () => {
    const spaceId = ref('space-1')
    const cb = callbacks()
    const { streamMissingDimensionsTranslation } = useDataEntryTranslation(spaceId, 'ds-1')

    spaceId.value = ''
    await streamMissingDimensionsTranslation('de', cb)

    expect(stream).not.toHaveBeenCalled()
    expect(cb.onError).toHaveBeenCalled()
  })

  it('survives a callback set without onError', async () => {
    const { streamMissingDimensionsTranslation } = useDataEntryTranslation('', 'ds-1')

    await expect(streamMissingDimensionsTranslation('de', {} as SseCallbacks)).resolves.toBeUndefined()
    expect(stream).not.toHaveBeenCalled()
  })
})

describe('stream control', () => {
  it('re-exposes the shared cancel and streaming flag', () => {
    const translation = useDataEntryTranslation('space-1', 'ds-1')

    translation.cancelStream()
    expect(cancelStream).toHaveBeenCalledTimes(1)

    isStreaming.value = true
    expect(translation.isStreaming.value).toBe(true)
  })
})
