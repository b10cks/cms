import { toast } from 'vue-sonner'

import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, type SseCallbacks } from '~/lib/sse'

export interface TranslationPayload {
  source: string
  target: string
  fields: Record<string, string>
  config_id?: string | null
}

export function useAiTranslation(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const abortController = ref<AbortController | null>(null)

  const streamTranslation = async (
    payload: TranslationPayload,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.('No space ID provided')
      return
    }

    await ensureCsrfToken()

    abortController.value = new AbortController()

    const url = `/mgmt/v1/ai/translate/stream?spaceId=${id}`
    const xsrfHeaders = getXsrfHeaders()

    if (Object.keys(xsrfHeaders).length === 0) {
      callbacks.onError?.('CSRF token not available. Please refresh the page.')
      return
    }

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          ...xsrfHeaders,
        },
        body: JSON.stringify({
          source: payload.source,
          target: payload.target,
          fields: payload.fields,
          config_id: payload.config_id ?? null,
        }),
        signal: abortController.value.signal,
        credentials: 'include',
      })

      if (!response.ok) {
        const errorText = await response.text().catch(() => 'Unknown error')
        let errorMessage = `HTTP error! status: ${response.status}`

        try {
          const errorJson = JSON.parse(errorText)
          errorMessage = errorJson.message || errorMessage
        } catch {
          if (errorText) errorMessage = errorText
        }

        if (response.status === 419) {
          errorMessage = 'CSRF token mismatch. Please refresh the page and try again.'
        }

        throw new Error(errorMessage)
      }

      const reader = response.body?.getReader()
      if (!reader) throw new Error('No response body')

      await consumeSseStream(reader, callbacks)
    } catch (error: any) {
      if (error.name === 'AbortError') return

      const errorMessage = error.message || 'Unknown error'
      callbacks.onError?.(errorMessage)
      toast.error(t('composables.aiTranslation.error', { error: errorMessage }) as string)
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

  return {
    streamTranslation,
    cancelStream,
    isStreaming,
  }
}
