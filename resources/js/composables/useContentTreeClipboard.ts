import type {
  ContentTreeClipboardItem,
  ContentTreeClipboardSnapshotItem,
} from '~/types/contents'

type ContentTreeClipboardItemLike = Pick<FlatContentMenuItem, 'id' | 'pid' | 'block_id' | 'type'>

export interface ContentTreeClipboardValidationContext {
  itemsById: Map<string, ContentTreeClipboardItemLike>
}

const clipboardState = ref<ContentTreeClipboardItem | null>(null)
const hasClipboardItem = ref(false)

const cloneSnapshotItem = (
  item: ContentTreeClipboardSnapshotItem
): ContentTreeClipboardSnapshotItem => ({
  ...item,
  descendant_ids: [...item.descendant_ids],
})

const normalizeClipboardItems = (clipboardItem: ContentTreeClipboardItem | null) => {
  if (!clipboardItem) {
    return []
  }

  if (clipboardItem.type === 'content-tree-clipboard-items') {
    return clipboardItem.data.map(cloneSnapshotItem)
  }

  return [cloneSnapshotItem(clipboardItem.data)]
}

export function useContentTreeClipboard() {
  const normalizeRootSelection = (
    ids: string[],
    parentById: Map<string, string | null | undefined>,
    treeOrderById: Map<string, number> = new Map()
  ) => {
    const uniqueIds = [...new Set(ids)].sort((left, right) => {
      return (treeOrderById.get(left) ?? Number.MAX_SAFE_INTEGER) -
        (treeOrderById.get(right) ?? Number.MAX_SAFE_INTEGER)
    })
    const selectedSet = new Set(uniqueIds)

    return uniqueIds.filter((id) => {
      let currentParentId = parentById.get(id) ?? null

      while (currentParentId) {
        if (selectedSet.has(currentParentId)) {
          return false
        }

        currentParentId = parentById.get(currentParentId) ?? null
      }

      return true
    })
  }

  const buildSnapshot = (
    itemIds: string[],
    options: {
      itemsById: Map<string, ContentTreeClipboardItemLike>
      descendantsById: Map<string, Set<string>>
      treeOrderById?: Map<string, number>
    }
  ): ContentTreeClipboardSnapshotItem[] => {
    return itemIds
      .map((id) => {
        const item = options.itemsById.get(id)
        if (!item) {
          return null
        }

        return {
          id: item.id,
          parent_id: item.pid ?? null,
          block_id: item.block_id,
          block_type: item.type,
          tree_index: options.treeOrderById?.get(item.id) ?? Number.MAX_SAFE_INTEGER,
          descendant_ids: [...(options.descendantsById.get(item.id) ?? new Set<string>())],
        } satisfies ContentTreeClipboardSnapshotItem
      })
      .filter((item): item is ContentTreeClipboardSnapshotItem => !!item)
      .sort((left, right) => left.tree_index - right.tree_index)
  }

  const writeClipboardItem = async (clipboardItem: ContentTreeClipboardItem) => {
    clipboardState.value =
      clipboardItem.type === 'content-tree-clipboard-items'
        ? {
            ...clipboardItem,
            data: clipboardItem.data.map(cloneSnapshotItem),
          }
        : {
            ...clipboardItem,
            data: cloneSnapshotItem(clipboardItem.data),
          }
    hasClipboardItem.value = true
  }

  const copyItem = async (
    item: ContentTreeClipboardSnapshotItem | ContentTreeClipboardSnapshotItem[],
    spaceId: string
  ) => {
    const clipboardItem: ContentTreeClipboardItem = Array.isArray(item)
      ? {
          type: 'content-tree-clipboard-items',
          data: item.map(cloneSnapshotItem),
          timestamp: Date.now(),
          spaceId,
        }
      : {
          type: 'content-tree-clipboard-item',
          data: cloneSnapshotItem(item),
          timestamp: Date.now(),
          spaceId,
        }

    await writeClipboardItem(clipboardItem)
  }

  const cutItem = async (
    item: ContentTreeClipboardSnapshotItem | ContentTreeClipboardSnapshotItem[],
    spaceId: string
  ) => {
    const clipboardItem: ContentTreeClipboardItem = Array.isArray(item)
      ? {
          type: 'content-tree-clipboard-items',
          data: item.map(cloneSnapshotItem),
          timestamp: Date.now(),
          spaceId,
          _isCut: true,
        }
      : {
          type: 'content-tree-clipboard-item',
          data: cloneSnapshotItem(item),
          timestamp: Date.now(),
          spaceId,
          _isCut: true,
        }

    await writeClipboardItem(clipboardItem)
  }

  const getClipboardItem = async (): Promise<ContentTreeClipboardItem | null> => {
    const clipboardItem = clipboardState.value
    if (!clipboardItem) {
      return null
    }

    if (clipboardItem.type === 'content-tree-clipboard-items') {
      return {
        ...clipboardItem,
        data: clipboardItem.data.map(cloneSnapshotItem),
      }
    }

    return {
      ...clipboardItem,
      data: cloneSnapshotItem(clipboardItem.data),
    }
  }

  const clearClipboard = async () => {
    clipboardState.value = null
    hasClipboardItem.value = false
  }

  const canPasteAtParent = (
    clipboardItem: ContentTreeClipboardItem | null,
    spaceId: string,
    targetParentId: string | null,
    context: ContentTreeClipboardValidationContext,
    options: {
      anchorId?: string | null
    } = {}
  ) => {
    if (!clipboardItem || clipboardItem.spaceId !== spaceId) {
      return false
    }

    const clipboardItems = normalizeClipboardItems(clipboardItem)
    const movingIds = new Set(clipboardItems.map((item) => item.id))
    const targetParent = targetParentId ? context.itemsById.get(targetParentId) : null

    if (targetParent?.type === 'single') {
      return false
    }

    for (const item of clipboardItems) {
      if (clipboardItem._isCut) {
        if (
          targetParentId &&
          (item.id === targetParentId || item.descendant_ids.includes(targetParentId))
        ) {
          return false
        }

        if (
          options.anchorId &&
          (item.id === options.anchorId || item.descendant_ids.includes(options.anchorId))
        ) {
          return false
        }
      }

      if (item.block_type !== 'single') {
        continue
      }

      if (targetParentId !== null) {
        return false
      }

      const duplicateRootSingle = [...context.itemsById.values()].some((existingItem) => {
        if (existingItem.pid !== null || existingItem.type !== 'single') {
          return false
        }

        if (existingItem.block_id !== item.block_id) {
          return false
        }

        if (clipboardItem._isCut && movingIds.has(existingItem.id)) {
          return false
        }

        return true
      })

      if (duplicateRootSingle) {
        return false
      }
    }

    return true
  }

  const canPasteIn = (
    clipboardItem: ContentTreeClipboardItem | null,
    spaceId: string,
    target: ContentTreeClipboardItemLike | null,
    context: ContentTreeClipboardValidationContext
  ) => {
    return canPasteAtParent(clipboardItem, spaceId, target?.id ?? null, context, {
      anchorId: target?.id ?? null,
    })
  }

  const canPasteAfter = (
    clipboardItem: ContentTreeClipboardItem | null,
    spaceId: string,
    target: ContentTreeClipboardItemLike | null,
    context: ContentTreeClipboardValidationContext
  ) => {
    if (!target) {
      return canPasteAtParent(clipboardItem, spaceId, null, context)
    }

    return canPasteAtParent(clipboardItem, spaceId, target.pid ?? null, context, {
      anchorId: target.id,
    })
  }

  return {
    buildSnapshot,
    canPasteAfter,
    canPasteIn,
    clearClipboard,
    copyItem,
    cutItem,
    getClipboardItem,
    hasClipboardItem: readonly(hasClipboardItem),
    normalizeClipboardItems,
    normalizeRootSelection,
  }
}
