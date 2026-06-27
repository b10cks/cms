import { balancedSpans, stripAiCodeFences } from '~/lib/aiJson'
import type { SseCallbacks } from '~/lib/sse'

import { useAiStream } from './useAiStream'

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

function isTreeOperation(value: unknown): value is TreeOperation {
  if (!value || typeof value !== 'object') {
    return false
  }

  const type = (value as { type?: unknown }).type
  return typeof type === 'string' && TREE_OPERATION_TYPES.has(type)
}

export function useAiContentTree(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const { stream, cancelStream, isStreaming } = useAiStream()

  const streamTreeInteraction = async (
    payload: ContentTreePayload,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.(t('composables.ai.errors.noSpace') as string)
      return
    }

    await stream(`/mgmt/v1/ai/content-tree-interaction/stream?spaceId=${id}`, payload, callbacks)
  }

  return {
    streamTreeInteraction,
    cancelStream,
    isStreaming,
  }
}

export function parseTreeOperations(jsonString: string): TreeOperationsResult | null {
  try {
    const parsed = JSON.parse(stripAiCodeFences(jsonString))
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
  const match = stripAiCodeFences(partial).match(/"operations"\s*:\s*\[([\s\S]*)$/)
  if (!match) {
    return []
  }

  const arrayContent = match[1]
  const operations: TreeOperation[] = []

  for (const { start, end } of balancedSpans(arrayContent, '{', '}')) {
    try {
      const parsed = JSON.parse(arrayContent.slice(start, end + 1))
      if (isTreeOperation(parsed)) {
        operations.push(parsed)
      }
    } catch {
      // Ignore incomplete or invalid partial objects until more stream data arrives.
    }
  }

  return operations
}
