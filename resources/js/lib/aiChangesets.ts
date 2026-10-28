/**
 * Types and helpers for AI changesets: field-level proposals pinned to the
 * version a run read, reviewed per field, entry or changeset.
 */
import type { DiffChange } from '~/components/content/diff/field-diff'
import type { AiGlossaryFinding, AiRunRecord } from '~/lib/aiRun'

/** Kinds that bulk scopes, schedules and preflight work on. */
export type AiTargetType = 'content' | 'data_entry' | 'asset'

/** Kinds the AI can propose changes to; block definitions are reviewed only. */
export type AiProposalTargetType = AiTargetType | 'block'

export type AiChangesetStatus = 'open' | 'partially_applied' | 'applied' | 'discarded'

export type AiChangesetItemStatus = 'pending' | 'accepted' | 'rejected' | 'conflict'

export interface AiChangesetItem {
  id: string
  operation: 'update' | 'create'
  target_type: AiProposalTargetType | 'selection'
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
  /** Cross-locale items: the locales and the source text the translation came from */
  source_locale?: string | null
  target_locale?: string | null
  source?: unknown
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

/** Link to the reviewed entry in its editor, when it has one. */
export function entryLink(spaceId: string, entry: Pick<AiChangesetEntry, 'targetType' | 'targetId'>): string | null {
  if (!entry.targetId) return null
  if (entry.targetType === 'content') return `/${spaceId}/content/${entry.targetId}`
  if (entry.targetType === 'block') return `/${spaceId}/blocks/${entry.targetId}`
  return null
}

/** The field definitions of a proposed new block, in schema order. */
export function createdBlockFields(item: AiChangesetItem): Array<{ key: string; name: string; type: string }> {
  if (item.target_type !== 'block' || item.operation !== 'create') return []
  const schema = typeof item.after === 'object' && item.after !== null && 'schema' in item.after ? item.after.schema : null
  if (typeof schema !== 'object' || schema === null) return []

  return Object.entries(schema).map(([key, field]: [string, unknown]) => {
    const definition = typeof field === 'object' && field !== null ? (field as Record<string, unknown>) : {}
    return {
      key,
      name: typeof definition.name === 'string' ? definition.name : key,
      type: typeof definition.type === 'string' ? definition.type : '',
    }
  })
}

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

export interface AiBlockListChange {
  id: string
  type: 'added' | 'removed' | 'changed'
  oldValue?: Record<string, unknown>
  newValue?: Record<string, unknown>
  children: DiffChange[]
}

const blockItems = (value: unknown): Record<string, unknown>[] =>
  Array.isArray(value)
    ? value.filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null && typeof item.id === 'string')
    : []

/**
 * The blocks a block-list proposal adds, removes or changes, aligned by block
 * ID: in the new order, removed blocks last. Unchanged blocks are left out;
 * a changed block lists its changed fields.
 */
export function blockListChanges(before: unknown, after: unknown): AiBlockListChange[] {
  const old = new Map(blockItems(before).map((item) => [item.id as string, item]))
  const next = blockItems(after)
  const kept = new Set(next.map((item) => item.id as string))

  const changes = next.flatMap((item): AiBlockListChange[] => {
    const id = item.id as string
    const previous = old.get(id)
    if (!previous) return [{ id, type: 'added', newValue: item, children: [] }]

    const keys = [...new Set([...Object.keys(previous), ...Object.keys(item)])].filter((key) => key !== 'id' && key !== 'block')
    const children = keys
      .filter((key) => JSON.stringify(previous[key]) !== JSON.stringify(item[key]))
      .map((key): DiffChange => ({
        type: previous[key] === undefined ? 'added' : item[key] === undefined ? 'removed' : 'changed',
        path: key,
        oldValue: previous[key],
        newValue: item[key],
      }))
    return children.length > 0 || previous.block !== item.block
      ? [{ id, type: 'changed', oldValue: previous, newValue: item, children }]
      : []
  })

  const removed = [...old.values()]
    .filter((item) => !kept.has(item.id as string))
    .map((item): AiBlockListChange => ({ id: item.id as string, type: 'removed', oldValue: item, children: [] }))

  return [...changes, ...removed]
}
