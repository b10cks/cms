import type { ContentBlockOperationPayload } from '~/composables/useContentLiveCollaboration'
import type { ContentTreeItem } from '~/composables/useContentTree'
import { isSameJsonValue } from '~/lib/contentEditorState'
import type { BlockAction, FieldPath } from '~/utils/preview-bridge'

/**
 * Structural edits on the editor's content tree, requested by the preview
 * (BLOCK_ACTION, BLOCK_MOVE) and planned here without touching the tree. The
 * content page applies a plan the same way BlocksBlock applies its own edits.
 */

/** Where a block sits: at `index` in the blocks list `parent[field]`. */
export interface BlockSlot {
  parent: ContentTreeItem
  field: string
  index: number
}

/** What a blocks field allows, from its schema. */
export interface BlockListRules {
  min: number | null
  max: number | null
  accepts: (blockSlug: string) => boolean
}

export type BlockListRulesLookup = (parent: ContentTreeItem, field: string) => BlockListRules | null

type ListOperation = Exclude<ContentBlockOperationPayload, { type: 'replace' }>

/** Replace the list `parent[field]` with `next`; `operation` is what collaborators receive. */
export interface ListEdit {
  parent: ContentTreeItem
  field: string
  previous: ContentTreeItem[]
  next: ContentTreeItem[]
  operation: ListOperation
}

/** Open the block picker of the list at `path` (joined with dots) at `index`. */
export interface BlockPickerRequest {
  path: string
  index: number
}

export type BlockPlan =
  | { kind: 'edit'; edits: ListEdit[]; select?: string }
  | { kind: 'pick'; slot: BlockSlot }
  | { kind: 'rejected' }

const REJECTED: BlockPlan = { kind: 'rejected' }

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isBlock = (value: unknown): value is ContentTreeItem =>
  isRecord(value) && typeof value.id === 'string' && typeof value.block === 'string'

const listAt = ({ parent, field }: Pick<BlockSlot, 'parent' | 'field'>): ContentTreeItem[] =>
  parent[field] as ContentTreeItem[]

/** Find the blocks list holding block `id`. The root itself has no slot. */
export function findBlockSlot(root: ContentTreeItem, id: string): BlockSlot | null {
  for (const [field, value] of Object.entries(root)) {
    if (!Array.isArray(value)) continue

    for (const [index, entry] of value.entries()) {
      if (!isBlock(entry)) continue
      if (entry.id === id) return { parent: root, field, index }

      const nested = findBlockSlot(entry, id)
      if (nested) return nested
    }
  }

  return null
}

/** Ids of the hidden blocks below `node`, at any depth, in document order. */
export function findHiddenBlockIds(node: Record<string, unknown>): string[] {
  return Object.values(node).flatMap((value) =>
    Array.isArray(value)
      ? value
          .filter(isBlock)
          .flatMap((block) => [...(block.hidden ? [block.id] : []), ...findHiddenBlockIds(block)])
      : []
  )
}

/** Path of block `id` from the root, as used in field paths; [] for the root. */
export function findBlockPath(root: ContentTreeItem, id: string): FieldPath | null {
  if (root.id === id) return []

  for (const [field, value] of Object.entries(root)) {
    if (!Array.isArray(value)) continue

    for (const [index, entry] of value.entries()) {
      if (!isBlock(entry)) continue

      const nested = findBlockPath(entry, id)
      if (nested) return [field, index, ...nested]
    }
  }

  return null
}

/**
 * Resolve a FIELD_SELECT target. `path` is relative to block `itemId` and may
 * pass through nested blocks; the innermost one is the block to open, and the
 * segment after it the field. `fieldPath` is the full path from the root.
 */
export function resolveFieldTarget(
  root: ContentTreeItem,
  itemId: string,
  path: FieldPath
): { itemId: string; field: string | null; fieldPath: FieldPath } | null {
  const basePath = findBlockPath(root, itemId)
  if (!basePath) return null

  let node: unknown = root
  for (const segment of basePath) node = (node as Record<string | number, unknown>)[segment]

  let blockId = itemId
  let fieldIndex = 0
  for (const [index, segment] of path.entries()) {
    if (node === null || typeof node !== 'object') break
    node = (node as Record<string | number, unknown>)[segment]
    if (isBlock(node)) {
      blockId = node.id
      fieldIndex = index + 1
    }
  }

  const field = path[fieldIndex]

  return {
    itemId: blockId,
    field: typeof field === 'string' ? field : null,
    fieldPath: [...basePath, ...path],
  }
}

