import { describe, expect, it, vi } from 'vitest'

import type { SseCallbacks } from '~/lib/sse'

import {
  consumeSseStream,
  dispatchSseEvent,
  parseSseEvent,
  parseStreamErrorResponse,
} from '~/lib/sse'

const encoder = new TextEncoder()

/**
 * A reader over a fixed list of chunks. Strings are encoded as UTF-8, so a test
 * can also hand over raw byte slices to split a multi-byte character across a
 * chunk boundary.
 */
const readerOf = (
  chunks: Array<string | Uint8Array>
): ReadableStreamDefaultReader<Uint8Array> & {
  reads: () => number
  cancel: ReturnType<typeof vi.fn>
  releaseLock: ReturnType<typeof vi.fn>
} => {
  let index = 0

  return {
    read: async () => {
      if (index >= chunks.length) return { done: true, value: undefined }
      const chunk = chunks[index++]
      return { done: false, value: typeof chunk === 'string' ? encoder.encode(chunk) : chunk }
    },
    // The consumer releases the body on every exit path, so a stand-in reader
    // has to offer the same surface a real one does.
    cancel: vi.fn(async () => {}),
    releaseLock: vi.fn(),
    reads: () => index,
  } as unknown as ReadableStreamDefaultReader<Uint8Array> & {
    reads: () => number
    cancel: ReturnType<typeof vi.fn>
    releaseLock: ReturnType<typeof vi.fn>
  }
}

const frame = (event: Record<string, unknown>) => `data: ${JSON.stringify(event)}\n\n`

const spies = () => ({
  onStatus: vi.fn(),
  onDelta: vi.fn(),
  onDone: vi.fn(),
  onError: vi.fn(),
})

const collect = async (chunks: Array<string | Uint8Array>) => {
  const callbacks = spies()
  await consumeSseStream(readerOf(chunks), callbacks)
  return callbacks
}

const deltas = (callbacks: { onDelta: ReturnType<typeof vi.fn> }) =>
  callbacks.onDelta.mock.calls.map(([content]) => content).join('')

describe('parseStreamErrorResponse', () => {
  it('maps 419 to a CSRF prompt without reading the body', async () => {
    const text = vi.fn()
    const response = { status: 419, text } as unknown as Response

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'CSRF token mismatch. Please refresh the page and try again.',
      reason: 'csrf',
    })
    expect(text).not.toHaveBeenCalled()
  })

  it('uses the backend message and machine reason from a JSON body', async () => {
    const response = new Response(JSON.stringify({ message: 'Out of AI credit', reason: 'quota' }), {
      status: 402,
    })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'Out of AI credit',
      reason: 'quota',
    })
  })

  it('falls back to the status when the JSON body carries no message', async () => {
    const response = new Response(JSON.stringify({ reason: 'quota' }), { status: 403 })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'HTTP error! status: 403',
      reason: 'quota',
    })
  })

  it('ignores an empty message in the JSON body', async () => {
    const response = new Response(JSON.stringify({ message: '' }), { status: 422 })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'HTTP error! status: 422',
      reason: undefined,
    })
  })

  it('surfaces a non-JSON body verbatim', async () => {
    const response = new Response('<html>502 Bad Gateway</html>', { status: 502 })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: '<html>502 Bad Gateway</html>',
      reason: undefined,
    })
  })

  it('falls back to the status for an empty body', async () => {
    const response = new Response('', { status: 500 })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'HTTP error! status: 500',
      reason: undefined,
    })
  })

  it('falls back to the status when the body cannot be read', async () => {
    const response = {
      status: 500,
      text: () => Promise.reject(new Error('network gone')),
    } as unknown as Response

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'HTTP error! status: 500',
      reason: undefined,
    })
  })

  it('treats a bare JSON literal body as text', async () => {
    // `JSON.parse('null')` succeeds, then reading `.message` off null throws
    // inside the same try — so the raw text wins. Pins ACTUAL behaviour.
    const response = new Response('null', { status: 500 })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'null',
      reason: undefined,
    })
  })

  it('falls back to the status for a numeric JSON body', async () => {
    const response = new Response('42', { status: 500 })

    expect(await parseStreamErrorResponse(response)).toEqual({
      message: 'HTTP error! status: 500',
      reason: undefined,
    })
  })
})

