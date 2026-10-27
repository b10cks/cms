/**
 * Types and helpers for AI changesets: field-level proposals pinned to the
 * version a run read, reviewed per field, entry or changeset.
 */
import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'
import type { AiGlossaryFinding, AiRunRecord } from '~/lib/aiRun'

export type AiTargetType = 'content' | 'data_entry' | 'asset'

export type AiChangesetStatus = 'open' | 'partially_applied' | 'applied' | 'discarded'

export type AiChangesetItemStatus = 'pending' | 'accepted' | 'rejected' | 'conflict'

export interface AiChangesetItem {
  id: string
  operation: 'update' | 'create'
  target_type: AiTargetType | 'selection'
  target_id: string | null
  target_label: string | null
  base_version_id: string | null
  path: string | null
  field_type: string | null
  before: unknown
  after: unknown
  /** What the reviewer accepted when they edited the proposal first */
  final: unknown
  current: unknown
  status: AiChangesetItemStatus
  glossary_findings: AiGlossaryFinding[]
  decided_at: string | null
}

export interface AiChangeset {
  id: string
  title: string
  status: AiChangesetStatus
  inline: boolean
  item_count: number
  pending_count: number
  run?: {
    id: string
    state: AiRunRecord['state']
    partial: boolean
    stopped_by: string | null
    cost_usd: number
  } | null
  actor?: { id: string; name: string }
  items?: AiChangesetItem[]
  created_at: string
}

/** A glossary term the learning loop drew from an editor's correction of AI output. */
export interface AiGlossarySuggestion {
  id: string
  source_locale: string
  term: string
  translations: Record<string, string>
  forbidden: string[]
  note: string | null
  evidence: { ai: string; final: string; label: string | null } | null
  actor: { id: string; name: string } | null
  created_at: string
}

export interface AiInbox {
  changesets: AiChangeset[]
  runs: AiRunRecord[]
  /** Only for users who manage the space's AI */
  suggestions: AiGlossarySuggestion[]
  /** Glossaries the user may accept suggestions into */
  glossaries: AiGlossaryResource[]
  count: number
}

export interface AiDecision {
  item_ids?: string[]
  overwrite?: boolean
  /** Reviewer edits of text proposals, item ID => text to apply instead */
  values?: Record<string, string>
}

export interface AiDecisionResult {
  data: AiChangeset
  accepted?: number
  conflicts?: number
  rejected?: number
}

export interface AiEntryScope {
  type: AiTargetType
  ids?: string[]
  filters?: Record<string, string>
}

export interface AiPreflight {
  type: AiTargetType
  count: number
  ids: string[]
  entries: Array<{ id: string; name: string }>
  model: string | null
  input_tokens: number
  output_tokens: number
  cost_usd: number
  max_usd: number
  background: boolean
  inline_entry_limit: number
}

export interface AiChangesetEntry {
  key: string
  label: string
  targetType: AiChangesetItem['target_type']
  targetId: string | null
  items: AiChangesetItem[]
}

/** Items grouped per entry, in the order they arrived. */
export function groupChangesetItems(items: readonly AiChangesetItem[]): AiChangesetEntry[] {
  const entries = new Map<string, AiChangesetEntry>()

  for (const item of items) {
    const key = `${item.target_type}:${item.target_id ?? item.id}`
    const entry = entries.get(key) ?? {
      key,
      label: item.target_label ?? item.target_id ?? '',
      targetType: item.target_type,
      targetId: item.target_id,
      items: [],
    }
    entry.items.push(item)
    entries.set(key, entry)
  }

  return [...entries.values()]
}

export const isOpenItem = (item: AiChangesetItem) =>
  item.status === 'pending' || item.status === 'conflict'

/** Field type for the history diff renderers, which name the blocks type `block`. */
export const diffFieldType = (item: AiChangesetItem): string | null =>
  item.field_type === 'blocks' ? 'block' : item.field_type

/**
 * A field path in the server's changeset form, with list items addressed by
 * their `id` instead of their index. `stamps` name the item ID at each index
 * position. Null when an index has no ID.
 */
export function idFieldPath(
  path: ReadonlyArray<string | number>,
  stamps: ReadonlyArray<{ pathIndex: number; id: string }> = []
): string | null {
  const segments: string[] = []

  for (const [index, segment] of path.entries()) {
    if (typeof segment === 'string') {
      segments.push(segment)
      continue
    }
    const id = stamps.find((stamp) => stamp.pathIndex === index)?.id
    if (!id) return null
    segments.push(id)
  }

  return segments.length > 0 ? segments.join('.') : null
}
