/**
 * Types and helpers for AI changesets: field-level changes pinned to the
 * version a run read. Content is already in its draft, and only a conflict needs
 * a person. Everything else is a proposal, decided per field, entry or changeset.
 */
import type { DiffChange } from '~/components/content/diff/field-diff'
import type { AiRunRecord } from '~/lib/aiRun'

/** Kinds that bulk scopes, schedules and preflight work on. */
export type AiTargetType = 'content' | 'data_entry' | 'asset'

/** Kinds the AI can propose changes to; block definitions are reviewed only. */
export type AiProposalTargetType = AiTargetType | 'block'

/** New asset folders and tags, created when their proposal is accepted. */
export type AiStructureTargetType = 'asset_folder' | 'asset_tag'

export const STRUCTURE_TARGET_TYPES: ReadonlySet<string> = new Set<AiStructureTargetType>(['asset_folder', 'asset_tag'])

export type AiChangesetStatus = 'open' | 'partially_applied' | 'applied' | 'discarded'

export type AiChangesetItemStatus = 'pending' | 'accepted' | 'rejected' | 'conflict'

/** A reason an item needs a person's look, set by the domain tool or a failed bulk accept. */
export interface AiItemFlag {
  code: string
  message: string
}

/** Why a translation unit was proposed. */
export type AiTranslationReason = 'missing' | 'empty' | 'stale' | 'same_as_source'

/** One translated text of an item and the source it was translated from. */
export interface AiTranslationUnit {
  unit_id: string
  reason: AiTranslationReason | null
  source: string
  /** The source the replaced translation was made from, stale units only */
  previous_source: string | null
}

/** What an asset proposal rests on: the image that reached the model, or a declared text basis. */
export type AiItemEvidence =
  | { basis: 'image'; run_id?: string; message?: number; sha256?: string | null }
  | { basis: 'metadata'; sources?: string[] }
  | { basis: 'instruction'; quote?: string }

/** The knowledge versions a proposal was written under. */
export interface AiItemPolicy {
  snapshot_id: string
  fingerprint?: string
}

/** Review data of a proposal: asset review, see `AssetReview` on the server, and its provenance. */
export interface AiItemReview {
  evidence?: AiItemEvidence
  policy?: AiItemPolicy
  group?: string
  reason?: string
  /** Asset IDs that justify a new folder or tag */
  examples?: string[]
  flags?: AiItemFlag[]
  /** Why the last accept could not apply the item */
  problem?: AiItemFlag
  depends_on?: string[]
  bundle?: string
  /** Display names of the folder and tag IDs and `new:` refs in the item; `root` names the library root */
  labels?: Record<string, string>
}

