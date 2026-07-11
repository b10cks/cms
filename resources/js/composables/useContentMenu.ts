// src/composables/useContentMenu.ts
import { useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import { isClient } from '~/lib/env'
import type { ContentChildSortBy, ContentResource } from '~/types/contents'

import { queryKeys } from './useQueryClient'

export function useContentMenu(spaceId: MaybeRef<string>) {
  const queryClient = useQueryClient()

  // Create a computed API instance that updates when spaceId changes
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  // Query to fetch the content menu
  const useContentMenuQuery = (enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contentMenu(spaceId).all()),
      queryFn: async () => {
        const response = await spaceAPI.value.contentMenu.get()
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(enabled)),
    })
  }

  const findItemById = (
    menuData: Record<string, FlatContentMenuItem> | undefined,
    idRef: MaybeRef<string>
  ): FlatContentMenuItem | null => {
    const id = unref(idRef)
    if (!menuData) return null
    return menuData[id] || null
  }

  const compareByPosition = (a: FlatContentMenuItem, b: FlatContentMenuItem) =>
    (a.position ?? 0) - (b.position ?? 0) ||
    (a.name || '').localeCompare(b.name || '') ||
    a.id.localeCompare(b.id)

  const SORT_DATE_KEYS: Record<
    Exclude<ContentChildSortBy, 'inherit' | 'manual' | 'name'>,
    'pat' | 'cat' | 'uat'
  > = {
    published_at: 'pat',
    created_at: 'cat',
    updated_at: 'uat',
  }

  // Each folder can override how its children are ordered via its own
  // settings; without an override the manual position order applies.
  const comparatorFor = (
    parent: FlatContentMenuItem | undefined
  ): ((a: FlatContentMenuItem, b: FlatContentMenuItem) => number) => {
    const sortBy = parent?.settings?.child_sort_by as ContentChildSortBy | undefined
    if (!sortBy || sortBy === 'inherit' || sortBy === 'manual') {
      return compareByPosition
    }

    const direction = parent?.settings?.child_sort_direction === 'desc' ? -1 : 1

    if (sortBy === 'name') {
      return (a, b) =>
        direction * (a.name || '').localeCompare(b.name || '') || a.id.localeCompare(b.id)
    }

    const key = SORT_DATE_KEYS[sortBy]
    return (a, b) => {
      const aValue = a[key]
      const bValue = b[key]
      // Entries without a value (e.g. unpublished) always sort last.
      if (!aValue || !bValue) {
        return Number(!aValue) - Number(!bValue) || compareByPosition(a, b)
      }

      return (
        direction * (Date.parse(aValue) - Date.parse(bValue)) ||
        (a.name || '').localeCompare(b.name || '') ||
        a.id.localeCompare(b.id)
      )
    }
  }

  // reka-ui calls get-children for every expanded node on every tree re-render.
  // Building a per-parent lookup once (and memoizing it on the menuData identity,
  // which is replaced wholesale by TanStack Query on every update) turns each of
  // those calls from an O(N) scan+sort into an O(1) map lookup.
  const childrenIndexCache = new WeakMap<object, Map<string | null, FlatContentMenuItem[]>>()

  const getChildrenIndex = (
    menuData: Record<string, FlatContentMenuItem>
  ): Map<string | null, FlatContentMenuItem[]> => {
    const cached = childrenIndexCache.get(menuData)
    if (cached) return cached

    const index = new Map<string | null, FlatContentMenuItem[]>()
    for (const item of Object.values(menuData)) {
      const parentId = item.pid ?? null
      const bucket = index.get(parentId)
      if (bucket) bucket.push(item)
      else index.set(parentId, [item])
    }
    for (const [parentId, bucket] of index) {
      bucket.sort(comparatorFor(parentId ? menuData[parentId] : undefined))
    }

    childrenIndexCache.set(menuData, index)
    return index
  }

  const getRootItems = (
    menuData: Record<string, FlatContentMenuItem> | undefined
  ): FlatContentMenuItem[] => {
    if (!menuData) return []
    const roots = getChildrenIndex(menuData).get(null) ?? []

    return [
      ...roots.filter((item) => item.type !== 'single'),
      ...roots.filter((item) => item.type === 'single'),
    ]
  }

  const getChildren = (
    menuData: Record<string, FlatContentMenuItem> | undefined,
    parentIdRef: MaybeRef<string | null>
  ): FlatContentMenuItem[] => {
    const parentId = unref(parentIdRef)
    if (!menuData) return []
    return getChildrenIndex(menuData).get(parentId ?? null) ?? []
  }

  const buildBreadcrumbs = (
    menuData: Record<string, FlatContentMenuItem> | undefined,
    contentIdRef: MaybeRef<string>
  ): Array<{
    id: string
    name: string
  }> => {
    const contentId = unref(contentIdRef)
    if (!menuData) return []

    const breadcrumbs = []
    let currentItem = findItemById(menuData, contentId)

    if (!currentItem) return []

    // Add the current item
    breadcrumbs.push({
      id: currentItem.id,
      name: currentItem.name,
    })

    // Traverse up the tree using parent_id
    while (currentItem && currentItem.pid) {
      currentItem = findItemById(menuData, currentItem.pid)

      if (currentItem) {
        breadcrumbs.unshift({
          id: currentItem.id,
          name: currentItem.name,
        })
      }
    }

    return breadcrumbs
  }

  const setupEcho = () => {
    if (!isClient) return

    try {
      const echo = useEcho()
      if (!echo) return
      echo
        .channel(`spaces.${toValue(spaceId)}.content`)
        .listen('.content:updated', (content: ContentResource) => {
          const contentTree =
            (queryClient.getQueryData(queryKeys.contentMenu(spaceId).all()) as Record<
              string,
              FlatContentMenuItem
            >) || {}
          const item: FlatContentMenuItem | null = content.i18n_parent_id
            ? contentTree[content.i18n_parent_id]
            : ({
                id: content.id,
                name: content.name,
                slug: content.slug,
                block_id: content.block_id,
                position: content.position,
                pid: content.parent_id,
                type: content.block?.type || 'universal',
                color: content.block?.color || null,
                children: (content?.children_count || 0) > 0,
                icon: content.block?.icon,
                settings: content.settings || {},
                i18n: content?.i18n_translations || [],
                pat: content.published_at,
                uat: content.updated_at,
              } as FlatContentMenuItem)

          if (!item) return
          const targetId = content.i18n_parent_id ?? content.id
          const newContentTree = { ...contentTree }
          newContentTree[targetId] = item
          queryClient.setQueryData(queryKeys.contentMenu(spaceId).all(), newContentTree)
        })
    } catch {
      /** */
    }
  }

  onMounted(() => {
    setupEcho()
  })

  return {
    // Queries
    useContentMenuQuery,

    // Helpers
    findItemById,
    getRootItems,
    getChildren,
    buildBreadcrumbs,
  }
}