/** The block at `path` from the root, as returned by findBlockPath. */
function blockAt(root: ContentTreeItem, path: FieldPath): ContentTreeItem | null {
  let node: unknown = root
  for (const segment of path) node = (node as Record<string | number, unknown>)[segment]
  return isBlock(node) ? node : null
}

/** Block `id`, anywhere in the tree. */
export function findBlock(root: ContentTreeItem, id: string): ContentTreeItem | null {
  const path = findBlockPath(root, id)
  return path && blockAt(root, path)
}

/** A copy of `target` with the value at `path` replaced, creating objects on the way. */
function setAt(target: unknown, path: FieldPath, value: unknown): unknown {
  const [key, ...rest] = path
  if (key === undefined) return value
  if (typeof key === 'number') {
    const next = Array.isArray(target) ? [...target] : []
    next[key] = setAt(next[key], rest, value)
    return next
  }
  const next: Record<string, unknown> = isRecord(target) ? { ...target } : {}
  next[key] = setAt(next[key], rest, value)
  return next
}

/** Set `block[field]` to `next`; `fieldPath` is the field's path from the root. */
export interface FieldEdit {
  block: ContentTreeItem
  field: string
  previous: unknown
  next: unknown
  fieldPath: FieldPath
}

/**
 * Plan a FIELD_UPDATE from the preview: `value` replaces the value at `path` in
 * block `itemId`, which may lead through nested blocks like a FIELD_SELECT.
 * Returns the whole-field edit, the unit forms and collaborators work in, or
 * null when the path addresses no field or the value is already there.
 */
export function planFieldUpdate(
  root: ContentTreeItem,
  itemId: string,
  path: FieldPath,
  value: unknown
): FieldEdit | null {
  const target = resolveFieldTarget(root, itemId, path)
  const blockPath = target && findBlockPath(root, target.itemId)
  const block = blockPath && blockAt(root, blockPath)
  if (!target?.field || !blockPath || !block) return null

  const previous = block[target.field]
  // Inline text cannot replace a structured list. The preview must send a
  // path into the list, or select the field in the editor.
  if (
    Array.isArray(previous) &&
    target.fieldPath.length === blockPath.length + 1 &&
    !Array.isArray(value)
  ) {
    return null
  }
  const next = setAt(previous, target.fieldPath.slice(blockPath.length + 1), value)
  if (isSameJsonValue(previous, next)) return null

  return {
    block,
    field: target.field,
    previous,
    next,
    fieldPath: [...blockPath, target.field],
  }
}

/** Deep copy of a block with fresh ids for it and every block nested in it. */
function withFreshIds<T>(value: T, newId: () => string): T {
  if (Array.isArray(value)) return value.map((entry) => withFreshIds(entry, newId)) as T
  if (!isRecord(value)) return value

  const copy = Object.fromEntries(
    Object.entries(value).map(([key, entry]) => [key, withFreshIds(entry, newId)])
  )
  if (isBlock(value)) copy.id = newId()

  return copy as T
}

const exceedsMax = (rules: BlockListRules | null, length: number) =>
  rules?.max != null && length >= rules.max
const reachesMin = (rules: BlockListRules | null, length: number) =>
  rules?.min != null && length <= rules.min

const reorder = (slot: BlockSlot, next: ContentTreeItem[]): BlockPlan => ({
  kind: 'edit',
  edits: [
    {
      ...slot,
      previous: listAt(slot),
      next,
      operation: { type: 'reorder', order: next.map((item) => item.id) },
    },
  ],
})

/**
 * Plan a BLOCK_ACTION. Returns null when it has nothing to do (unknown block,
 * moving past either end of the list, hiding a hidden or showing a visible block).
 */
