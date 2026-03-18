import { toast } from 'vue-sonner'

import type { ContentResource } from '~/types/contents'

import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, type SseCallbacks } from '~/lib/sse'

export interface TreeOperation {
  type: 'create' | 'move'
  id?: string
  name?: string
  slug?: string
  parent_id?: string | null
  block_id?: string
  temp_id?: string
  position?: number
}

export interface ContentTreePayload {
  prompt: string
  tree: ContentResource[]
  config_id: string | null
  mentions: Array<{
    type: string
    id: string
    label: string
  }>
}

export interface TreeOperationsResult {
  operations: TreeOperation[]
}

export function useAiContentTree(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const abortController = ref<AbortController | null>(null)

  const streamTreeInteraction = async (
    payload: ContentTreePayload,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.('No space ID provided')
      return
    }

    await ensureCsrfToken()

    abortController.value = new AbortController()

    const url = `/mgmt/v1/ai/content-tree-interaction/stream?spaceId=${id}`
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
        body: JSON.stringify(payload),
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
      toast.error(t('composables.aiContentTree.interactionError', { error: errorMessage }) as string)
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
    streamTreeInteraction,
    cancelStream,
    isStreaming,
  }
}

export function parseTreeOperations(jsonString: string): TreeOperationsResult | null {
  try {
    const parsed = JSON.parse(jsonString)
    if (parsed && Array.isArray(parsed.operations)) {
      return parsed as TreeOperationsResult
    }
    return null
  } catch {
    return null
  }
}
