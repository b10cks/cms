<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { pointerOutsideOfPreview } from '@atlaskit/pragmatic-drag-and-drop/element/pointer-outside-of-preview'
import { setCustomNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/set-custom-native-drag-preview'
import { TreeItem, TreeRoot, type TreeItemToggleEvent } from 'reka-ui'
import { RouterLink } from 'vue-router'
import { toast } from 'vue-sonner'

import CreateContentDialog from '~/components/content/CreateContentDialog.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import DropIndicator from '~/components/ui/DropIndicator.vue'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { SimpleTooltip } from '~/components/ui/tooltip'
import {
  getContentDefaultLanguage,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'

type Edge = 'left'


type ContentTreeDragItem = {
  id: string
}


type ContentTreeDragData = {
  kind: 'content-tree'
  items: ContentTreeDragItem[]
  primaryId: string
}


const CONTENT_TREE_DRAG_KIND = 'content-tree'


const props = defineProps<{
  title?: string
  spaceId: string
}>()


const route = useRoute()
const router = useRouter()


const { alert } = useAlertDialog()
const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(computed(() => props.spaceId))
const { useSpacesQuery } = useSpaces()
const { data: spaces } = useSpacesQuery({})
const { useContentMenuQuery, getChildren, getRootItems } = useContentMenu(props.spaceId)
const { useUpdateContentMutation, useDeleteContentMutation, useMoveContentMutation } = useContent(
  props.spaceId
)


const { mutate: updateContent } = useUpdateContentMutation()
const { mutate: deleteContent } = useDeleteContentMutation()
const { mutateAsync: moveContent } = useMoveContentMutation()


const { settings } = useSpaceSettings(props.spaceId)


const { isLoading, error, data } = useContentMenuQuery()
const rootItems = computed(() => getRootItems(data.value) || [])


const { getUsersForContent } = useContentMenuPresence(props.spaceId)


const selectedItemId = ref<string | null>(null)
const selectedItemIds = ref<string[]>([])
const currentlyEditingId = ref<string | null>(null)
const showCreateDialog = ref(false)
const createParentId = ref<string | null>(null)
const activeDropTargetId = ref<string | null>(null)
const activeDropEdge = ref<Edge | null>(null)
const rootDropMode = ref<'root' | 'root-top' | null>(null)
const isDragging = ref(false)
const dragSelectionSnapshot = ref<string[] | null>(null)


const treeContainerRef = ref<HTMLElement | null>(null)
const rootDropZoneRef = ref<HTMLElement | null>(null)
const itemCleanupMap = new Map<string, () => void>()


const selectedSpace = computed(() => {
  return spaces.value?.find((space) => space.id === props.spaceId) || null
})


const flatItems = computed(() => {
  return data.value ? Object.values(data.value) : []
})


const itemIndexMap = computed(() => {
  return new Map(flatItems.value.map((item, index) => [item.id, index]))
})


const selectedItemsSet = computed(() => new Set(selectedItemIds.value))


const childIdMap = computed(() => {
  const map = new Map<string | null, string[]>()

  map.set(
    null,
    rootItems.value.map((item) => item.id)
  )

  flatItems.value.forEach((item) => {
    const parentId = item.pid ?? null
    if (!map.has(parentId)) {
      map.set(parentId, [])
    }
  })

  for (const parentId of map.keys()) {
    if (parentId === null) {
      map.set(
        null,
        rootItems.value.map((item) => item.id)
      )
      continue
    }

    map.set(
      parentId,
      getChildren(data.value, parentId).map((item) => item.id)
    )
  }

  return map
})


const descendantIdsMap = computed(() => {
  const map = new Map<string, Set<string>>()

  const collect = (id: string): Set<string> => {
    if (map.has(id)) {
      return map.get(id)!
    }

    const descendants = new Set<string>()
    const children = childIdMap.value.get(id) || []

    for (const childId of children) {
      descendants.add(childId)
      const childDescendants = collect(childId)
      childDescendants.forEach((descendantId) => descendants.add(descendantId))
    }

    map.set(id, descendants)
    return descendants
  }

  flatItems.value.forEach((item) => {
    collect(item.id)
  })

  return map
})


const isItemSelected = (id: string) => selectedItemsSet.value.has(id)


const clearDropState = () => {
  activeDropTargetId.value = null
  activeDropEdge.value = null
  rootDropMode.value = null
}


const restoreDragSelection = () => {
  if (dragSelectionSnapshot.value) {
    selectedItemIds.value = [...dragSelectionSnapshot.value]
    selectedItemId.value = dragSelectionSnapshot.value[0] ?? null
  }


  dragSelectionSnapshot.value = null
}


const finishDragState = () => {
  isDragging.value = false
  dragSelectionSnapshot.value = null
  clearDropState()
}


function createContentTreeDragData(
  items: ContentTreeDragItem[],
  primaryId: string
): Record<string, unknown> {
  return {
    kind: CONTENT_TREE_DRAG_KIND,
    items,
    primaryId,
  }
}


function isContentTreeDragData(
  value: Record<string, unknown> | null | undefined
): value is ContentTreeDragData {
  return value?.kind === CONTENT_TREE_DRAG_KIND
}


function getContentTreeDragItems(
  value: Record<string, unknown> | null | undefined
): ContentTreeDragItem[] {
  if (!isContentTreeDragData(value) || !Array.isArray(value.items)) {
    return []
  }


  return value.items.flatMap((item) => {
    if (item && typeof item === 'object' && 'id' in item && typeof item.id === 'string') {
      return [{ id: item.id }]
    }

    return []
  })
}


function attachClosestEdge(
  value: Record<string, unknown>,
  options: {
    input: { clientX: number; clientY: number }
    element: HTMLElement
    allowedEdges: Edge[]
  }
) {
  const rect = options.element.getBoundingClientRect()


  const distances = options.allowedEdges.map((edge) => ({
    edge,
    value: Math.abs(options.input.clientX - rect.left),
  }))


  const closest = distances.sort((a, b) => a.value - b.value)[0]


  return {
    ...value,
    closestEdge: closest?.edge ?? null,
  }
}


function extractClosestEdge(value: Record<string, unknown>): Edge | null {
  if ('closestEdge' in value && value.closestEdge === 'left') {
    return value.closestEdge
  }


  return null
}


function setContentTreeDragPreview({
  nativeSetDragImage,
  count,
  title,
}: {
  nativeSetDragImage: ((image: Element, x: number, y: number) => void) | null
  count: number
  title: string
}) {
  if (!nativeSetDragImage) {
    return
  }


  setCustomNativeDragPreview({
    nativeSetDragImage,
    getOffset: pointerOutsideOfPreview({
      x: '12px',
      y: '10px',
    }),
    render({ container }) {
      const preview = document.createElement('div')
      const label = document.createElement('div')
      const badge = document.createElement('div')

      preview.style.display = 'inline-flex'
      preview.style.alignItems = 'center'
      preview.style.gap = '8px'
      preview.style.maxWidth = '220px'
      preview.style.padding = '8px 10px'
      preview.style.borderRadius = '10px'
      preview.style.border = '1px solid rgba(148, 163, 184, 0.35)'
      preview.style.background = 'rgba(15, 23, 42, 0.94)'
      preview.style.boxShadow = '0 12px 24px rgba(15, 23, 42, 0.18)'
      preview.style.color = '#f8fafc'
      preview.style.fontSize = '12px'
      preview.style.fontWeight = '600'
      preview.style.lineHeight = '16px'

      label.textContent = title
      label.style.minWidth = '0'
      label.style.overflow = 'hidden'
      label.style.textOverflow = 'ellipsis'
      label.style.whiteSpace = 'nowrap'

      preview.appendChild(label)

      if (count > 1) {
        badge.textContent = String(count)
        badge.style.flexShrink = '0'
        badge.style.padding = '2px 6px'
        badge.style.borderRadius = '999px'
        badge.style.background = 'rgba(248, 250, 252, 0.14)'
        badge.style.fontSize = '11px'
        badge.style.fontWeight = '700'
        preview.appendChild(badge)
      }

      container.appendChild(preview)

      return () => {
        preview.remove()
      }
    },
  })
}


const resolveElement = (value: Element | { $el?: Element } | null): HTMLElement | null => {
  if (value instanceof HTMLElement) {
    return value
  }


  if (value && '$el' in value && value.$el instanceof HTMLElement) {
    return value.$el
  }


  return null
}


function handleRename(newName: string, contentId: string) {
  if (contentId && newName) {
    updateContent({ id: contentId, payload: { name: newName } })
  }
  currentlyEditingId.value = null
}


function handleEditStart(contentId: string) {
  currentlyEditingId.value = contentId
}


function handleEditCancel() {
  currentlyEditingId.value = null
}


const buildLink = (contentId: string, item?: FlatContentMenuItem) => {
  const menuLanguages = item?.i18n?.map((translation) => translation.language_iso) || []
  const defaultLanguage = getContentDefaultLanguage(
    currentSpace.value?.settings.default_language,
    menuLanguages.map((languageIso) => ({
      language_iso: languageIso,
      label: languageIso,
      exists: true,
      content_id: null,
      is_default: languageIso === currentSpace.value?.settings.default_language,
      is_current: false,
      status: 'draft' as const,
      published_at: null,
      fallback_language: null,
    })),
    menuLanguages[0]
  )


  const currentLanguage =
    typeof route.query.lang === 'string' && route.query.lang.length > 0
      ? route.query.lang
      : undefined


  const targetLanguage = resolveContentLanguage(
    currentLanguage,
    defaultLanguage,
    menuLanguages.map((languageIso) => ({
      language_iso: languageIso,
      label: languageIso,
      exists: true,
      content_id: null,
      is_default: languageIso === defaultLanguage,
      is_current: false,
      status: 'draft' as const,
      published_at: null,
      fallback_language: null,
    })),
    defaultLanguage
  )


  const name =
    route.name != 'space-content-index'
      ? resolveContentRouteName(
          route.name as string | undefined,
          undefined,
          targetLanguage,
          defaultLanguage
        )
      : resolveContentRouteName(
          'space-content-contentId',
          undefined,
          targetLanguage,
          defaultLanguage
        )


  return {
    name,
    query: withContentLanguageQuery(route.query, targetLanguage, defaultLanguage),
    hash: '',
    params: {
      space: route.params.space,
      contentId,
    },
  }
}


const handleToggle = (event: TreeItemToggleEvent<FlatContentMenuItem> | Event) => {
  if ('detail' in event && event.detail?.originalEvent instanceof PointerEvent) {
    event.preventDefault()
    return
  }


  if (event instanceof PointerEvent) {
    event.preventDefault()
  }
}


const toggleExpanded = (contentId: string) => {
  const expanded = settings.value.content.expanded || []
  const index = expanded.indexOf(contentId)


  if (index > -1) {
    settings.value.content.expanded = expanded.filter((id) => id !== contentId)
    return
  }


  settings.value.content.expanded = [...expanded, contentId]
}


const setCurrentItemFromRoute = () => {
  if (route.params.contentId) {
    const contentId = route.params.contentId as string
    selectedItemId.value = contentId


    if (!selectedItemsSet.value.has(contentId)) {
      selectedItemIds.value = [contentId]
    }
  }
}


watch(
  () => route.params.contentId,
  () => {
    setCurrentItemFromRoute()
  },
  { immediate: true }
)


onMounted(() => {
  setCurrentItemFromRoute()
})


const initCreate = (parentId: string | null) => {
  createParentId.value = parentId
  showCreateDialog.value = true
}


const initDelete = async (item: { id: string }) => {
  if (!(await alert.confirm('Are you sure you want to delete this item?'))) {
    return
  }


  deleteContent(item.id)
}


function selectSingleItem(id: string) {
  selectedItemId.value = id
  selectedItemIds.value = [id]
}


function toggleItemSelection(id: string) {
  const next = new Set(selectedItemIds.value)


  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }


  selectedItemIds.value = Array.from(next)
  selectedItemId.value = id
}


function handleItemPointerDown(event: MouseEvent, id: string) {
  if (currentlyEditingId.value === id || isDragging.value) {
    return
  }


  if (event.metaKey || event.ctrlKey) {
    event.preventDefault()
    event.stopPropagation()
    toggleItemSelection(id)
    return
  }


  if (!selectedItemsSet.value.has(id) || selectedItemIds.value.length <= 1) {
    selectSingleItem(id)
  }
}


function handleItemNavigate(event: MouseEvent, id: string) {
  if (isDragging.value || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    event.preventDefault()
    event.stopPropagation()
    return
  }


  if (selectedItemId.value !== id) {
    selectSingleItem(id)
  }
}


function getSelectedDragItemsFor(id: string): ContentTreeDragItem[] {
  if (!selectedItemsSet.value.has(id) || selectedItemIds.value.length === 0) {
    return [{ id }]
  }


  return selectedItemIds.value.map((selectedId) => ({ id: selectedId }))
}


function getDragPreviewTitle(item: FlatContentMenuItem, dragItems: ContentTreeDragItem[]) {
  if (dragItems.length > 1) {
    return `${dragItems.length} pages`
  }


  return item.name
}


function canDropItemsOnTarget(dragItems: ContentTreeDragItem[], targetId: string | null) {
  if (!dragItems.length) {
    return false
  }


  const draggedIds = new Set(dragItems.map((item) => item.id))


  if (targetId === null) {
    return true
  }


  if (draggedIds.has(targetId)) {
    return false
  }


  for (const draggedId of draggedIds) {
    const descendants = descendantIdsMap.value.get(draggedId)
    if (descendants?.has(targetId)) {
      return false
    }
  }


  return true
}


async function moveDraggedItemsToTarget(
  dragItems: ContentTreeDragItem[],
  targetId: string | null,
  edge: Edge | null
) {
  if (!dragItems.length) {
    finishDragState()
    return
  }


  const orderedDraggedIds = dragItems
    .map((item) => item.id)
    .filter((id, index, array) => array.indexOf(id) === index)


  if (orderedDraggedIds.length === 1 && targetId === orderedDraggedIds[0]) {
    finishDragState()
    return
  }


  if (targetId === null) {
    try {
      for (const id of orderedDraggedIds) {
        await moveContent({
          id,
          payload: {
            parent_id: null,
          },
        })
      }
    } finally {
      finishDragState()
    }


    return
  }


  const target = data.value?.[targetId]


  if (!target) {
    finishDragState()
    return
  }


  const parentId = target.type === 'single' ? (target.pid ?? null) : target.id


  try {
    for (const id of orderedDraggedIds) {
      await moveContent({
        id,
        payload: {
          parent_id: parentId,
        },
      })
    }
  } finally {
    finishDragState()
  }
}


function registerItemInteractions(item: FlatContentMenuItem, element: HTMLElement) {
  const cleanup = combine(
    draggable({
      element,
      getInitialData: () => {
        const dragItems = getSelectedDragItemsFor(item.id)
        dragSelectionSnapshot.value = [...selectedItemIds.value]
        return createContentTreeDragData(dragItems, item.id)
      },
      onGenerateDragPreview: ({ nativeSetDragImage }) => {
        const dragItems = getSelectedDragItemsFor(item.id)
        setContentTreeDragPreview({
          nativeSetDragImage,
          count: dragItems.length,
          title: getDragPreviewTitle(item, dragItems),
        })
      },
      onDragStart: () => {
        isDragging.value = true
      },
      onDrop: () => {
        finishDragState()
      },
    }),
    dropTargetForElements({
      element,
      canDrop: ({ source }) => {
        const dragItems = getContentTreeDragItems(source.data)
        return canDropItemsOnTarget(dragItems, item.id)
      },
      getData: ({ input }) => {
        return attachClosestEdge(
          {
            id: item.id,
            kind: CONTENT_TREE_DRAG_KIND,
          },
          {
            element,
            input,
            allowedEdges: ['left'],
          }
        )
      },
      getIsSticky: () => true,
      onDragEnter: ({ self, source }) => {
        const dragItems = getContentTreeDragItems(source.data)
        const closestEdge = extractClosestEdge(self.data)

        if (!canDropItemsOnTarget(dragItems, item.id)) {
          activeDropTargetId.value = null
          activeDropEdge.value = null
          return
        }

        if (dragItems.length === 1 && dragItems[0]?.id === item.id) {
          activeDropTargetId.value = null
          activeDropEdge.value = null
          return
        }

        activeDropTargetId.value = item.id
        activeDropEdge.value = closestEdge
        rootDropMode.value = null
      },
      onDrag: ({ self, source }) => {
        const dragItems = getContentTreeDragItems(source.data)
        const closestEdge = extractClosestEdge(self.data)

        if (!canDropItemsOnTarget(dragItems, item.id)) {
          if (activeDropTargetId.value === item.id) {
            activeDropTargetId.value = null
            activeDropEdge.value = null
          }
          return
        }

        if (dragItems.length === 1 && dragItems[0]?.id === item.id) {
          if (activeDropTargetId.value === item.id) {
            activeDropTargetId.value = null
            activeDropEdge.value = null
          }
          return
        }

        if (activeDropTargetId.value !== item.id || activeDropEdge.value !== closestEdge) {
          activeDropTargetId.value = item.id
          activeDropEdge.value = closestEdge
        }

        rootDropMode.value = null
      },
      onDragLeave: () => {
        if (activeDropTargetId.value === item.id) {
          activeDropTargetId.value = null
          activeDropEdge.value = null
        }
      },
      onDrop: async ({ source, self }) => {
        const dragItems = getContentTreeDragItems(source.data)
        const closestEdge = extractClosestEdge(self.data)

        if (!canDropItemsOnTarget(dragItems, item.id)) {
          toast.error('Invalid move')
          clearDropState()
          return
        }

        await moveDraggedItemsToTarget(dragItems, item.id, closestEdge)
      },
    })
  )


  itemCleanupMap.set(item.id, cleanup)
}


const setItemElement = (item: FlatContentMenuItem) => {
  return (value: Element | { $el?: Element } | null) => {
    itemCleanupMap.get(item.id)?.()
    itemCleanupMap.delete(item.id)


    const element = resolveElement(value)
    if (!element) {
      return
    }


    registerItemInteractions(item, element)
  }
}


watch([treeContainerRef, rootDropZoneRef], ([containerElement, rootElement], _, onCleanup) => {
  if (!containerElement || !rootElement) {
    return
  }

  const cleanup = combine(
    dropTargetForElements({
      element: containerElement,
      canDrop: ({ source }) => {
        const dragItems = getContentTreeDragItems(source.data)
        return canDropItemsOnTarget(dragItems, null)
      },
      getIsSticky: () => true,
      onDropTargetChange: ({ location }) => {
        const rootActive = location.current.dropTargets.some(
          (dropTarget) => dropTarget.element === rootElement
        )
        const itemActive = location.current.dropTargets.some(
          (dropTarget) =>
            dropTarget.element !== containerElement && dropTarget.element !== rootElement
        )

        if (itemActive) {
          rootDropMode.value = null
          return
        }

        rootDropMode.value = rootActive ? 'root' : null
      },
    }),
    dropTargetForElements({
      element: rootElement,
      canDrop: ({ source }) => {
        const dragItems = getContentTreeDragItems(source.data)
        return canDropItemsOnTarget(dragItems, null)
      },
      getIsSticky: () => true,
      onDragEnter: () => {
        activeDropTargetId.value = null
        activeDropEdge.value = null
        rootDropMode.value = 'root'
      },
      onDrag: () => {
        activeDropTargetId.value = null
        activeDropEdge.value = null
        rootDropMode.value = 'root'
      },
      onDragLeave: () => {
        if (rootDropMode.value === 'root') {
          rootDropMode.value = null
        }
      },
      onDrop: async ({ source }) => {
        const dragItems = getContentTreeDragItems(source.data)
        await moveDraggedItemsToTarget(dragItems, null, null)
      },
    })
  )

  onCleanup(() => {
    finishDragState()
    cleanup()
  })
})


const handleKeydown = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || !isDragging.value) {
    return
  }


  event.preventDefault()
  event.stopPropagation()
  restoreDragSelection()
  finishDragState()
}


onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})


onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeydown)
  itemCleanupMap.forEach((cleanup) => cleanup())
  itemCleanupMap.clear()
})
</script>

<template>
  <aside ref="treeContainerRef">
    <div
      v-if="isLoading"
      class="flex items-center justify-center py-4"
    >
      <span class="text-sm text-muted">Loading...</span>
    </div>

    <div
      v-else-if="error"
      class="px-2 py-4 text-sm text-destructive"
    >
      {{ error }}
    </div>

    <TreeRoot
      v-slot="{ flattenItems }"
      v-model:expanded="settings.content.expanded"
      class="w-full list-none p-2 select-none"
      :items="rootItems"
      :get-children="(item) => getChildren(data, item.id)"
      :get-key="({ id }) => id"
    >
      <h2
        v-if="title && !isLoading"
        class="px-2 pt-1 pb-3 text-sm font-semibold text-primary"
      >
        {{ title }}
      </h2>

      <div
        v-if="selectedSpace"
        ref="rootDropZoneRef"
        :class="[
          'group relative mb-2 flex w-full items-center gap-2 rounded-md border border-transparent -my-1 px-2 py-1 transition-all duration-150',
          rootDropMode ? 'bg-accent/50 ring-1 ring-info' : '',
        ]"
      >
        <button
          type="button"
          class="min-w-0 flex flex-1 items-center gap-2 text-left"
          @click="
            router.push({ name: 'space-content-index', params: { space: route.params.space } })
          "
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <NuxtImg
                v-if="selectedSpace.icon"
                :src="selectedSpace.icon"
                :alt="selectedSpace.name"
                :width="20"
                :height="20"
                class="size-5 shrink-0 rounded-sm object-cover"
              />
              <Icon
                v-else
                name="lucide:cuboid"
                class="shrink-0 text-muted"
              />
              <span class="truncate font-semibold">{{ selectedSpace.name }}</span>
            </div>
            <SpaceBadge
              v-if="selectedSpace.badge"
              :badge="selectedSpace.badge"
              size="2xs"
            />
          </div>
        </button>

        <div class="ml-auto flex items-center">
          <Button
            variant="ghost"
            size="toolbar"
            @click.stop="initCreate(null)"
          >
            <Icon name="lucide:plus" />
          </Button>
        </div>

        <DropIndicator
          v-if="rootDropMode === 'root'"
          edge="bottom"
          gap="0px"
          label="Move to root"
        />
      </div>

      <TreeItem
        v-for="item in flattenItems"
        :ref="setItemElement(item.value)"
        v-slot="{ isExpanded }"
        :key="item._id"
        :style="{ 'padding-left': `${item.level - 0.5}rem` }"
        v-bind="item.bind"
        :as="RouterLink"
        :to="buildLink(item.value.id, item.value)"
        :class="[
          'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none',
          'transition-colors duration-150 hover:bg-border',
          'cursor-pointer font-semibold',
          item.value.id === selectedItemId ? 'text-primary' : '',
          isItemSelected(item.value.id) ? 'bg-border text-primary' : '',
          activeDropTargetId === item.value.id && activeDropEdge === 'left'
            ? 'bg-accent/50 ring-1 ring-info/30'
            : '',
        ]"
        @pointerdown="handleItemPointerDown($event, item.value.id)"
        @click="handleItemNavigate($event, item.value.id)"
        @toggle="handleToggle"
      >
        <DropIndicator
          v-if="activeDropTargetId === item.value.id && activeDropEdge"
          :edge="activeDropEdge"
          gap="4px"
          inset="6px"
          label="Move into"
        />

        <button
          v-if="item.value.children"
          class="z-10 h-4 w-3 cursor-pointer"
          @click.stop.prevent="toggleExpanded(item.value.id)"
        >
          <Icon
            name="lucide:chevron-right"
            :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
          />
        </button>
        <span
          v-else
          class="size-4"
        />

        <Icon
          :name="`lucide:${item.value.icon}`"
          class="shrink-0"
          :style="{ color: item.value.color }"
        />

        <RenamableTitle
          :name="item.value.name"
          class="w-full truncate text-left"
          @update="handleRename($event, item.value.id)"
          @edit-start="handleEditStart(item.value.id)"
          @cancel="handleEditCancel"
        />

        <div class="ml-auto flex items-center gap-2">
          <AvatarList
            v-if="getUsersForContent(item.value.id).length > 0"
            :users="getUsersForContent(item.value.id)"
            :max="2"
            size="sm"
            class="mr-1"
          />
          <div
            v-if="!item.value.pat"
            class="h-2 w-2 rounded-full bg-text-muted"
            title="Draft"
          />
          <SimpleTooltip
            v-else
            :tooltip="item.value.pat"
          >
            <div class="h-2 w-2 rounded-full bg-success" />
          </SimpleTooltip>
        </div>

        <div
          class="absolute right-6 flex items-center gap-1 overflow-clip bg-border opacity-0 transition-opacity duration-200 group-hover:w-auto group-hover:opacity-100"
        >
          <button
            v-if="item.value.type !== 'single'"
            class="flex transform cursor-pointer items-center hover:text-primary"
            @click.stop.prevent="initCreate(item.value.id)"
          >
            <Icon name="lucide:plus" />
          </button>
          <button
            type="button"
            title="Delete item"
            class="flex transform cursor-pointer items-center hover:text-red-500"
            @click.stop.prevent="initDelete(item.value)"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </TreeItem>
    </TreeRoot>

    <CreateContentDialog
      v-model:open="showCreateDialog"
      :space-id="props.spaceId"
      :parent-id="createParentId"
    />
  </aside>
</template>