export interface AiChangesetItem {
  id: string
  operation: 'update' | 'create'
  target_type: AiProposalTargetType | AiStructureTargetType | 'selection'
  target_id: string | null
  target_label: string | null
  base_version_id: string | null
  path: string | null
  field_type: string | null
  before: unknown
  after: unknown
  /** What a person applied when they edited the change first */
  final: unknown
  current: unknown
  status: AiChangesetItemStatus
  flags?: AiItemFlag[]
  translation_units?: AiTranslationUnit[]
  review?: AiItemReview | null
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
  /** Set when the changeset collects an operation's batches; its review is grouped */
  operation_id?: string | null
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

export interface AiDecision {
  item_ids?: string[]
  overwrite?: boolean
  /** Leave items that need an individual look open */
  bulk?: boolean
  /** Reviewer edits of text proposals, item ID => text to apply instead */
  values?: Record<string, string>
}

export interface AiDecisionResult {
  data: AiChangeset
  accepted?: number
  conflicts?: number
  failed?: number
  blocked?: number
  rejected?: number
}

/** One entry an operation changed, conflicts first. */
export interface AiChangedEntry {
  target_id: string | null
  label: string | null
  written: number
  conflicts: number
}

/** What an operation did to drafts. */
export interface AiChangesSummary {
  /** Entries with something in their draft */
  changed: number
  /** Fields not written because someone changed them */
  conflicts: number
}

export interface AiOperationChangesPage {
  data: AiChangedEntry[]
  summary: AiChangesSummary
  meta: { total: number; page: number; per_page: number }
}

/** The key of the group that holds new asset folders and tags. */
export const STRUCTURE_GROUP = 'structure'

/** A group of an operation's proposals, see `OperationReview` on the server. */
export interface AiReviewGroup {
  key: string
  /** The asset review label, null for field groups and the structure group */
  label: string | null
  operation: AiChangesetItem['operation']
  target_type: AiChangesetItem['target_type']
  /** Field path with IDs folded to `*` */
  path: string | null
  field_type: string | null
  counts: Record<AiChangesetItemStatus, number>
  /** Open items a group accept takes */
  bulk: number
  /** Open items left to an individual decision */
  individual: number
  /** A group decision the queue is working on, until it is done or failed */
  queued: 'accept' | 'reject' | null
  examples: AiChangesetItem[]
}

export interface AiReviewItemsPage {
  data: AiChangesetItem[]
  meta: { total: number; page: number; per_page: number }
}

/** A group accept; `queued` counts the items a background job decides instead (202). */
export interface AiGroupDecisionResult {
  accepted: number
  conflicts: number
  failed: number
  blocked: number
  individual: number
  queued: number
}

/** A group reject; `queued` counts the items a background job rejects instead (202). */
export interface AiGroupRejectResult {
  rejected: number
  queued: number
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
  /** More entries match than one run takes; `count` stops at `max_entries` */
  truncated: boolean
  max_entries: number
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

/** Content in its draft: done, with nothing to decide. */
export const isWrittenItem = (item: AiChangesetItem) => item.target_type === 'content' && item.status === 'accepted'

/** The same figures an operation's receipt shows, for a changeset the client holds. */
export function changesSummary(items: readonly AiChangesetItem[]): AiChangesSummary {
  return {
    changed: new Set(items.filter(isWrittenItem).map((item) => item.target_id)).size,
    conflicts: items.filter((item) => item.target_type === 'content' && item.status === 'conflict').length,
  }
}

/** "Changed 412 entries, 3 conflicts": what happened, in one line. */
export function changesLine(
  t: (key: string, named: Record<string, unknown>, plural: number) => string,
  { changed, conflicts }: AiChangesSummary,
  formatNumber: (value: number) => string = String
): string {
  return [
    t('components.aiThreads.summary.changed', { count: formatNumber(changed) }, changed),
    t('components.aiThreads.summary.conflicts', { count: formatNumber(conflicts) }, conflicts),
  ].join(', ')
}

/** Entries with a conflict first, else in the order they arrived. */
export function attentionFirst(entries: readonly AiChangesetEntry[]): AiChangesetEntry[] {
  const rank = (entry: AiChangesetEntry) => (entry.items.some((item) => item.status === 'conflict') ? 0 : 1)
  return [...entries].sort((a, b) => rank(a) - rank(b))
}

/** Flags and the last accept problem of an item, shown as warnings on the field. */
export const itemNotes = (item: AiChangesetItem): AiItemFlag[] => [
  ...(item.flags ?? []),
  ...(item.review?.flags ?? []),
  ...(item.review?.problem ? [item.review.problem] : []),
]

/**
 * Whether a bulk accept leaves the item to an individual decision: the
 * client side of `DecidableItems::needsIndividualLook()`.
 */
export const needsIndividualLook = (item: AiChangesetItem) =>
  item.status === 'conflict' ||
  itemNotes(item).length > 0 ||
  STRUCTURE_TARGET_TYPES.has(item.target_type)

/** The reviewer's text, when it differs from a text proposal. */
export const editedText = (item: AiChangesetItem, edit: string | undefined): string | undefined =>
  edit !== undefined && edit !== item.after ? edit : undefined

const ID_SEGMENT = /^([0-9a-hjkmnp-tv-z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\d+|\*)$/i

/** A field path or translation unit ID for reading: IDs dropped, `title → text`. */
export const readablePath = (path: string) =>
  path
    .split('.')
    .filter((segment) => !ID_SEGMENT.test(segment))
    .join(' → ')

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null

/** Walks unit ID segments: list items by `id`, table cells under `cells`. */
const walkUnit = (node: unknown, segments: readonly string[]): unknown =>
  segments.reduce<unknown>((current, segment) => {
    if (Array.isArray(current)) return current.find((entry) => isRecord(entry) && entry.id === segment)
    if (!isRecord(current)) return undefined
    if (segment in current) return current[segment]
    return isRecord(current.cells) ? current.cells[segment] : undefined
  }, node)

/**
 * A translation unit's text inside an item: `before` is the current
 * translation, `after` the proposal (or the reviewer's edit). Units of a
 * composite field or a new entry are read from inside the value.
 */
export function translationUnitValue(item: AiChangesetItem, unitId: string, side: 'before' | 'after'): unknown {
  if (item.operation === 'create') {
    return side === 'after' && isRecord(item.after) ? walkUnit(item.after.content, unitId.split('.')) : undefined
  }
  const value = side === 'after' ? (item.final ?? item.after) : item.before
  const path = item.path ?? ''
  if (unitId === path) return value
  return unitId.startsWith(`${path}.`) ? walkUnit(value, unitId.slice(path.length + 1).split('.')) : undefined
}

/** Rich text sources arrive as HTML; the source delta compares their text. */
export const plainText = (value: string) => value.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/** Tags an asset proposal adds and removes, by display name. */
export function assetTagChange(item: AiChangesetItem): { add: string[]; remove: string[] } | null {
  if (item.target_type !== 'asset' || item.path !== 'tags' || !isRecord(item.after)) return null
  const names = (refs: unknown) =>
    Array.isArray(refs) ? refs.filter((ref): ref is string => typeof ref === 'string').map((ref) => item.review?.labels?.[ref] ?? ref) : []
  return { add: names(item.after.add), remove: names(item.after.remove) }
}

/** The folders an asset moves between, by display name; null names the library root. */
export function assetFolderChange(item: AiChangesetItem): { from: string | null; to: string | null } | null {
  if (item.target_type !== 'asset' || item.path !== 'folder_id') return null
  const name = (ref: unknown) => (typeof ref === 'string' ? (item.review?.labels?.[ref] ?? ref) : (item.review?.labels?.root ?? null))
  return { from: name(item.before), to: name(item.after) }
}

/** A new folder's parent by display name; null is the library root. */
export function structureParent(item: AiChangesetItem): string | null {
  if (item.target_type !== 'asset_folder' || !isRecord(item.after)) return null
  const parent = item.after.parent
  return typeof parent === 'string' ? (item.review?.labels?.[parent] ?? parent) : null
}

/** Link to the reviewed entry in its editor, when it has one. */
export function entryLink(spaceId: string, entry: Pick<AiChangesetEntry, 'targetType' | 'targetId'>): string | null {
  if (!entry.targetId) return null
  if (entry.targetType === 'content') return `/${spaceId}/content/${entry.targetId}`
  if (entry.targetType === 'block') return `/${spaceId}/blocks/${entry.targetId}`
  return null
}

/** Link to a content entry's version history, which is how to go back. */
export function entryHistoryLink(spaceId: string, entry: Pick<AiChangesetEntry, 'targetType' | 'targetId'>): string | null {
  return entry.targetType === 'content' && entry.targetId ? `/${spaceId}/content/${entry.targetId}/versions` : null
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
