import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAiStream } from '~/composables/useAiStream'

const encoder = new TextEncoder()
const fetchMock = vi.fn()

/** A body that hands the SSE lines over in the order given. */
const body = (chunks: string[]) =>
  new ReadableStream<Uint8Array>({
    start(controller) {
      chunks.forEach((chunk) => controller.enqueue(encoder.encode(chunk)))
      controller.close()
    },
  })

const frame = (event: Record<string, unknown>) => `data: ${JSON.stringify(event)}\n\n`

const spies = () => ({
  onStatus: vi.fn(),
  onDelta: vi.fn(),
  onDone: vi.fn(),
  onError: vi.fn(),
})

const setXsrfCookie = (value = 'csrf-token-1') => {
  document.cookie = `XSRF-TOKEN=${value}`
}

const clearXsrfCookie = () => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
}

/** The request init the composable handed to fetch for the streaming call. */
const streamRequest = () => {
  const call = fetchMock.mock.calls.find(([url]) => url !== '/auth/v1/csrf-cookie')
  return { url: call?.[0] as string, init: (call?.[1] ?? {}) as RequestInit }
}

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  setXsrfCookie()
})

afterEach(() => {
  vi.unstubAllGlobals()
  clearXsrfCookie()
})

describe('the streaming request', () => {
  it('posts the payload as an event-stream request with the CSRF header', async () => {
    fetchMock.mockImplementation(
      async () => new Response(body([frame({ type: 'done', content: 'Hi' })]))
    )

    await useAiStream().stream('/mgmt/v1/ai/thing/stream', { prompt: 'Hello' }, spies())

    const { url, init } = streamRequest()

    expect(url).toBe('/mgmt/v1/ai/thing/stream')
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('include')
    expect(init.body).toBe(JSON.stringify({ prompt: 'Hello' }))
    expect(init.headers).toMatchObject({
      'Content-Type': 'application/json',
      Accept: 'text/event-stream',
      'X-XSRF-TOKEN': 'csrf-token-1',
    })
    expect(init.signal).toBeInstanceOf(AbortSignal)
  })

  it('forwards every event from the body to the callbacks', async () => {
    fetchMock.mockImplementation(
      async () =>
        new Response(
          body([
            frame({ type: 'status', message: 'Thinking' }),
            frame({ type: 'delta', content: 'He' }),
            frame({ type: 'delta', content: 'llo' }),
            frame({ type: 'done', content: 'Hello', data: { tokens: 3 } }),
          ])
        )
    )
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onStatus).toHaveBeenCalledWith('Thinking')
    expect(callbacks.onDelta.mock.calls.map(([content]) => content)).toEqual(['He', 'llo'])
    expect(callbacks.onDone).toHaveBeenCalledWith('Hello', { tokens: 3 })
    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('fetches the CSRF cookie first when none is set yet', async () => {
    clearXsrfCookie()
    fetchMock.mockImplementation(async (url: string) => {
      if (url === '/auth/v1/csrf-cookie') {
        setXsrfCookie('minted')
        return new Response('', { status: 204 })
      }
      return new Response(body([frame({ type: 'done', content: 'Hi' })]))
    })
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(fetchMock.mock.calls[0][0]).toBe('/auth/v1/csrf-cookie')
    expect(streamRequest().init.headers).toMatchObject({ 'X-XSRF-TOKEN': 'minted' })
    expect(callbacks.onDone).toHaveBeenCalledWith('Hi', undefined)
  })

  it('refuses to send anything without a CSRF token', async () => {
    clearXsrfCookie()
    fetchMock.mockRejectedValue(new Error('offline'))
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith(
      'Security token unavailable. Please refresh the page and try again.'
    )
    expect(fetchMock.mock.calls.every(([url]) => url === '/auth/v1/csrf-cookie')).toBe(true)
  })
})

describe('error handling', () => {
  it('reports the backend message and reason from a pre-stream failure', async () => {
    fetchMock.mockImplementation(
      async () =>
        new Response(JSON.stringify({ message: 'Out of AI credit', reason: 'quota' }), {
          status: 402,
        })
    )
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith('Out of AI credit', 'quota')
    expect(callbacks.onDelta).not.toHaveBeenCalled()
  })

  it('reports a CSRF mismatch response', async () => {
    fetchMock.mockImplementation(async () => new Response('', { status: 419 }))
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith(
      'CSRF token mismatch. Please refresh the page and try again.',
      'csrf'
    )
  })

  it('reports an OK response that carries no body', async () => {
    fetchMock.mockImplementation(async () => ({ ok: true, body: null }) as Response)
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith('No response body')
  })

  it('reports a transport failure', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith('Failed to fetch')
  })

  it('names a failure that carries no message', async () => {
    fetchMock.mockRejectedValue({ name: 'WeirdError' })
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith('Unknown error')
  })

  it('stays silent on abort', async () => {
    fetchMock.mockRejectedValue(Object.assign(new Error('aborted'), { name: 'AbortError' }))
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('reports a stream that ends without a completion event', async () => {
    fetchMock.mockImplementation(
      async () => new Response(body([frame({ type: 'delta', content: 'Hi' })]))
    )
    const callbacks = spies()

    await useAiStream().stream('/x', {}, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith('Stream ended without completion event')
  })

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a string', 'boom'],
  ])('reports a rejection of %s rather than throwing out of the catch', async (_label, value) => {
    fetchMock.mockRejectedValue(value)
    const callbacks = spies()

    // Callers `await stream()` without a catch, so a throw here would surface as
    // an unhandled rejection instead of an error message.
    await expect(useAiStream().stream('/x', {}, callbacks)).resolves.toBeUndefined()
    expect(callbacks.onError).toHaveBeenCalledWith('Unknown error')
  })
})

describe('isStreaming', () => {
  it('is false before anything starts', () => {
    expect(useAiStream().isStreaming.value).toBe(false)
  })

  it('is true while the body is being consumed', async () => {
    fetchMock.mockImplementation(
      async () =>
        new Response(
          body([frame({ type: 'delta', content: 'a' }), frame({ type: 'done', content: 'a' })])
        )
    )
    const { stream, isStreaming } = useAiStream()
    const seen: boolean[] = []

    await stream('/x', {}, { onDelta: () => seen.push(isStreaming.value) })

    expect(seen).toEqual([true])
    expect(isStreaming.value).toBe(false)
  })

  it('resets after a failed stream', async () => {
    fetchMock.mockRejectedValue(new Error('boom'))
    const { stream, isStreaming } = useAiStream()

    await stream('/x', {}, spies())

    expect(isStreaming.value).toBe(false)
  })

  it('stays false when the CSRF guard short-circuits the request', async () => {
    clearXsrfCookie()
    fetchMock.mockRejectedValue(new Error('offline'))
    const { stream, isStreaming } = useAiStream()

    await stream('/x', {}, spies())

    expect(isStreaming.value).toBe(false)
  })
})

describe('cancelStream', () => {
  it('aborts the in-flight request signal', async () => {
    fetchMock.mockImplementation(
      async () =>
        new Response(
          body([frame({ type: 'delta', content: 'a' }), frame({ type: 'done', content: 'a' })])
        )
    )
    const { stream, cancelStream, isStreaming } = useAiStream()

    await stream('/x', {}, {
      onDelta: () => {
        cancelStream()
      },
    })

    expect(streamRequest().init.signal?.aborted).toBe(true)
    expect(isStreaming.value).toBe(false)
  })

  it('does nothing when no stream is running', () => {
    const { cancelStream, isStreaming } = useAiStream()

    expect(() => cancelStream()).not.toThrow()
    expect(isStreaming.value).toBe(false)
  })

  it('is idempotent', async () => {
    fetchMock.mockImplementation(
      async () => new Response(body([frame({ type: 'done', content: 'a' })]))
    )
    const { stream, cancelStream } = useAiStream()

    await stream('/x', {}, spies())
    cancelStream()

    expect(() => cancelStream()).not.toThrow()
  })

  it('does not abort the next stream started after a cancel', async () => {
    fetchMock.mockImplementation(
      async () => new Response(body([frame({ type: 'done', content: 'a' })]))
    )
    const { stream, cancelStream } = useAiStream()

    cancelStream()
    await stream('/x', {}, spies())

    expect(streamRequest().init.signal?.aborted).toBe(false)
  })
})

describe('concurrent streams on one instance', () => {
  /**
   * A body the test finishes by hand, so a stream can be left in flight. The
   * signal errors it the way a real aborted fetch body would.
   */
  const pendingBody = (signal?: AbortSignal) => {
    let controller: ReadableStreamDefaultController<Uint8Array>
    const stream = new ReadableStream<Uint8Array>({
      start(c) {
        controller = c
        signal?.addEventListener('abort', () =>
          c.error(Object.assign(new Error('aborted'), { name: 'AbortError' }))
        )
      },
    })
    return {
      stream,
      finish: () => {
        controller.enqueue(encoder.encode(frame({ type: 'done', content: 'a' })))
        controller.close()
      },
    }
  }

  it('stays streaming while the first request is still open', async () => {
    const first = pendingBody()
    fetchMock
      .mockImplementationOnce(async () => new Response(first.stream))
      .mockImplementation(async () => new Response(body([frame({ type: 'done', content: 'b' })])))
    const { stream, isStreaming } = useAiStream()

    const pending = stream('/first', {}, spies())
    await stream('/second', {}, spies())

    expect(isStreaming.value).toBe(true)

    first.finish()
    await pending

    expect(isStreaming.value).toBe(false)
  })

  it('cancels every in-flight request, not just the last one', async () => {
    fetchMock.mockImplementation(
      async (_url: string, init: RequestInit) =>
        new Response(pendingBody(init.signal as AbortSignal).stream)
    )
    const { stream, cancelStream } = useAiStream()

    const pending = [stream('/first', {}, spies()), stream('/second', {}, spies())]
    // Both fetches must have been issued before the cancel reaches them.
    await Promise.resolve()
    await Promise.resolve()
    cancelStream()
    await Promise.all(pending)

    expect(fetchMock.mock.calls.map(([, init]) => init.signal.aborted)).toEqual([true, true])
  })
})

describe('instances', () => {
  it('gives each caller its own abort controller', async () => {
    fetchMock.mockImplementation(
      async () => new Response(body([frame({ type: 'done', content: 'a' })]))
    )
    const first = useAiStream()
    const second = useAiStream()

    await first.stream('/x', {}, spies())

    expect(second.isStreaming.value).toBe(false)
  })
})
