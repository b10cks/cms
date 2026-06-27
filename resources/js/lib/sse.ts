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
  if (!line.startsWith('data: ') || line.startsWith(':') || !line.trim()) {
    return null
  }

  try {
    return JSON.parse(line.slice(6)) as SseEvent
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

export async function consumeSseStream(
  reader: ReadableStreamDefaultReader<Uint8Array>,
  callbacks: SseCallbacks
): Promise<void> {
  const decoder = new TextDecoder()
  let buffer = ''
  let receivedDone = false

  while (true) {
    const { done, value } = await reader.read()

    if (done) {
      if (buffer.trim()) {
        for (const line of buffer.split('\n')) {
          const event = parseSseEvent(line)
          if (event) {
            const isDone = dispatchSseEvent(event, callbacks)
            if (isDone) receivedDone = true
          }
        }
      }
      break
    }

    buffer += decoder.decode(value, { stream: true })
    const lines = buffer.split('\n')
    buffer = lines.pop() ?? ''

    for (const line of lines) {
      const event = parseSseEvent(line)
      if (event) {
        const isDone = dispatchSseEvent(event, callbacks)
        if (isDone) receivedDone = true
      }
    }
  }

  if (!receivedDone) {
    callbacks.onError?.('Stream ended without completion event')
  }
}
