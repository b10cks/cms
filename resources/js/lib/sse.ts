export interface SseEvent {
  type: string
  message?: string
  content?: string
  reason?: string
  data?: Record<string, unknown>
}

export interface SseCallbacks {
  onStatus?: (message: string) => void
  onDelta?: (content: string) => void
  onDone?: (content: string, data?: Record<string, unknown>) => void
  onError?: (message: string, reason?: string) => void
}

/**
 * Turn a non-OK fetch Response from a streaming endpoint into a user-facing
 * message plus the backend's machine `reason`, so callers can branch on it
 * (e.g. show an upgrade prompt). Handles the pre-stream failures — auth (403),
 * validation (422), CSRF (419) — that arrive as a normal JSON body before the
 * event stream begins.
 */
export async function parseStreamErrorResponse(
  response: Response
): Promise<{ message: string; reason?: string }> {
  if (response.status === 419) {
    return {
      message: 'CSRF token mismatch. Please refresh the page and try again.',
      reason: 'csrf',
    }
  }

  const errorText = await response.text().catch(() => '')
  let message = `HTTP error! status: ${response.status}`
  let reason: string | undefined

  try {
    const errorJson = JSON.parse(errorText)
    message = errorJson.message || message
    reason = errorJson.reason
  } catch {
    if (errorText) message = errorText
  }

  return { message, reason }
}

export function parseSseEvent(line: string): SseEvent | null {
  if (!line.startsWith('data: ')) {
    return null
  }

  try {
    const parsed: unknown = JSON.parse(line.slice(6))
    // Only an object with a string `type` is an event; a bare array or scalar
    // parses as JSON but is not something `dispatchSseEvent` could ever route.
    if (!parsed || typeof parsed !== 'object' || typeof (parsed as SseEvent).type !== 'string') {
      return null
    }
    return parsed as SseEvent
  } catch {
    return null
  }
}

export function dispatchSseEvent(event: SseEvent, callbacks: SseCallbacks): boolean {
  switch (event.type) {
    case 'status':
      callbacks.onStatus?.(event.message ?? '')
      return false
    case 'delta':
      callbacks.onDelta?.(event.content ?? '')
      return false
    case 'done':
      callbacks.onDone?.(event.content ?? '', event.data)
      return true
    case 'error':
      callbacks.onError?.(event.message ?? 'Unknown error', event.reason)
      return true
    default:
      return false
  }
}

/** Dispatches lines in order, stopping at the first terminal (`done`/`error`) event. */
function dispatchSseLines(lines: string[], callbacks: SseCallbacks): boolean {
  for (const line of lines) {
    const event = parseSseEvent(line)
    if (event && dispatchSseEvent(event, callbacks)) {
      return true
    }
  }

  return false
}

export async function consumeSseStream(
  reader: ReadableStreamDefaultReader<Uint8Array>,
  callbacks: SseCallbacks
): Promise<void> {
  const decoder = new TextDecoder()
  let buffer = ''
  let receivedDone = false

  try {
    // A terminal event ends the stream: anything the server writes after it is
    // dropped, so a second `done` cannot fire `onDone` (and re-apply its result)
    // twice.
    while (!receivedDone) {
      const { done, value } = await reader.read()

      if (done) {
        if (buffer.trim()) {
          receivedDone = dispatchSseLines(buffer.split('\n'), callbacks)
        }
        break
      }

      buffer += decoder.decode(value, { stream: true })
      const lines = buffer.split('\n')
      buffer = lines.pop() ?? ''

      receivedDone = dispatchSseLines(lines, callbacks)
    }
  } finally {
    // Release the body on every exit path — completion, abort or a failed read —
    // otherwise the stream stays locked until it is garbage collected.
    void reader.cancel().catch(() => {})
    reader.releaseLock()
  }

  if (!receivedDone) {
    callbacks.onError?.('Stream ended without completion event')
  }
}