export function planBlockAction(
  root: ContentTreeItem,
  itemId: string,
  action: BlockAction,
  rulesFor: BlockListRulesLookup,
  newId: () => string
): BlockPlan | null {
  const slot = findBlockSlot(root, itemId)
  if (!slot) return null

  const list = listAt(slot)
  const rules = rulesFor(slot.parent, slot.field)

  switch (action) {
    case 'move-up':
    case 'move-down': {
      const target = slot.index + (action === 'move-up' ? -1 : 1)
      if (target < 0 || target >= list.length) return null

      const next = [...list]
      const [moved] = next.splice(slot.index, 1)
      next.splice(target, 0, moved)
      return reorder(slot, next)
    }
    case 'duplicate': {
      if (exceedsMax(rules, list.length)) return REJECTED

      const copy = withFreshIds(list[slot.index], newId)
      const index = slot.index + 1
      const next = [...list]
      next.splice(index, 0, copy)
      return {
        kind: 'edit',
        edits: [
          { ...slot, previous: list, next, operation: { type: 'add', index, items: [copy] } },
        ],
        select: copy.id,
      }
    }
    case 'delete': {
      if (reachesMin(rules, list.length)) return REJECTED

      const next = list.filter((_, index) => index !== slot.index)
      return {
        kind: 'edit',
        edits: [
          { ...slot, previous: list, next, operation: { type: 'remove', itemIds: [itemId] } },
        ],
      }
    }
    case 'hide':
    case 'show': {
      // Same edit and collaboration op as the blocks field's own visibility toggle.
      const hidden = action === 'hide'
      const block = list[slot.index]
      if (Boolean(block.hidden) === hidden) return null

      const next = [...list]
      next[slot.index] = { ...block, hidden }
      return {
        kind: 'edit',
        edits: [
          { ...slot, previous: list, next, operation: { type: 'visibility', itemId, hidden } },
        ],
      }
    }
    case 'insert-before':
    case 'insert-after':
      if (exceedsMax(rules, list.length)) return REJECTED

      return {
        kind: 'pick',
        slot: { ...slot, index: slot.index + (action === 'insert-after' ? 1 : 0) },
      }
  }
}

/**
 * Plan a BLOCK_MOVE of `itemId` next to `targetId`, possibly into another
 * blocks list. Rejects what the target field's schema does not allow and moves
 * into the block's own subtree. Returns null when the order would not change.
 */
export function planBlockMove(
  root: ContentTreeItem,
  itemId: string,
  targetId: string,
  position: 'before' | 'after',
  rulesFor: BlockListRulesLookup
): BlockPlan | null {
  const from = findBlockSlot(root, itemId)
  const to = findBlockSlot(root, targetId)
  if (!from || !to) return null

  const fromList = listAt(from)
  const block = fromList[from.index]
  if (findBlockPath(block, targetId)) return REJECTED

  if (from.parent === to.parent && from.field === to.field) {
    const next = fromList.filter((item) => item.id !== itemId)
    const targetIndex = next.findIndex((item) => item.id === targetId)
    next.splice(position === 'before' ? targetIndex : targetIndex + 1, 0, block)
    if (next.every((item, index) => item === fromList[index])) return null

    return reorder(from, next)
  }

  const toList = listAt(to)
  const toRules = rulesFor(to.parent, to.field)
  if (!toRules?.accepts(block.block) || exceedsMax(toRules, toList.length)) return REJECTED
  if (reachesMin(rulesFor(from.parent, from.field), fromList.length)) return REJECTED

  const index = position === 'before' ? to.index : to.index + 1
  const toNext = [...toList]
  toNext.splice(index, 0, block)

  return {
    kind: 'edit',
    edits: [
      {
        ...from,
        previous: fromList,
        next: fromList.filter((item) => item.id !== itemId),
        operation: { type: 'remove', itemIds: [itemId] },
      },
      { ...to, previous: toList, next: toNext, operation: { type: 'add', index, items: [block] } },
    ],
  }
}

/**
 * Whether `block` may be added to a blocks field. Only nestable and universal
 * blocks nest; non-empty allow lists (blocks or tags) narrow that further.
 */
export function isBlockAllowed(
  field: Pick<BlocksSchema, 'block_whitelist' | 'tag_whitelist'>,
  block: Pick<BlockResource, 'slug' | 'type' | 'tags'>
): boolean {
  if (!['nestable', 'universal'].includes(block.type)) return false

  const blockAllowlist = (field.block_whitelist || []).filter(Boolean)
  const tagAllowlist = (field.tag_whitelist || []).filter(Boolean)
  if (blockAllowlist.length === 0 && tagAllowlist.length === 0) return true

  return (
    blockAllowlist.includes(block.slug) ||
    Boolean(block.tags?.some((tag) => tagAllowlist.includes(tag)))
  )
}
