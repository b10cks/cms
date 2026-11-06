import type { ContentTreeItem } from '~/composables/useContentTree'
import { DRILL_IN_DEPTH, findBlockPath, findBlockSlot } from '~/lib/blockTreeEdits'
import type { CommentResource } from '~/types/comments'
import type { FieldPath } from '~/utils/preview-bridge'

/** What a comment needs to know about a block to name its fields. */
export type CommentBlockLookup = Record<
  string,
  { name?: string | null; schema?: Record<string, { name?: string | null }> | null }
>

export type CommentLocation =
  | { kind: 'entry' }
  | { kind: 'missing' }
  | { kind: 'place'; label: string; itemId: string; field: string | null }

/** The blocks on the way down `path`, each with its position in its list. */
const blocksAlong = (root: ContentTreeItem, path: FieldPath) => {
  const chain: Array<{ block: ContentTreeItem; index: number }> = []
  let node = root

  for (let i = 0; i < path.length; i += 2) {
    const index = path[i + 1] as number
    node = (node[path[i]] as ContentTreeItem[])[index]
    chain.push({ block: node, index })
  }

  return chain
}

/** Where a thread belongs, named the way the translation view names a field: "Hero 2, Header". */
export const locateComment = (
  comment: Pick<CommentResource, 'item_id' | 'field'>,
  root: ContentTreeItem,
  blocks: CommentBlockLookup
): CommentLocation => {
  const { item_id: itemId, field } = comment
  if (!field && (!itemId || itemId === root.id)) return { kind: 'entry' }

  const path = itemId ? findBlockPath(root, itemId) : []
  if (!path) return { kind: 'missing' }

  const last = blocksAlong(root, path).at(-1)
  const block = last?.block ?? root
  const definition = blocks[block.block]
  const blockName = last && `${definition?.name || block.block} ${last.index + 1}`
  if (!field) return { kind: 'place', label: blockName ?? '', itemId: block.id, field: null }

  // A block type that isn't loaded says nothing about its fields, so only a known schema can rule one out.
  const fieldSchema = definition?.schema?.[field]
  if (definition?.schema && !fieldSchema) return { kind: 'missing' }

  const fieldName = fieldSchema?.name || field

  return {
    kind: 'place',
    label: blockName ? `${blockName}, ${fieldName}` : fieldName,
    itemId: block.id,
    field,
  }
}

/** How the editor gets to a field, taken up by the parts of the editor that are on the way. */
export interface FieldReveal {
  itemId: string
  field: string | null
  /** The block to open through the hash route; null stays in the entry's own column. */
  openItem: string | null
  /** Blocks to expand inline in that column. */
  expand: string[]
  /** For each block on the way, the field whose editor tab has to show. */
  pages: Record<string, string>
  /** The `data-field-path` of the target. */
  fieldPath: string
  /** The target is this block's row in the list `field`, to focus rather than open. */
  row?: string
}

/**
 * Plan the way to a field of block `itemId`. A column expands rows of its first
 * lists and opens anything deeper through the hash route, where depth starts again.
 */
export const planFieldReveal = (
  root: ContentTreeItem,
  itemId: string,
  field: string | null
): FieldReveal | null => {
  const path = findBlockPath(root, itemId)
  if (!path) return null

  const ids = [root.id, ...blocksAlong(root, path).map(({ block }) => block.id)]
  const blockIds = ids.slice(1)
  const levelsPerColumn = DRILL_IN_DEPTH + 1
  const opened = Math.floor(blockIds.length / levelsPerColumn) * levelsPerColumn

  const pages: Record<string, string> = {}
  ids.forEach((id, level) => {
    const next = level * 2 < path.length ? String(path[level * 2]) : field
    if (next) pages[id] = next
  })

  return {
    itemId,
    field,
    openItem: opened ? blockIds[opened - 1] : null,
    expand: blockIds.slice(opened),
    pages,
    fieldPath: ['content', ...path, ...(field ? [field] : [])].join('.'),
  }
}

/** Plan the way to the row of block `itemId` in its list, for a thread that names only a block. */
export const planRowReveal = (root: ContentTreeItem, itemId: string): FieldReveal | null => {
  const slot = findBlockSlot(root, itemId)
  const reveal = slot && planFieldReveal(root, slot.parent.id, slot.field)

  return reveal && { ...reveal, row: itemId }
}
