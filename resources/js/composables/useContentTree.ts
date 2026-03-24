import type { MaybeRef } from 'vue'

import type { ContentBlock } from '~/types/contents'

export interface ContentTreeItem {
  id: string
  block: string
  [key: string]: ContentTreeItem | ContentTreeItem[] | unknown
}

export interface FindResult {
  item: ContentTreeItem | null
  path: ContentTreeItem[]
  parent: ContentTreeItem | null
  parentKey: string | null
  index: number | null
}

type InternalFindResult = {
  item: ContentTreeItem
  path: ContentTreeItem[]
  parent: ContentTreeItem | null
  parentKey: string | null
  index: number | null
}

const isContentTreeItem = (value: unknown): value is ContentTreeItem =>
  Boolean(
    value &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    'id' in value &&
    typeof (value as { id?: unknown }).id === 'string'
  )

const findNestedItem = (
  node: ContentTreeItem,
  itemId: string,
  path: ContentTreeItem[] = []
): InternalFindResult | null => {
  if (!isContentTreeItem(node)) {
    return null
  }

  const currentPath = [...path, node]

  if (node.id === itemId) {
    return {
      item: node,
      path: currentPath,
      parent: path[path.length - 1] ?? null,
      parentKey: null,
      index: null,
    }
  }

  for (const [key, value] of Object.entries(node)) {
    if (!value || typeof value !== 'object') {
      continue
    }

    if (Array.isArray(value)) {
      for (const [index, entry] of value.entries()) {
        if (!isContentTreeItem(entry)) {
          continue
        }

        const result = findNestedItem(entry, itemId, currentPath)
        if (result) {
          return {
            ...result,
            parent: result.parent ?? node,
            parentKey: result.parentKey ?? key,
            index: result.index ?? index,
          }
        }
      }

      continue
    }

    if (!isContentTreeItem(value)) {
      continue
    }

    const result = findNestedItem(value, itemId, currentPath)
    if (result) {
      return {
        ...result,
        parent: result.parent ?? node,
        parentKey: result.parentKey ?? key,
      }
    }
  }

  return null
}

export function useContentTree(
  contentRef: MaybeRef<ContentTreeItem>,
  _root: MaybeRef<ContentBlock>
) {
  const findItemById = (itemId: string): FindResult | null => {
    const content = unref(contentRef)
    if (!content || content.id === itemId) {
      return null
    }

    const result = findNestedItem(content, itemId)
    if (!result) {
      return null
    }

    return {
      item: result.item,
      path: result.path,
      parent: result.parent,
      parentKey: result.parentKey,
      index: result.index,
    }
  }

  const buildBreadcrumbs = (itemId: string) => {
    const result = findItemById(itemId)
    if (!result) return []

    return result.path
      .map((item) => ({
        id: item.id,
        label: item.block,
      }))
      .slice(0, -1)
  }

  const updateItem = (itemId: string, updatedItem: ContentTreeItem) => {
    const result = findItemById(itemId)
    if (!result?.item) {
      return false
    }

    Object.assign(result.item, updatedItem)
    return true
  }

  return {
    findItemById,
    buildBreadcrumbs,
    updateItem,
  }
}
