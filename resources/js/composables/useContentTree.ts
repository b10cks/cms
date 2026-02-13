import type { MaybeRef } from 'vue'

import type { ContentBlock } from '~/types/contents'

export interface ContentTreeItem {
  id: string
  block: string
  [key: string]: ContentTreeItem | ContentTreeItem[] | unknown
}

export interface FindResult {
  item: ContentTreeItem | null
  path: Array<ContentTreeItem | ContentBlock>
  parent: ContentTreeItem | null
  parentKey: string | null
  index: number | null
}

export function useContentTree(
  contentRef: MaybeRef<ContentTreeItem>,
  root: MaybeRef<ContentBlock>
) {
  const findItemById = (itemId: string): FindResult => {
    const content = unref(contentRef)
    if (!content) {
      return { item: null, path: [], parent: null, parentKey: null, index: null }
    }

    if (content.id === itemId) {
      return {
        item: content,
        path: [unref(root), content],
        parent: null,
        parentKey: null,
        index: null,
      }
    }

    const result: FindResult = {
      item: null,
      path: [],
      parent: null,
      parentKey: null,
      index: null,
    }

    const findInObject = (obj: ContentTreeItem, currentPath: ContentTreeItem[] = []): boolean => {
      if (!obj || typeof obj !== 'object') return false

      if (obj.id === itemId) {
        result.item = obj
        result.path = [...currentPath, obj]
        return true
      }

      for (const key in obj) {
        const value = obj[key] as ContentTreeItem

        if (typeof value !== 'object' || value === null) continue

        if (Array.isArray(value)) {
          for (let i = 0; i < value.length; i++) {
            const item = value[i]

            if (!item || typeof item !== 'object' || !item.id) continue

            const newPath = [...currentPath]
            if (obj.id) newPath.push(obj)

            if (findInObject(item, newPath)) {
              result.parent = obj
              result.parentKey = key
              result.index = i
              return true
            }
          }
        } else if (value.id) {
          const newPath = [...currentPath]
          if (obj.id) newPath.push(obj)

          if (findInObject(value, newPath)) {
            result.parent = obj
            result.parentKey = key
            return true
          }
        }
      }

      return false
    }

    findInObject(content)
    return result
  }

  const buildBreadcrumbs = (itemId: string) => {
    const { path } = findItemById(itemId)
    const result = path
      .map((item) => ({
        id: item.id,
        label: item.block,
      }))
      .slice(0, -1)

    const rootValue = unref(root)
    result.unshift({
      id: null,
      label: rootValue?.name || rootValue?.slug || '',
    })

    return result
  }

  const updateItem = (itemId: string, updatedItem: ContentTreeItem) => {
    const content = unref(contentRef)
    if (!content) return false

    const { item, path } = findItemById(itemId)

    if (!item) return false

    if (path.length <= 1) {
      Object.assign(item, updatedItem)
      return true
    }

    let current: ContentTreeItem = content
    const pathItemIds = path.map((p) => p.id).filter(Boolean)
    for (let i = 0; i < pathItemIds.length - 1; i++) {
      const pathItemId = pathItemIds[i]
      const foundInArray = Object.entries(current).find(([_, value]) => {
        if (Array.isArray(value)) {
          return value.some((item) => item.id === pathItemId)
        }
        return false
      })

      if (foundInArray) {
        const [_, array] = foundInArray
        const arrayValue = array as ContentTreeItem[]
        const index = arrayValue.findIndex((item: ContentTreeItem) => item.id === pathItemId)
        current = arrayValue[index]
      } else {
        // Check for nested objects
        const foundInObject = Object.entries(current).find(([_, value]) => {
          return (
            typeof value === 'object' &&
            value !== null &&
            !Array.isArray(value) &&
            (value as any).id === pathItemId
          )
        })

        if (foundInObject) {
          const [_, obj] = foundInObject
          current = obj as ContentTreeItem
        } else {
          return false // Path item not found
        }
      }
    }

    // Now that we have the parent object, find the target item and update it
    const targetId = pathItemIds[pathItemIds.length - 1]

    for (const key in current) {
      const value = current[key]

      if (Array.isArray(value)) {
        const index = value.findIndex((item: ContentTreeItem) => item.id === targetId)
        if (index !== -1) {
          current[key][index] = { ...current[key][index], ...updatedItem }
          return true
        }
      } else if (typeof value === 'object' && value !== null && value.id === targetId) {
        current[key] = { ...current[key], ...updatedItem }
        return true
      }
    }

    return false
  }

  return {
    findItemById,
    buildBreadcrumbs,
    updateItem,
  }
}
