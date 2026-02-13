// src/composables/useContentMenu.ts
import { useQuery, useQueryClient } from '@tanstack/vue-query'

import type { ContentResource } from '~/types/contents'

import { api } from '~/api'
import { isClient } from '~/lib/env'

import { queryKeys } from './useQueryClient'

export function useContentMenu(spaceId: MaybeRef<string>) {
  const queryClient = useQueryClient()

  // Create a computed API instance that updates when spaceId changes
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  // Query to fetch the content menu
  const useContentMenuQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.contentMenu(spaceId).all()),
      queryFn: async () => {
        const response = await spaceAPI.value.contentMenu.get()
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId)),
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
    return [
      ...Object.values(menuData)
        .filter((item) => !item.pid && item.type !== 'single')
        .sort((a, b) => (a.name || '').localeCompare(b.name || '')),
      ...Object.values(menuData)
        .filter((item) => !item.pid && item.type === 'single')
        .sort((a, b) => (a.name || '').localeCompare(b.name || '')),
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
      .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
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
                pid: content.parent_id,
                children: content?.children_count || 0 > 0,
                icon: content.block?.icon,
                i18n: content?.i18n_translations || [],
                pat: content.published_at,
                uat: content.updated_at,
              } as FlatContentMenuItem)

          if (!item) return
          const newContentTree = { ...contentTree }
          newContentTree[content.id] = item
          queryClient.setQueryData(queryKeys.contentMenu(spaceId).all(), newContentTree)
        })
    } catch (err: unknown) {
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
