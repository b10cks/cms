// src/composables/useContentMenu.ts
import { useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import { isClient } from '~/lib/env'
import type { ContentChildSortBy } from '~/types/contents'

import { queryKeys } from './useQueryClient'

// Many components (tree, command palette, RTE link/reference pickers) use this
// composable at the same time but share one Echo channel per space. Ref-count
// the subscribers so the channel is joined once and left only when the last
// subscriber unmounts — otherwise every picker mount stacks another listener
// that survives for the whole session.
const contentChannelSubscribers = new Map<string, number>()

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

    // content.{field}: the menu endpoint delivers the extracted value as `sv`
    // for exactly the children that need it. Numeric values compare
    // numerically, everything else as strings; entries without a value last.
    if (sortBy.startsWith('content.')) {
      return (a, b) => {
        const aValue = a.sv ?? null
        const bValue = b.sv ?? null
        if (aValue === null || bValue === null) {
          return Number(aValue === null) - Number(bValue === null) || compareByPosition(a, b)
        }

        const aNumber = typeof aValue === 'number' ? aValue : Number(aValue)
        const bNumber = typeof bValue === 'number' ? bValue : Number(bValue)
        const bothNumeric =
          aValue !== '' && bValue !== '' && Number.isFinite(aNumber) && Number.isFinite(bNumber)

        return (
          direction *
            (bothNumeric ? aNumber - bNumber : String(aValue).localeCompare(String(bValue))) ||
          compareByPosition(a, b)
        )
      }
    }

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

  const setupEcho = (id: string) => {
    try {
      const echo = useEcho()
      if (!echo) return
      echo
        .channel(`spaces.${id}.content`)
        // The payload is already menu-shaped (the full content resource does
        // not fit into a broadcast message), so it drops straight into the tree.
        .listen(
          '.content:updated',
          (broadcast: FlatContentMenuItem & { i18n_parent_id: string | null }) => {
            const contentTree =
              (queryClient.getQueryData(queryKeys.contentMenu(id).all()) as Record<
                string,
                FlatContentMenuItem
              >) || {}

            const { i18n_parent_id: i18nParentId, ...content } = broadcast
            const item: FlatContentMenuItem | undefined = i18nParentId
              ? contentTree[i18nParentId]
              : {
                  ...content,
                  // Carry the previous sort value forward when the parent does
                  // not sort by a content field.
                  sv: content.sv ?? contentTree[content.id]?.sv ?? null,
                }

            if (!item) return
            queryClient.setQueryData(queryKeys.contentMenu(id).all(), {
              ...contentTree,
              [i18nParentId ?? content.id]: item,
            })
          }
        )
    } catch {
      /** */
    }
  }

  const teardownEcho = (id: string) => {
    try {
      useEcho()?.leave(`spaces.${id}.content`)
    } catch {
      /** */
    }
  }

  // Tracks the id this instance holds a subscription for, so mount/unmount and
  // spaceId switches stay balanced against the shared ref-count.
  let subscribedId: string | null = null
  let mounted = false

  const subscribe = (id: string | null) => {
    if (!isClient || subscribedId === id) return

    if (subscribedId) {
      const count = (contentChannelSubscribers.get(subscribedId) ?? 1) - 1
      if (count <= 0) {
        contentChannelSubscribers.delete(subscribedId)
        teardownEcho(subscribedId)
      } else {
        contentChannelSubscribers.set(subscribedId, count)
      }
      subscribedId = null
    }

    if (id) {
      const count = contentChannelSubscribers.get(id) ?? 0
      contentChannelSubscribers.set(id, count + 1)
      if (count === 0) setupEcho(id)
      subscribedId = id
    }
  }

  onMounted(() => {
    mounted = true
    subscribe(toValue(spaceId) || null)
  })

  onUnmounted(() => {
    mounted = false
    subscribe(null)
  })

  watch(
    () => toValue(spaceId),
    (id) => {
      if (mounted) subscribe(id || null)
    }
  )

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
