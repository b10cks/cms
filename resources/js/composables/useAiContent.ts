import { toast } from 'vue-sonner'

import type { ContentInteractionPayload } from '~/api/resources/ai'

import { getXsrfHeaders, hasXsrfToken } from '~/lib/csrf'

export interface AiStreamEvent {
  type: 'status' | 'delta' | 'done' | 'error'
  message?: string
  content?: string
  data?: Record<string, unknown>
}

export interface StreamCallbacks {
  onStatus?: (message: string) => void
  onDelta?: (content: string) => void
  onDone?: (content: string, data?: Record<string, unknown>) => void
  onError?: (message: string) => void
}

async function fetchCsrfCookie(): Promise<boolean> {
  if (typeof document === 'undefined') return false

  try {
    const response = await fetch('/auth/v1/csrf-cookie', {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
      },
    })

    if (!response.ok) {
      return false
    }

    return true
  } catch (error) {
    return false
  }
}

async function ensureCsrfToken(): Promise<boolean> {
  if (hasXsrfToken()) {
    return true
  }

  const success = await fetchCsrfCookie()
  if (!success) {
    return false
  }

  await new Promise((resolve) => setTimeout(resolve, 100))

  return hasXsrfToken()
}

export function useAiContent(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const abortController = ref<AbortController | null>(null)

  const streamContentInteraction = async (
    payload: ContentInteractionPayload,
    callbacks: StreamCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.('No space ID provided')
      return
    }

    await ensureCsrfToken()

    abortController.value = new AbortController()

    const url = `/mgmt/v1/ai/content-interaction/stream?spaceId=${id}`

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
          if (errorText) {
            errorMessage = errorText
          }
        }

        if (response.status === 419) {
          errorMessage = 'CSRF token mismatch. Please refresh the page and try again.'
        }

        throw new Error(errorMessage)
      }

      const reader = response.body?.getReader()
      if (!reader) {
        throw new Error('No response body')
      }

      const decoder = new TextDecoder()
      let buffer = ''
      let receivedDone = false

      while (true) {
        const { done, value } = await reader.read()

        if (done) {
          if (buffer.trim()) {
            const lines = buffer.split('\n')
            for (const line of lines) {
              if (line.startsWith(':') || !line.trim()) {
                continue
              }

              if (line.startsWith('data: ')) {
                try {
                  const event = JSON.parse(line.slice(6)) as AiStreamEvent
                  switch (event.type) {
                    case 'status':
                      callbacks.onStatus?.(event.message ?? '')
                      break
                    case 'delta':
                      callbacks.onDelta?.(event.content ?? '')
                      break
                    case 'done':
                      receivedDone = true
                      callbacks.onDone?.(event.content ?? '', event.data)
                      break
                    case 'error':
                      callbacks.onError?.(event.message ?? 'Unknown error')
                      break
                  }
                } catch (e) {
                  console.error('[AI Stream] Failed to parse final SSE event:', line, e)
                }
              }
            }
          }
          if (!receivedDone) {
            console.warn('[AI Stream] Stream ended without done event')
          }
          break
        }

        buffer += decoder.decode(value, { stream: true })
        const lines = buffer.split('\n')
        buffer = lines.pop() ?? ''

        for (const line of lines) {
          if (line.startsWith(':')) {
            continue
          }

          if (line.startsWith('data: ')) {
            try {
              const event = JSON.parse(line.slice(6)) as AiStreamEvent
              switch (event.type) {
                case 'status':
                  callbacks.onStatus?.(event.message ?? '')
                  break
                case 'delta':
                  callbacks.onDelta?.(event.content ?? '')
                  break
                case 'done':
                  receivedDone = true
                  console.log('[AI Stream] Done event received, content length:', event.content)
                  callbacks.onDone?.(event.content ?? '', event.data)
                  break
                case 'error':
                  callbacks.onError?.(event.message ?? 'Unknown error')
                  break
              }
            } catch (e) {
              console.error('[AI Stream] Failed to parse SSE event:', line, e)
            }
          }
        }
      }
    } catch (error: any) {
      if (error.name === 'AbortError') {
        return
      }

      const errorMessage = error.message || 'Unknown error'
      callbacks.onError?.(errorMessage)
      toast.error(
        t('composables.ai.interactionError', {
          error: errorMessage,
        }) as string
      )
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
    streamContentInteraction,
    cancelStream,
    isStreaming,
  }
}