describe('parseSseEvent', () => {
  it('parses a data line', () => {
    expect(parseSseEvent('data: {"type":"delta","content":"Hi"}')).toEqual({
      type: 'delta',
      content: 'Hi',
    })
  })

  it('tolerates the trailing carriage return of a CRLF stream', () => {
    expect(parseSseEvent('data: {"type":"status","message":"Thinking"}\r')).toEqual({
      type: 'status',
      message: 'Thinking',
    })
  })

  it('keeps newlines that were escaped inside the JSON', () => {
    expect(parseSseEvent('data: {"type":"delta","content":"a\\nb"}')).toEqual({
      type: 'delta',
      content: 'a\nb',
    })
  })

  it.each([
    ['an empty line', ''],
    ['whitespace', '   '],
    ['a comment/keepalive line', ': keepalive'],
    ['an event name line', 'event: message'],
    ['a retry line', 'retry: 3000'],
    ['a data line with no space after the colon', 'data:{"type":"delta"}'],
    ['a leading-space data line', ' data: {"type":"delta"}'],
    ['the OpenAI-style terminator', 'data: [DONE]'],
    ['a plain-text payload', 'data: thinking…'],
    ['truncated JSON', 'data: {"type":"delta","content":"H'],
    ['a lone brace', 'data: {'],
  ])('returns null for %s', (_label, line) => {
    expect(parseSseEvent(line)).toBeNull()
  })

  it.each([
    ['a JSON array', 'data: [1,2]'],
    ['a JSON scalar', 'data: 7'],
    ['a JSON null', 'data: null'],
    ['an object with no type', 'data: {"content":"Hi"}'],
    ['an object with a non-string type', 'data: {"type":3}'],
  ])('returns null for %s, which is not an SseEvent', (_label, line) => {
    expect(parseSseEvent(line)).toBeNull()
  })
})

