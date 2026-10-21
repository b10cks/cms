import type {
  ContentResource,
  ContentSettings,
  ContentTreeMoveOperation,
} from '~/types/contents'

/** The flat menu cache, keyed by content id. Translations live in their canonical entry's `i18n`. */
export type ContentMenu = Record<string, FlatContentMenuItem>

/** Manual order, as the server resequences it: position, then name, then id. */
export const compareByPosition = (a: FlatContentMenuItem, b: FlatContentMenuItem) =>
  (a.position ?? 0) - (b.position ?? 0) ||
  (a.name || '').localeCompare(b.name || '') ||
  a.id.localeCompare(b.id)

const sortsByContentField = (settings: Partial<ContentSettings> | undefined) =>
  settings?.child_sort_by?.startsWith('content.') ?? false

export function withRenamedItem(menu: ContentMenu, id: string, name: string): ContentMenu | null {
  const item = menu[id]
  return item ? { ...menu, [id]: { ...item, name } } : null
}

/**
 * The menu with a saved entry folded in from its write response, or null when
 * the response cannot place it and the menu has to refetch: an unknown entry,
 * a new parent or block, or a sort value (`sv`) the response does not carry.
 * A name or date sort needs nothing extra, the menu re-sorts on every update.
 */
export function withSavedContent(menu: ContentMenu, content: ContentResource): ContentMenu | null {
  if (content.current_version_id === undefined) return null

  const drf = content.current_version_id !== content.published_version_id

  if (content.i18n_parent_id) {
    const parent = menu[content.i18n_parent_id]
    if (!parent?.i18n?.some((entry) => entry.id === content.id)) return null

    return {
      ...menu,
      [parent.id]: {
        ...parent,
        i18n: parent.i18n.map((entry) =>
          entry.id === content.id
            ? { ...entry, name: content.name, published_at: content.published_at, drf }
            : entry
        ),
      },
    }
  }

  const item = menu[content.id]
  if (
    !item ||
    (item.pid ?? null) !== content.parent_id ||
    item.block_id !== content.block_id ||
    sortsByContentField(item.pid ? menu[item.pid]?.settings : undefined)
  ) {
    return null
  }

  // Switching this entry's own children to a content-field sort needs their `sv`.
  const childSort = content.settings?.child_sort_by
  if (sortsByContentField(content.settings) && childSort !== item.settings?.child_sort_by) {
    return null
  }

  return {
    ...menu,
    [item.id]: {
      ...item,
      name: content.name,
      slug: content.slug,
      settings: content.settings,
      pat: content.published_at,
      drf,
      uat: content.updated_at,
    },
  }
}

/**
 * Applies a tree move the way the server does: reparent, and where the
 * destination is manually sorted, resequence its children 0..n with the moved
 * entries inserted after `after_id`, else at `position`, else at the end.
 */
export function withMovedItems(
  menu: ContentMenu,
  operation: Pick<ContentTreeMoveOperation, 'ids' | 'parent_id' | 'after_id' | 'position'>,
  resequence: boolean
): ContentMenu {
  const moving = operation.ids.filter((id) => menu[id])
  if (moving.length === 0) return menu

  const parentId = operation.parent_id ?? null
  const movingIds = new Set(moving)
  const next: ContentMenu = { ...menu }
  const oldParentIds = new Set(moving.map((id) => menu[id].pid ?? null))

  for (const id of moving) {
    next[id] = { ...menu[id], pid: parentId }
  }

  if (resequence) {
    const siblings = Object.values(menu)
      .filter((item) => (item.pid ?? null) === parentId && !movingIds.has(item.id))
      .sort(compareByPosition)

    let insertIndex = siblings.length
    const afterId = operation.after_id ?? null
    if (afterId !== null && !movingIds.has(afterId)) {
      const afterIndex = siblings.findIndex((item) => item.id === afterId)
      if (afterIndex >= 0) insertIndex = afterIndex + 1
    } else if (operation.position !== undefined) {
      insertIndex = Math.max(0, Math.min(operation.position, siblings.length))
    }

    const ordered = [
      ...siblings.slice(0, insertIndex).map((item) => item.id),
      ...moving,
      ...siblings.slice(insertIndex).map((item) => item.id),
    ]
    ordered.forEach((id, position) => {
      if (next[id].position !== position) next[id] = { ...next[id], position }
    })
  }

  if (parentId && next[parentId]) {
    next[parentId] = { ...next[parentId], children: true }
  }
  for (const oldParentId of oldParentIds) {
    if (!oldParentId || oldParentId === parentId || !next[oldParentId]) continue
    const children = Object.values(next).some((item) => item.pid === oldParentId)
    next[oldParentId] = { ...next[oldParentId], children }
  }

  return next
}

/**
 * The given entries with their whole subtrees and every translation of them:
 * the details whose parent, slug path or block a tree operation can change.
 */
export function collectSubtreeIds(menu: ContentMenu, ids: string[]): Set<string> {
  const childIds = new Map<string, string[]>()
  for (const item of Object.values(menu)) {
    if (!item.pid) continue
    const siblings = childIds.get(item.pid)
    if (siblings) siblings.push(item.id)
    else childIds.set(item.pid, [item.id])
  }

  const collected = new Set<string>()
  const queue = [...ids]
  for (let id = queue.pop(); id !== undefined; id = queue.pop()) {
    if (collected.has(id)) continue
    collected.add(id)
    menu[id]?.i18n?.forEach((translation) => collected.add(translation.id))
    queue.push(...(childIds.get(id) ?? []))
  }

  return collected
}
