import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, parseStreamErrorResponse, type SseCallbacks } from '~/lib/sse'

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
  const abortController = ref<AbortController | null>(null)

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

    abortController.value = new AbortController()

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          ...xsrfHeaders,
        },
        body: JSON.stringify(payload),
        signal: abortController.value.signal,
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
    } catch (error: any) {
      if (error.name === 'AbortError') return

      callbacks.onError?.(error.message || 'Unknown error')
    } finally {
      abortController.value = null
    }
  }

  const cancelStream = () => {
    if (abortController.value) {
      abortController.value.abort()
      abortController.value = null
    }
  }

  const isStreaming = computed(() => abortController.value !== null)

  return { stream, cancelStream, isStreaming }
}