describe('dispatchSseEvent', () => {
  it('routes a status event and keeps the stream open', () => {
    const callbacks = spies()

    expect(dispatchSseEvent({ type: 'status', message: 'Thinking' }, callbacks)).toBe(false)
    expect(callbacks.onStatus).toHaveBeenCalledWith('Thinking')
  })

  it('routes a delta event and keeps the stream open', () => {
    const callbacks = spies()

    expect(dispatchSseEvent({ type: 'delta', content: 'Hi' }, callbacks)).toBe(false)
    expect(callbacks.onDelta).toHaveBeenCalledWith('Hi')
  })

  it('routes a done event with its data and closes the stream', () => {
    const callbacks = spies()

    expect(
      dispatchSseEvent({ type: 'done', content: 'Full', data: { usage: 1 } }, callbacks)
    ).toBe(true)
    expect(callbacks.onDone).toHaveBeenCalledWith('Full', { usage: 1 })
  })

  it('routes an error event with its reason and closes the stream', () => {
    const callbacks = spies()

    expect(dispatchSseEvent({ type: 'error', message: 'Nope', reason: 'quota' }, callbacks)).toBe(
      true
    )
    expect(callbacks.onError).toHaveBeenCalledWith('Nope', 'quota')
  })

  it('substitutes empty strings for missing status and delta bodies', () => {
    const callbacks = spies()

    dispatchSseEvent({ type: 'status' }, callbacks)
    dispatchSseEvent({ type: 'delta' }, callbacks)

    expect(callbacks.onStatus).toHaveBeenCalledWith('')
    expect(callbacks.onDelta).toHaveBeenCalledWith('')
  })

  it('names an unspecified error', () => {
    const callbacks = spies()

    dispatchSseEvent({ type: 'error' }, callbacks)

    expect(callbacks.onError).toHaveBeenCalledWith('Unknown error', undefined)
  })

  it.each(['[DONE]', 'DONE', 'complete', ''])('ignores the unknown type %s', (type) => {
    const callbacks = spies()

    expect(dispatchSseEvent({ type }, callbacks)).toBe(false)
    expect(callbacks.onStatus).not.toHaveBeenCalled()
    expect(callbacks.onDelta).not.toHaveBeenCalled()
    expect(callbacks.onDone).not.toHaveBeenCalled()
    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('does not throw when no callback is registered', () => {
    expect(() => dispatchSseEvent({ type: 'done', content: 'x' }, {} as SseCallbacks)).not.toThrow()
    expect(() => dispatchSseEvent({ type: 'error' }, {} as SseCallbacks)).not.toThrow()
  })
})

describe('consumeSseStream', () => {
  it('dispatches every event in a single chunk in order', async () => {
    const callbacks = await collect([
      frame({ type: 'status', message: 'Thinking' }) +
        frame({ type: 'delta', content: 'He' }) +
        frame({ type: 'delta', content: 'llo' }) +
        frame({ type: 'done', content: 'Hello' }),
    ])

    expect(callbacks.onStatus).toHaveBeenCalledWith('Thinking')
    expect(deltas(callbacks)).toBe('Hello')
    expect(callbacks.onDone).toHaveBeenCalledWith('Hello', undefined)
    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('reassembles an event split across chunk boundaries', async () => {
    const callbacks = await collect([
      'data: {"type":"del',
      'ta","content":"Hel',
      'lo"}\n',
      frame({ type: 'done', content: 'Hello' }),
    ])

    expect(deltas(callbacks)).toBe('Hello')
    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('reassembles an event split immediately after the newline', async () => {
    const callbacks = await collect([
      `${frame({ type: 'delta', content: 'a' })}data: `,
      `{"type":"delta","content":"b"}\n${frame({ type: 'done', content: 'ab' })}`,
    ])

    expect(deltas(callbacks)).toBe('ab')
  })

  it('reassembles a multi-byte character split across chunk boundaries', async () => {
    const payload = encoder.encode('data: {"type":"delta","content":"café — ok"}\n')
    // Split between the two bytes of `é`, which only `{ stream: true }` survives.
    const split = payload.indexOf(0xc3) + 1
    expect(payload[split]).toBe(0xa9)
    const callbacks = await collect([
      payload.slice(0, split),
      payload.slice(split),
      frame({ type: 'done', content: '' }),
    ])

    expect(deltas(callbacks)).toBe('café — ok')
  })

  it('flushes a final event that arrives without a trailing newline', async () => {
    const callbacks = await collect(['data: {"type":"done","content":"Hello"}'])

    expect(callbacks.onDone).toHaveBeenCalledWith('Hello', undefined)
    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('flushes several events left in the buffer at stream end', async () => {
    const callbacks = await collect([
      `data: {"type":"delta","content":"a"}\ndata: {"type":"done","content":"a"}`,
    ])

    expect(deltas(callbacks)).toBe('a')
    expect(callbacks.onDone).toHaveBeenCalledWith('a', undefined)
  })

  it('reports a stream that ends without a completion event', async () => {
    const callbacks = await collect([frame({ type: 'delta', content: 'Hi' })])

    expect(deltas(callbacks)).toBe('Hi')
    expect(callbacks.onError).toHaveBeenCalledWith('Stream ended without completion event')
  })

  it('reports an empty stream', async () => {
    const callbacks = await collect([])

    expect(callbacks.onError).toHaveBeenCalledWith('Stream ended without completion event')
  })

  it('reports a stream that only ever sends keepalives', async () => {
    const callbacks = await collect([': keepalive\n\n', ': keepalive\n\n'])

    expect(callbacks.onError).toHaveBeenCalledTimes(1)
  })

  it('treats an error event as completion, so no second error is raised', async () => {
    const callbacks = await collect([frame({ type: 'error', message: 'Nope', reason: 'quota' })])

    expect(callbacks.onError).toHaveBeenCalledTimes(1)
    expect(callbacks.onError).toHaveBeenCalledWith('Nope', 'quota')
  })

  it('does not treat the [DONE] terminator as completion', async () => {
    // Pins ACTUAL behaviour: only a JSON frame of type `done`/`error` completes
    // the stream, so a bare `data: [DONE]` still ends in an error callback.
    const callbacks = await collect([
      frame({ type: 'delta', content: 'Hi' }),
      'data: [DONE]\n\n',
    ])

    expect(callbacks.onError).toHaveBeenCalledWith('Stream ended without completion event')
  })

  it('skips malformed frames and keeps consuming', async () => {
    const callbacks = await collect([
      'data: {"type":"delta","content":"a"}\n',
      'data: not json\n',
      'data: {oops}\n',
      'garbage without a prefix\n',
      '\n',
      'data: {"type":"delta","content":"b"}\n',
      frame({ type: 'done', content: 'ab' }),
    ])

    expect(deltas(callbacks)).toBe('ab')
    expect(callbacks.onDone).toHaveBeenCalledWith('ab', undefined)
    expect(callbacks.onError).not.toHaveBeenCalled()
  })

  it('stops consuming after the done event', async () => {
    const reader = readerOf([
      frame({ type: 'done', content: 'Hello' }),
      frame({ type: 'delta', content: 'trailing' }),
    ])
    const callbacks = spies()

    await consumeSseStream(reader, callbacks)

    expect(callbacks.onDone).toHaveBeenCalledTimes(1)
    expect(callbacks.onDelta).not.toHaveBeenCalled()
    expect(reader.reads()).toBe(1)
  })

  it('ignores events that follow the done event in the same chunk', async () => {
    const callbacks = await collect([
      frame({ type: 'done', content: 'Hello' }) + frame({ type: 'delta', content: 'trailing' }),
    ])

    expect(callbacks.onDelta).not.toHaveBeenCalled()
  })

  it('dispatches only the first done event when the server sends more than one', async () => {
    // A second onDone would make the tree wizard apply its operations twice.
    const callbacks = await collect([
      frame({ type: 'done', content: 'first' }),
      frame({ type: 'done', content: 'second' }),
    ])

    expect(callbacks.onDone).toHaveBeenCalledTimes(1)
    expect(callbacks.onDone).toHaveBeenCalledWith('first', undefined)
  })

  it('stops consuming after an error event', async () => {
    const callbacks = await collect([
      frame({ type: 'error', message: 'Nope' }),
      frame({ type: 'delta', content: 'trailing' }),
    ])

    expect(callbacks.onError).toHaveBeenCalledTimes(1)
    expect(callbacks.onDelta).not.toHaveBeenCalled()
  })

  it('releases the body once the stream completes', async () => {
    const reader = readerOf([frame({ type: 'done', content: 'Hi' })])

    await consumeSseStream(reader, spies())

    expect(reader.cancel).toHaveBeenCalled()
    expect(reader.releaseLock).toHaveBeenCalled()
  })

  it('forwards the done payload data', async () => {
    const callbacks = await collect([
      frame({ type: 'done', content: 'Hi', data: { operations: [{ type: 'create' }] } }),
    ])

    expect(callbacks.onDone).toHaveBeenCalledWith('Hi', { operations: [{ type: 'create' }] })
  })

  it('works with only the callbacks the caller supplied', async () => {
    const onDelta = vi.fn()

    await consumeSseStream(
      readerOf([frame({ type: 'status', message: 'x' }), frame({ type: 'delta', content: 'a' })]),
      { onDelta }
    )

    expect(onDelta).toHaveBeenCalledWith('a')
  })

  it('propagates a read failure to the caller', async () => {
    const reader = {
      read: () => Promise.reject(new Error('network gone')),
      cancel: vi.fn(async () => {}),
      releaseLock: vi.fn(),
    } as unknown as ReadableStreamDefaultReader<Uint8Array>

    // No try/catch here: the transport (`useAiStream`) owns error reporting.
    await expect(consumeSseStream(reader, spies())).rejects.toThrow('network gone')
  })

  it('releases the body when a read throws, and still rethrows', async () => {
    const cancel = vi.fn(async () => {})
    const releaseLock = vi.fn()
    const reader = {
      read: () => Promise.reject(Object.assign(new Error('aborted'), { name: 'AbortError' })),
      cancel,
      releaseLock,
    } as unknown as ReadableStreamDefaultReader<Uint8Array>

    await expect(consumeSseStream(reader, spies())).rejects.toThrow('aborted')
    expect(cancel).toHaveBeenCalled()
    expect(releaseLock).toHaveBeenCalled()
  })

  it('survives a cancel that rejects on an already errored stream', async () => {
    const reader = {
      read: () => Promise.reject(new Error('network gone')),
      cancel: () => Promise.reject(new Error('already errored')),
      releaseLock: vi.fn(),
    } as unknown as ReadableStreamDefaultReader<Uint8Array>

    await expect(consumeSseStream(reader, spies())).rejects.toThrow('network gone')
  })

  it('drives a real ReadableStream end to end', async () => {
    const stream = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(encoder.encode('data: {"type":"delta","content":"a'))
        controller.enqueue(encoder.encode('b"}\n'))
        controller.enqueue(encoder.encode(frame({ type: 'done', content: 'ab' })))
        controller.close()
      },
    })
    const callbacks = spies()

    await consumeSseStream(stream.getReader(), callbacks)

    expect(deltas(callbacks)).toBe('ab')
    expect(callbacks.onDone).toHaveBeenCalledWith('ab', undefined)
  })
})
