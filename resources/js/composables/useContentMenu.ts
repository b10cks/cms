// src/composables/useContentMenu.ts
import { useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import { isClient } from '~/lib/env'
import type { ContentResource } from '~/types/contents'

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

  const getRootItems = (
    menuData: Record<string, FlatContentMenuItem> | undefined
  ): FlatContentMenuItem[] => {
    if (!menuData) return []
    const compareByPosition = (a: FlatContentMenuItem, b: FlatContentMenuItem) =>
      (a.position ?? 0) - (b.position ?? 0) ||
      (a.name || '').localeCompare(b.name || '') ||
      a.id.localeCompare(b.id)

    return [
      ...Object.values(menuData)
        .filter((item) => !item.pid && item.type !== 'single')
        .sort(compareByPosition),
      ...Object.values(menuData)
        .filter((item) => !item.pid && item.type === 'single')
        .sort(compareByPosition),
    ]
  }

  const getChildren = (
    menuData: Record<string, FlatContentMenuItem> | undefined,
    parentIdRef: MaybeRef<string | null>
  ): FlatContentMenuItem[] => {
    const parentId = unref(parentIdRef)
    if (!menuData) return []
    return Object.values(menuData)
      .filter((item) => item.pid === parentId)
      .sort(
        (a, b) =>
          (a.position ?? 0) - (b.position ?? 0) ||
          (a.name || '').localeCompare(b.name || '') ||
          a.id.localeCompare(b.id)
      )
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
