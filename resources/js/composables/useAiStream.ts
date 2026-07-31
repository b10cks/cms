import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, parseStreamErrorResponse, type SseCallbacks } from '~/lib/sse'

/** A rejection is not necessarily an Error — it can be anything, including null. */
const errorField = (error: unknown, field: 'name' | 'message'): string | undefined => {
  if (typeof error !== 'object' || error === null) return undefined
  const value = (error as Record<string, unknown>)[field]
  return typeof value === 'string' ? value : undefined
}

/**
 * Shared transport for the AI streaming endpoints. Owns the abort controller, CSRF
 * handshake, fetch with the SSE headers, pre-stream error parsing and the reader
 * loop — everything that was previously copy-pasted across every `useAi*` composable.
 *
 * Feature composables build their own URL and payload (and run any feature-specific
 * guards) before delegating to `stream`.
 */
export function useAiStream() {
  const { t } = useI18n()
  // A set, not a single controller: two concurrent `stream()` calls on one
  // instance would otherwise overwrite each other's controller, so one could no
  // longer be cancelled and the other would report itself as finished mid-flight.
  const abortControllers = ref(new Set<AbortController>())

  const stream = async (
    url: string,
    payload: unknown,
    callbacks: SseCallbacks
  ): Promise<void> => {
    await ensureCsrfToken()

    const xsrfHeaders = getXsrfHeaders()
    if (Object.keys(xsrfHeaders).length === 0) {
      callbacks.onError?.(t('composables.ai.errors.csrfUnavailable') as string)
      return
    }

    const controller = new AbortController()
    abortControllers.value.add(controller)

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          ...xsrfHeaders,
        },
        body: JSON.stringify(payload),
        signal: controller.signal,
        credentials: 'include',
      })

      if (!response.ok) {
        const { message, reason } = await parseStreamErrorResponse(response)
        callbacks.onError?.(message, reason)
        return
      }

      const reader = response.body?.getReader()
      if (!reader) throw new Error('No response body')

      await consumeSseStream(reader, callbacks)
    } catch (error: unknown) {
      if (errorField(error, 'name') === 'AbortError') return

      callbacks.onError?.(errorField(error, 'message') || 'Unknown error')
    } finally {
      abortControllers.value.delete(controller)
    }
  }

  const cancelStream = () => {
    for (const controller of abortControllers.value) {
      controller.abort()
    }
    abortControllers.value.clear()
  }

  const isStreaming = computed(() => abortControllers.value.size > 0)

  return { stream, cancelStream, isStreaming }
}
