import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, parseStreamErrorResponse, type SseCallbacks } from '~/lib/sse'

export interface AiContentTreeNode {
  id: string
  backend_id?: string | null
  parent_id: string | null
  name: string
  slug: string
  block_id: string
  block_name?: string
  block_type?: string
  deleted_reason?: string | null
  is_deleted?: boolean
}

export interface CreateTreeOperation {
  type: 'create'
  id?: string
  name?: string
  slug?: string
  parent_id?: string | null
  block_id?: string
  temp_id?: string
  position?: number
}

export interface MoveTreeOperation {
  type: 'move'
  id?: string
  parent_id?: string | null
  position?: number
}

export interface UpdateTreeOperation {
  type: 'update' | 'rename'
  id?: string
  name?: string
  slug?: string
  block_id?: string
}

export interface DeleteTreeOperation {
  type: 'delete' | 'remove'
  id?: string
}

export interface RestoreTreeOperation {
  type: 'restore'
  id?: string
}

export type TreeOperation =
  | CreateTreeOperation
  | MoveTreeOperation
  | UpdateTreeOperation
  | DeleteTreeOperation
  | RestoreTreeOperation

export interface ContentTreePayload {
  prompt: string
  tree: AiContentTreeNode[]
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

const TREE_OPERATION_TYPES = new Set([
  'create',
  'move',
  'update',
  'rename',
  'delete',
  'remove',
  'restore',
])

function stripCodeFences(content: string): string {
  return content
    .replace(/^```(?:json|javascript|js)?\s*\n?/i, '')
    .replace(/\n?```\s*$/i, '')
    .trim()
}

function isTreeOperation(value: unknown): value is TreeOperation {
  if (!value || typeof value !== 'object') {
    return false
  }

  const type = (value as { type?: unknown }).type
  return typeof type === 'string' && TREE_OPERATION_TYPES.has(type)
}

export function useAiContentTree(spaceId: MaybeRef<string>) {
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

  return {
    streamTreeInteraction,
    cancelStream,
    isStreaming,
  }
}

export function parseTreeOperations(jsonString: string): TreeOperationsResult | null {
  try {
    const parsed = JSON.parse(stripCodeFences(jsonString))
    if (parsed && Array.isArray(parsed.operations)) {
      return {
        operations: parsed.operations.filter(isTreeOperation),
      }
    }
    return null
  } catch {
    return null
  }
}

export function extractStreamingTreeOperations(partial: string): TreeOperation[] {
  const match = stripCodeFences(partial).match(/"operations"\s*:\s*\[([\s\S]*)$/)
  if (!match) {
    return []
  }

  const arrayContent = match[1]
  const operations: TreeOperation[] = []
  let depth = 0
  let start = -1
  let inString = false
  let isEscaped = false

  for (let index = 0; index < arrayContent.length; index++) {
    const char = arrayContent[index]

    if (inString) {
      if (isEscaped) {
        isEscaped = false
        continue
      }

      if (char === '\\') {
        isEscaped = true
        continue
      }

      if (char === '"') {
        inString = false
      }

      continue
    }

    if (char === '"') {
      inString = true
      continue
    }

    if (char === '{') {
      if (depth === 0) {
        start = index
      }
      depth++
      continue
    }

    if (char === '}') {
      depth--
      if (depth === 0 && start !== -1) {
        try {
          const parsed = JSON.parse(arrayContent.slice(start, index + 1))
          if (isTreeOperation(parsed)) {
            operations.push(parsed)
          }
        } catch {
          // Ignore incomplete or invalid partial objects until more stream data arrives.
        }
        start = -1
      }
    }
  }

  return operations
}
