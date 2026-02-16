export interface SseEvent {
  type: string
  message?: string
  content?: string
  data?: Record<string, unknown>
}

export interface SseCallbacks {
  onStatus?: (message: string) => void
  onDelta?: (content: string) => void
  onDone?: (content: string, data?: Record<string, unknown>) => void
  onError?: (message: string) => void
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
      callbacks.onError?.(event.message ?? 'Unknown error')
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
