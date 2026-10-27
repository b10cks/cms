import { balancedSpans, parseAiJson, stripAiCodeFences } from '~/lib/aiJson'

/**
 * Tree operations the content-tree skill returns, plus the tolerant parsers
 * the canvas uses for its streaming preview and the final answer.
 */

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

/** The fields that make a `create` mean something; every other type carries an `id`. */
const CREATE_FIELDS = ['name', 'slug', 'block_id', 'temp_id', 'parent_id'] as const

function isTreeOperation(value: unknown): value is TreeOperation {
  if (!value || typeof value !== 'object') {
    return false
  }

  const record = value as Record<string, unknown>
  if (typeof record.type !== 'string' || !TREE_OPERATION_TYPES.has(record.type)) {
    return false
  }

  // `create` is the one shape with no required key, so a bare `{type: 'create'}`
  // — a stray object the model emitted mid-thought — would otherwise reach the
  // applier as a real operation.
  if (record.type === 'create') {
    return CREATE_FIELDS.some((field) => record[field] !== undefined)
  }

  return true
}

export function parseTreeOperations(jsonString: string): TreeOperationsResult | null {
  // `parseAiJson` falls back to balanced extraction, so a document the model
  // wrapped in prose parses here just as it does in the streaming path.
  const parsed = parseAiJson<{ operations?: unknown }>(jsonString)
  if (!parsed || !Array.isArray(parsed.operations)) {
    return null
  }

  return {
    operations: parsed.operations.filter(isTreeOperation),
  }
}

export function extractStreamingTreeOperations(partial: string): TreeOperation[] {
  const text = stripAiCodeFences(partial)
  const match = text.match(/"operations"\s*:\s*\[/)
  if (match?.index === undefined) {
    return []
  }

  // From the array's own `[`, and only as far as its matching `]` — otherwise
  // the scan runs on into the sibling keys that follow the closed array and
  // reports any operation-shaped object among them as an operation.
  const rest = text.slice(match.index + match[0].length - 1)
  const array = balancedSpans(rest, '[', ']').next().value
  const arrayContent = array ? rest.slice(1, array.end) : rest.slice(1)
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
