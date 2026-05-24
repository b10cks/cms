import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, type SseCallbacks } from '~/lib/sse'

export function useDataEntryTranslation(spaceId: MaybeRef<string>, dataSourceId: MaybeRef<string>) {
  const abortController = ref<AbortController | null>(null)

  const streamMissingDimensionsTranslation = async (
    targetDimension: string,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const currentSpaceId = toValue(spaceId)
    const currentDataSourceId = toValue(dataSourceId)

    if (!currentSpaceId || !currentDataSourceId) {
      callbacks.onError?.('Missing space or data source ID')
      return
    }

    await ensureCsrfToken()

    abortController.value = new AbortController()

    const xsrfHeaders = getXsrfHeaders()

    if (Object.keys(xsrfHeaders).length === 0) {
      callbacks.onError?.('CSRF token not available. Please refresh the page.')
      return
    }

    const url = `/mgmt/v1/spaces/${currentSpaceId}/data-sources/${currentDataSourceId}/entries/translate-missing-dimensions/stream`

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          ...xsrfHeaders,
        },
        body: JSON.stringify({
          target_dimension: targetDimension,
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

  return {
    streamMissingDimensionsTranslation,
    cancelStream,
    isStreaming,
  }
}
