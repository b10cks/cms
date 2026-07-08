<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { pointerOutsideOfPreview } from '@atlaskit/pragmatic-drag-and-drop/element/pointer-outside-of-preview'
import { setCustomNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/set-custom-native-drag-preview'
import { useQueryClient } from '@tanstack/vue-query'
import { TreeItem, TreeRoot, type TreeItemToggleEvent } from 'reka-ui'
import type { ComponentPublicInstance } from 'vue'
import { RouterLink, type LocationQueryRaw } from 'vue-router'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import CreateContentDialog from '~/components/content/CreateContentDialog.vue'
import PublishDialog from '~/components/content/PublishDialog.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '~/components/ui/dropdown-menu'
import DropIndicator from '~/components/ui/DropIndicator.vue'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { VerticalScrollArea } from '~/components/ui/scroll-area'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { useContentTreeClipboard } from '~/composables/useContentTreeClipboard'
import { queryKeys } from '~/composables/useQueryClient'
import { normalizeLanguageIso } from '~/lib/content-i18n'
import type {
    ContentTreeActionContext,
    ContentTreeClipboardItem,
    ContentTreeOperationPayload,
    CreateContentPayload,
} from '~/types/contents'

type Edge = 'top' | 'bottom' | 'left'
type MenuOwnerId = string | 'root'

type ContentTreeDragItem = {
  id: string
}

type ContentTreeDragData = {
  kind: 'content-tree'
  items: ContentTreeDragItem[]
  primaryId: string
}

type ContentTreeMenuAction = {
  id: string
  label: string
  icon: string
  disabled?: boolean
  destructive?: boolean
  separatorBefore?: boolean
  onSelect: () => void | Promise<void>
}

type RenamableTitleInstance = InstanceType<typeof RenamableTitle>

const CONTENT_TREE_DRAG_KIND = 'content-tree'

const props = defineProps<{
  title?: string
  spaceId: string
}>()

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const queryClient = useQueryClient()
const { useAccessControl } = useAuthorization()
const { alert } = useAlertDialog()
const { useSpaceQuery } = useSpaces()
const { data: selectedSpace } = useSpaceQuery(props.spaceId)
const { useContentMenuQuery, getChildren, getRootItems } = useContentMenu(props.spaceId)
const { useTreeOperationsMutation, useUpdateContentMutation, usePublishContentMutation, useScheduleContentMutation } = useContent(props.spaceId)
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageContent = computed(() => access.hasAbility('content.manage'))
const canPublishContent = computed(() => access.hasAbility('content.publish'))
// Manual drag-to-reorder is opt-in per space. When disabled, dragging may only
// reparent items (the "into" gesture); before/after reordering is suppressed.
const sortingEnabled = computed(() => !!selectedSpace.value?.settings?.content_sorting)
const { settings } = useSpaceSettings(props.spaceId)
const {
  hasClipboardItem,
  normalizeRootSelection,
  normalizeClipboardItems,
  buildSnapshot,
  canPasteAfter,
  canPasteIn,
  copyItem: copyClipboardItem,
  cutItem: cutClipboardItem,
  clearClipboard,
  getClipboardItem,
} = useContentTreeClipboard()
const { mutateAsync: runTreeOperations } = useTreeOperationsMutation()
const { mutate: updateContent } = useUpdateContentMutation()
const { mutateAsync: publishContent, isPending: isPublishing } = usePublishContentMutation()
const { mutateAsync: scheduleContent, isPending: isScheduling } = useScheduleContentMutation()

const { isLoading, error, data } = useContentMenuQuery()
const rootItems = computed(() => getRootItems(data.value) || [])
const { getUsersForContent } = useContentMenuPresence(props.spaceId)

const selectedItemId = ref<string | null>(null)
const selectedItemIds = ref<string[]>([])
const currentlyEditingId = ref<string | null>(null)
const showCreateDialog = ref(false)
const createParentId = ref<string | null>(null)
const publishDialogOpen = ref(false)
const publishDialogType = ref<'now' | 'schedule'>('now')
const publishDialogItem = ref<FlatContentMenuItem | null>(null)
const activeDropTargetId = ref<string | null>(null)
const activeDropEdge = ref<Edge | null>(null)
const rootDropMode = ref<'root' | null>(null)
const isDragging = ref(false)
const dragSelectionSnapshot = ref<string[] | null>(null)
const openMenuId = ref<MenuOwnerId | null>(null)
const activeClipboardItem = ref<ContentTreeClipboardItem | null>(null)
const activeMenuAnchor = ref<HTMLElement | null>(null)
const activeMenuTrigger = ref<HTMLElement | null>(null)
let lastDragEndedAt = 0

const treeContainerRef = ref<HTMLElement | null>(null)
const rootDropZoneRef = ref<HTMLElement | null>(null)
const itemCleanupMap = new Map<string, () => void>()
const titleRefMap = new Map<string, RenamableTitleInstance | null>()

const flatItems = computed(() => {
  const menuData = data.value
  if (!menuData) {
    return []
  }

  const orderedItems: FlatContentMenuItem[] = []
  const visit = (item: FlatContentMenuItem) => {
    orderedItems.push(item)
    getChildren(menuData, item.id).forEach(visit)
  }

  getRootItems(menuData).forEach(visit)
  return orderedItems
})
const itemIndexMap = computed(() => new Map(flatItems.value.map((item, index) => [item.id, index])))
const parentIdMap = computed(
  () => new Map(flatItems.value.map((item) => [item.id, item.pid ?? null]))
)
const selectedItemsSet = computed(() => new Set(selectedItemIds.value))

const clipboardValidationContext = computed(() => ({
  itemsById: new Map(
    flatItems.value.map((item) => [
      item.id,
      {
        id: item.id,
        pid: item.pid,
        block_id: item.block_id,
        type: item.type,
      },
    ])
  ),
}))

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

// 1-based depth per item (root items = 1), matching the rendered row indentation.
const levelMap = computed(() => {
  const map = new Map<string, number>()
  const parents = parentIdMap.value

  const depthOf = (id: string): number => {
    const cached = map.get(id)
    if (cached !== undefined) {
      return cached
    }

    const parentId = parents.get(id) ?? null
    const level = parentId === null ? 1 : depthOf(parentId) + 1
    map.set(id, level)
    return level
  }

  flatItems.value.forEach((item) => depthOf(item.id))
  return map
})

const expandedSet = computed(() => new Set(settings.value.content.expanded || []))
const hasVisibleChildren = (id: string): boolean => (childIdMap.value.get(id)?.length ?? 0) > 0
const isRowExpanded = (id: string): boolean => expandedSet.value.has(id) && hasVisibleChildren(id)

// The deepest, last visible row belonging to `id`'s subtree — i.e. the row a new
// sibling placed *after* `id` will visually appear under. Equals `id` itself when
// it is collapsed or a leaf.
const lastVisibleDescendant = (id: string): string => {
  let current = id
  while (isRowExpanded(current)) {
    const children = childIdMap.value.get(current) || []
    const last = children[children.length - 1]
    if (!last) {
      break
    }
    current = last
  }
  return current
}

const effectiveEnvironment = computed(() => {
  const userEnv = settings.value.content.environment
  if (userEnv) {
    return userEnv
  }

  const defaultName = selectedSpace.value?.settings.default_environment
  if (!defaultName) {
    return null
  }

  return (
    selectedSpace.value?.settings.environments?.find(
      (environment: { name: string }) => environment.name === defaultName
    ) ?? null
  )
})

const canOpenRootMenu = computed(
  () =>
    canManageContent.value &&
    hasClipboardItem.value &&
    canPasteIn(activeClipboardItem.value, props.spaceId, null, clipboardValidationContext.value)
)

const isItemSelected = (id: string) => selectedItemsSet.value.has(id)

const getNormalizedIds = (ids: string[]) => {
  return normalizeRootSelection(ids, parentIdMap.value, itemIndexMap.value)
}

const createClipboardSnapshot = (itemIds: string[]) => {
  return buildSnapshot(itemIds, {
    itemsById: clipboardValidationContext.value.itemsById,
    descendantsById: descendantIdsMap.value,
    treeOrderById: itemIndexMap.value,
  })
}

const syncActiveClipboardItem = async () => {
  activeClipboardItem.value = await getClipboardItem()
}

const getActiveClipboardItem = () => activeClipboardItem.value

const cutItemIds = computed(() => {
  if (!activeClipboardItem.value?._isCut || activeClipboardItem.value.spaceId !== props.spaceId) {
    return new Set<string>()
  }

  return new Set(normalizeClipboardItems(activeClipboardItem.value).map((item) => item.id))
})

const isCutItem = (id: string) => cutItemIds.value.has(id)

const resolveMenuAnchorElement = (value: EventTarget | null) => {
  return value instanceof HTMLElement ? value : null
}

const resolveMenuContext = (targetId: string | null): ContentTreeActionContext => {
  const usesSelection =
    !!targetId && selectedItemsSet.value.has(targetId) && selectedItemIds.value.length > 0
  const candidateIds = usesSelection ? selectedItemIds.value : targetId ? [targetId] : []

  return {
    target_id: targetId,
    selected_ids: [...selectedItemIds.value],
    resolved_ids: getNormalizedIds(candidateIds),
    uses_selection: usesSelection,
  }
}

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
  if (isDragging.value) {
    lastDragEndedAt = Date.now()
  }

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

// Predictable vertical-region hitbox. The pointer's vertical position within the
// row maps to an intent: top band → before, bottom band → after, and (for
// containers) the middle band → nest. Using vertical bands instead of mixing
// horizontal/vertical distances makes the zones stable and easy to hit.
//   'top'    → reorder before the row
//   'bottom' → reorder after the row (or first child when the row is expanded)
//   'left'   → nest into the row
function computeDropEdge(
  element: HTMLElement,
  input: { clientY: number },
  item: FlatContentMenuItem
): Edge {
  // Sorting disabled: only reparenting (nesting) is permitted, never reordering.
  if (!sortingEnabled.value) {
    return 'left'
  }

  const rect = element.getBoundingClientRect()
  const ratio = rect.height > 0 ? (input.clientY - rect.top) / rect.height : 0.5
  const isContainer = item.type !== 'single'

  if (isContainer) {
    if (ratio <= 0.3) {
      return 'top'
    }
    if (ratio >= 0.7) {
      return 'bottom'
    }
    return 'left'
  }

  return ratio < 0.5 ? 'top' : 'bottom'
}

function extractClosestEdge(value: Record<string, unknown>): Edge | null {
  if (
    'closestEdge' in value &&
    (value.closestEdge === 'top' ||
      value.closestEdge === 'bottom' ||
      value.closestEdge === 'left')
  ) {
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

const setTitleRef = (itemId: string) => (value: Element | ComponentPublicInstance | null) => {
  if (value && typeof value === 'object' && 'startEdit' in value) {
    titleRefMap.set(itemId, value as unknown as RenamableTitleInstance)
    return
  }

  titleRefMap.set(itemId, null)
}

const openMenu = async (menuId: MenuOwnerId, anchor: HTMLElement | null = null) => {
  await syncActiveClipboardItem()
  activeMenuAnchor.value = anchor
  activeMenuTrigger.value = anchor
  openMenuId.value = menuId
}

const closeMenu = () => {
  openMenuId.value = null
  activeMenuAnchor.value = null
  activeMenuTrigger.value?.focus({ preventScroll: true })
  activeMenuTrigger.value = null
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

const buildLink = (contentId: string) => {
  const currentLanguage =
    typeof route.query.lang === 'string' ? normalizeLanguageIso(route.query.lang) : undefined
  const query: LocationQueryRaw = {
    ...route.query,
  }

  if (currentLanguage) {
    query.lang = currentLanguage
  } else {
    delete query.lang
  }

  const name =
    currentLanguage && route.name === 'space-content-contentId-localization'
      ? 'space-content-contentId-localization'
      : route.name === 'space-content-contentId-versions'
        ? 'space-content-contentId-versions'
        : 'space-content-contentId'

  return {
    name,
    query,
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

watch(hasClipboardItem, async (value) => {
  if (!value) {
    activeClipboardItem.value = null
    return
  }

  await syncActiveClipboardItem()
})

onMounted(() => {
  setCurrentItemFromRoute()
  void syncActiveClipboardItem()
})

const selectSingleItem = (id: string) => {
  dragSelectionSnapshot.value = null
  selectedItemId.value = id
  selectedItemIds.value = [id]
}

const toggleItemSelection = (id: string) => {
  dragSelectionSnapshot.value = null
  const next = new Set(selectedItemIds.value)

  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }

  selectedItemIds.value = Array.from(next)
  selectedItemId.value = id
}

const resolveLocaleTargetId = (item: FlatContentMenuItem) => {
  const currentLanguage =
    typeof route.query.lang === 'string' ? normalizeLanguageIso(route.query.lang) : null

  if (!currentLanguage || currentLanguage === selectedSpace.value?.settings.default_language) {
    return item.id
  }

  return (
    item.i18n.find(
      (translation) => normalizeLanguageIso(translation.language_iso) === currentLanguage
    )?.id ?? item.id
  )
}

const openViewTarget = async (item: FlatContentMenuItem) => {
  const environment = effectiveEnvironment.value

  if (!environment?.url) {
    toast.error(t('labels.contentTree.errors.previewUnavailable') as string)
    return
  }

  const contentId = resolveLocaleTargetId(item)

  try {
    const content = await queryClient.fetchQuery({
      queryKey: queryKeys.contents(props.spaceId).detail(contentId),
      queryFn: async () => {
        const response = await api.forSpace(props.spaceId).contents.get(contentId)
        return response.data
      },
    })

    const slugStrategy = selectedSpace.value?.settings.slug_strategy
    const needsPrepend =
      slugStrategy === 'always_prepend' ||
      (slugStrategy === 'prepend_translations' &&
        content.language_iso !== selectedSpace.value?.settings.default_language)
    const prefix = needsPrepend ? `/${content.language_iso}` : ''
    const url = environment.url.replace(/\/$/, '')

    window.open(`${url}${prefix}${content.full_slug}`, '_blank', 'noopener,noreferrer')
  } catch {
    toast.error(t('labels.contentTree.errors.previewFailed') as string)
  }
}

const executeTreeOperations = async (operations: ContentTreeOperationPayload[]) => {
  await runTreeOperations({
    operations,
  })
}

const handleCreateSubmit = async (payload: CreateContentPayload) => {
  await executeTreeOperations([
    {
      type: 'create',
      temp_id: crypto.randomUUID(),
      parent_id: payload.parent_id ?? null,
      block_id: payload.block_id,
      name: payload.name,
      slug: payload.slug,
      content: payload.content,
      settings: payload.settings,
    },
  ])
}

const initCreate = (parentId: string | null) => {
  createParentId.value = parentId
  showCreateDialog.value = true
}

const confirmDelete = async (count: number) => {
  const single = count === 1

  return alert.confirm(
    single
      ? (t('labels.contentTree.confirmations.deleteSingle') as string)
      : (t('labels.contentTree.confirmations.deleteMany', { count }) as string),
    {
      title: t('labels.contentTree.confirmations.deleteTitle') as string,
      confirmLabel: t('labels.contentTree.actions.delete') as string,
      variant: 'destructive',
    }
  )
}

const executeDelete = async (context: ContentTreeActionContext) => {
  if (context.resolved_ids.length === 0) {
    return
  }

  if (!(await confirmDelete(context.resolved_ids.length))) {
    return
  }

  await executeTreeOperations([
    {
      type: 'delete',
      ids: context.resolved_ids,
    },
  ])

  selectedItemId.value = null
  selectedItemIds.value = []
}

const copyToClipboard = async (context: ContentTreeActionContext) => {
  if (context.resolved_ids.length === 0) {
    return
  }

  const snapshot = createClipboardSnapshot(context.resolved_ids)
  await copyClipboardItem(snapshot.length === 1 ? snapshot[0] : snapshot, props.spaceId)
  await syncActiveClipboardItem()
}

const cutToClipboard = async (context: ContentTreeActionContext) => {
  if (context.resolved_ids.length === 0) {
    return
  }

  const snapshot = createClipboardSnapshot(context.resolved_ids)
  await cutClipboardItem(snapshot.length === 1 ? snapshot[0] : snapshot, props.spaceId)
  await syncActiveClipboardItem()
}

const isCutPasteNoOp = (
  activeClipboardItem: ContentTreeClipboardItem | null,
  target: FlatContentMenuItem | null,
  mode: 'in' | 'after'
) => {
  if (!activeClipboardItem?._isCut) {
    return false
  }

  const clipboardItems = normalizeClipboardItems(activeClipboardItem)
  if (clipboardItems.length === 0) {
    return false
  }

  const targetParentId = mode === 'in' ? (target?.id ?? null) : (target?.pid ?? null)
  const anchorId = mode === 'after' ? (target?.id ?? null) : null
  const currentOrder = childIdMap.value.get(targetParentId) || []
  const clipboardIds = clipboardItems.map((item) => item.id)
  const movingIds = new Set(clipboardIds)

  if (clipboardItems.some((item) => item.parent_id !== targetParentId)) {
    return false
  }

  const remaining = currentOrder.filter((id) => !movingIds.has(id))
  const insertIndex = anchorId ? Math.max(remaining.indexOf(anchorId) + 1, 0) : remaining.length
  const nextOrder = [
    ...remaining.slice(0, insertIndex),
    ...clipboardIds,
    ...remaining.slice(insertIndex),
  ]

  return (
    currentOrder.length === nextOrder.length &&
    currentOrder.every((id, index) => id === nextOrder[index])
  )
}

const pasteClipboard = async (target: FlatContentMenuItem | null, mode: 'in' | 'after') => {
  const clipboardItem = getActiveClipboardItem()
  if (!clipboardItem) {
    return
  }

  if (
    mode === 'in' &&
    !canPasteIn(clipboardItem, props.spaceId, target, clipboardValidationContext.value)
  ) {
    return
  }

  if (
    mode === 'after' &&
    !canPasteAfter(clipboardItem, props.spaceId, target, clipboardValidationContext.value)
  ) {
    return
  }

  if (isCutPasteNoOp(clipboardItem, target, mode)) {
    return
  }

  const clipboardIds = normalizeClipboardItems(clipboardItem).map((item) => item.id)
  if (clipboardIds.length === 0) {
    return
  }

  const parentId = mode === 'in' ? (target?.id ?? null) : (target?.pid ?? null)
  const afterId = mode === 'after' ? (target?.id ?? null) : null
  const operation: ContentTreeOperationPayload = clipboardItem._isCut
    ? {
        type: 'move',
        ids: clipboardIds,
        parent_id: parentId,
        after_id: afterId,
      }
    : {
        type: 'duplicate',
        ids: clipboardIds,
        parent_id: parentId,
        after_id: afterId,
      }

  await executeTreeOperations([operation])

  if (clipboardItem._isCut) {
    await clearClipboard()
    activeClipboardItem.value = null
  }
}

const openRename = async (itemId: string) => {
  selectSingleItem(itemId)
  await router.push(buildLink(itemId))
  await nextTick()
  titleRefMap.get(itemId)?.startEdit?.()
}

const openEdit = async (itemId: string) => {
  selectSingleItem(itemId)
  await router.push(buildLink(itemId))
}

const isPublishingAction = computed(() => isPublishing.value || isScheduling.value)

const executePublish = async (item: FlatContentMenuItem) => {
  await publishContent({ id: item.id, payload: {} })
}

const openPublishWithMessage = (item: FlatContentMenuItem) => {
  publishDialogItem.value = item
  publishDialogType.value = 'now'
  publishDialogOpen.value = true
}

const openSchedulePublish = (item: FlatContentMenuItem) => {
  publishDialogItem.value = item
  publishDialogType.value = 'schedule'
  publishDialogOpen.value = true
}

const handlePublishDialog = async (payload: { message?: string; published_at?: string | null }) => {
  if (!publishDialogItem.value) return
  await publishContent({ id: publishDialogItem.value.id, payload })
  publishDialogOpen.value = false
  publishDialogItem.value = null
}

const handleScheduleDialog = async (payload: { message?: string; scheduled_at?: string | null }) => {
  if (!publishDialogItem.value) return
  await scheduleContent({ id: publishDialogItem.value.id, payload })
  publishDialogOpen.value = false
  publishDialogItem.value = null
}

const buildItemMenuActions = (item: FlatContentMenuItem): ContentTreeMenuAction[] => {
  const context = resolveMenuContext(item.id)
  const activeClipboardItem = getActiveClipboardItem()
  const canPasteIntoTarget = canPasteIn(
    activeClipboardItem,
    props.spaceId,
    item,
    clipboardValidationContext.value
  )
  const canPasteAfterTarget = canPasteAfter(
    activeClipboardItem,
    props.spaceId,
    item,
    clipboardValidationContext.value
  )

  return [
    {
      id: 'view',
      label: t('labels.contentTree.actions.view') as string,
      icon: 'eye',
      onSelect: () => openViewTarget(item),
    },
    {
      id: 'edit',
      label: t('labels.contentTree.actions.edit') as string,
      icon: 'pencil',
      onSelect: () => openEdit(item.id),
    },
    {
      id: 'publish',
      label: t('labels.contentTree.actions.publish') as string,
      icon: 'send',
      separatorBefore: true,
      disabled: !canPublishContent.value || isPublishingAction.value || !!item.pat,
      onSelect: () => executePublish(item),
    },
    {
      id: 'publish-with-message',
      label: t('labels.contentTree.actions.publishWithMessage') as string,
      icon: 'message-square-share',
      disabled: !canPublishContent.value || isPublishingAction.value || !!item.pat,
      onSelect: () => openPublishWithMessage(item),
    },
    {
      id: 'schedule',
      label: t('labels.contentTree.actions.schedule') as string,
      icon: 'clock-fading',
      disabled: !canPublishContent.value || isPublishingAction.value || !!item.pat,
      onSelect: () => openSchedulePublish(item),
    },
    {
      id: 'rename',
      label: t('labels.contentTree.actions.rename') as string,
      icon: 'text-cursor-input',
      separatorBefore: true,
      disabled: !canManageContent.value,
      onSelect: () => openRename(item.id),
    },
    {
      id: 'new-sub-item',
      label: t('labels.contentTree.actions.newSubItem') as string,
      icon: 'folder-plus',
      disabled: !canManageContent.value || item.type === 'single',
      onSelect: () => initCreate(item.id),
    },
    {
      id: 'copy',
      label: t('labels.contentTree.actions.copy') as string,
      icon: 'copy',
      separatorBefore: true,
      disabled: !canManageContent.value || context.resolved_ids.length === 0,
      onSelect: () => copyToClipboard(context),
    },
    {
      id: 'cut',
      label: t('labels.contentTree.actions.cut') as string,
      icon: 'scissors',
      disabled: !canManageContent.value || context.resolved_ids.length === 0,
      onSelect: () => cutToClipboard(context),
    },
    {
      id: 'paste-in',
      label: t('labels.contentTree.actions.pasteIn') as string,
      icon: 'clipboard-paste',
      disabled: !canManageContent.value || !canPasteIntoTarget,
      onSelect: () => pasteClipboard(item, 'in'),
    },
    {
      id: 'paste-after',
      label: t('labels.contentTree.actions.pasteAfter') as string,
      icon: 'between-horizontal-start',
      disabled: !canManageContent.value || !canPasteAfterTarget,
      onSelect: () => pasteClipboard(item, 'after'),
    },
    {
      id: 'delete',
      label: t('labels.contentTree.actions.delete') as string,
      icon: 'trash-2',
      disabled: !canManageContent.value || context.resolved_ids.length === 0,
      destructive: true,
      onSelect: () => executeDelete(context),
    },
  ]
}

const buildRootMenuActions = (): ContentTreeMenuAction[] => {
  if (!canOpenRootMenu.value) {
    return []
  }

  return [
    {
      id: 'paste-root',
      label: t('labels.contentTree.actions.pasteIn') as string,
      icon: 'clipboard-paste',
      onSelect: () => pasteClipboard(null, 'in'),
    },
  ]
}

const activeMenuActions = computed(() => {
  if (openMenuId.value === 'root') {
    return buildRootMenuActions()
  }

  if (!openMenuId.value) {
    return []
  }

  const item = data.value?.[openMenuId.value]
  if (!item) {
    return []
  }

  return buildItemMenuActions(item)
})

watch(activeMenuActions, (actions) => {
  if (openMenuId.value && actions.length === 0) {
    closeMenu()
  }
})

const clearTreeClipboard = async () => {
  await clearClipboard()
  activeClipboardItem.value = null
  closeMenu()
}

const handleActionSelect = async (action: ContentTreeMenuAction) => {
  if (action.disabled) {
    return
  }

  closeMenu()
  await action.onSelect()
}

const handleMenuOpenChange = (value: boolean) => {
  if (!value) {
    closeMenu()
  }
}

const handleItemPointerDown = (event: MouseEvent, id: string) => {
  if (event.button !== 0 || currentlyEditingId.value === id || isDragging.value) {
    return
  }

  if (event.metaKey || event.ctrlKey) {
    event.preventDefault()
    event.stopPropagation()
    toggleItemSelection(id)
    return
  }

  if (selectedItemsSet.value.has(id) && selectedItemIds.value.length > 1) {
    event.stopPropagation()
    dragSelectionSnapshot.value = [...selectedItemIds.value]
    return
  }

  dragSelectionSnapshot.value = null

  if (!selectedItemsSet.value.has(id) || selectedItemIds.value.length <= 1) {
    selectSingleItem(id)
  }
}

const handleItemContextMenu = (event: MouseEvent, id: string) => {
  event.preventDefault()
  event.stopPropagation()

  if (!selectedItemsSet.value.has(id) || selectedItemIds.value.length === 0) {
    selectSingleItem(id)
  } else {
    selectedItemId.value = id
  }

  void openMenu(id, resolveMenuAnchorElement(event.currentTarget))
}

const handleRootContextMenu = (event: MouseEvent) => {
  if (!canOpenRootMenu.value) {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  void openMenu('root', resolveMenuAnchorElement(event.currentTarget))
}

const handleItemMenuTrigger = (event: MouseEvent, id: string) => {
  event.preventDefault()
  event.stopPropagation()
  void openMenu(id, resolveMenuAnchorElement(event.currentTarget))
}

const handleRootMenuTrigger = (event: MouseEvent) => {
  event.preventDefault()
  event.stopPropagation()
  void openMenu('root', resolveMenuAnchorElement(event.currentTarget))
}

const handleItemNavigate = (event: MouseEvent, id: string) => {
  if (Date.now() - lastDragEndedAt < 250) {
    event.preventDefault()
    event.stopPropagation()
    return
  }

  if (isDragging.value || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    event.preventDefault()
    event.stopPropagation()
    return
  }

  if (selectedItemId.value !== id) {
    selectSingleItem(id)
  }
}

const getSelectedDragItemsFor = (id: string): ContentTreeDragItem[] => {
  const rawSelection =
    dragSelectionSnapshot.value && dragSelectionSnapshot.value.includes(id)
      ? dragSelectionSnapshot.value
      : selectedItemIds.value

  if (!rawSelection.includes(id)) {
    return [{ id }]
  }

  const normalizedIds = getNormalizedIds(rawSelection)
  if (normalizedIds.length === 0) {
    return [{ id }]
  }

  return normalizedIds.map((selectedId) => ({ id: selectedId }))
}

const getDragPreviewTitle = (item: FlatContentMenuItem, dragItems: ContentTreeDragItem[]) => {
  if (dragItems.length > 1) {
    return t('labels.contentTree.drag.multiple', { count: dragItems.length }) as string
  }

  return item.name
}

const canDropItemsOnTarget = (dragItems: ContentTreeDragItem[], targetId: string | null) => {
  if (!dragItems.length) {
    return false
  }

  const draggedIds = new Set(getNormalizedIds(dragItems.map((item) => item.id)))

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

const isSelfDrop = (dragItems: ContentTreeDragItem[], targetId: string | null) => {
  if (!targetId) {
    return false
  }

  return getNormalizedIds(dragItems.map((item) => item.id)).includes(targetId)
}

// A fully-resolved drop: the concrete destination (parent + index) plus exactly
// where the indicator should render. Both the live indicator and the committed
// move derive from this single function, so what the user sees is always what
// lands. The indicator is always anchored to the hovered row because every
// outcome (before / after / first-child) lands adjacent to it.
type DropResolution = {
  parentId: string | null
  insertIndex: number
  afterId: string | null
  rowId: string
  side: 'top' | 'bottom'
  level: number
  nest: boolean
  label: string
}

const resolveDrop = (
  targetId: string,
  edge: Edge,
  draggedIds: string[]
): DropResolution | null => {
  const target = data.value?.[targetId]
  if (!target) {
    return null
  }

  const moving = new Set(draggedIds)
  const targetLevel = levelMap.value.get(targetId) ?? 1
  const targetParentId = target.pid ?? null
  const isContainer = target.type !== 'single'

  const siblingsOf = (parentId: string | null): string[] =>
    (childIdMap.value.get(parentId) || []).filter((id) => !moving.has(id))

  const reorderBefore = (): DropResolution => {
    const siblings = siblingsOf(targetParentId)
    const index = Math.max(siblings.indexOf(targetId), 0)
    return {
      parentId: targetParentId,
      insertIndex: index,
      afterId: index > 0 ? (siblings[index - 1] ?? null) : null,
      rowId: targetId,
      side: 'top',
      level: targetLevel,
      nest: false,
      label: t('labels.contentTree.drop.moveBefore') as string,
    }
  }

  const reorderAfter = (): DropResolution => {
    const siblings = siblingsOf(targetParentId)
    const targetIndex = siblings.indexOf(targetId)
    const insertIndex = targetIndex < 0 ? siblings.length : targetIndex + 1
    return {
      parentId: targetParentId,
      insertIndex,
      afterId: insertIndex > 0 ? (siblings[insertIndex - 1] ?? null) : null,
      // Anchor below the target's whole visible subtree so the line sits exactly
      // where the new sibling will appear, not under the (expanded) parent header.
      rowId: lastVisibleDescendant(targetId),
      side: 'bottom',
      level: targetLevel,
      nest: false,
      label: t('labels.contentTree.drop.moveAfter') as string,
    }
  }

  // Nest as the first child so the indicator (drawn one level deeper, just below
  // the row) always sits exactly where the item will appear.
  const nestAsFirstChild = (): DropResolution => ({
    parentId: targetId,
    insertIndex: 0,
    afterId: null,
    rowId: targetId,
    side: 'bottom',
    level: targetLevel + 1,
    nest: true,
    label: t('labels.contentTree.drop.moveInto') as string,
  })

  if (edge === 'top') {
    return reorderBefore()
  }

  // The middle band ('left') nests into a container; for a leaf it falls back to
  // reordering after, since a leaf cannot hold children.
  if (edge === 'left') {
    return isContainer ? nestAsFirstChild() : reorderAfter()
  }

  // edge === 'bottom' always reorders after the row (same parent), so items can be
  // moved past an expanded container without being captured as its child.
  return reorderAfter()
}

const currentDragIds = computed(() => getNormalizedIds(dragSelectionSnapshot.value ?? []))

const dropResolution = computed<DropResolution | null>(() => {
  const targetId = activeDropTargetId.value
  const edge = activeDropEdge.value
  if (!targetId || !edge) {
    return null
  }

  return resolveDrop(targetId, edge, currentDragIds.value)
})

const moveDraggedItemsToRoot = async (dragItems: ContentTreeDragItem[]) => {
  const orderedDraggedIds = getNormalizedIds(
    dragItems.map((item) => item.id).filter((id, index, array) => array.indexOf(id) === index)
  )

  if (!orderedDraggedIds.length) {
    finishDragState()
    return
  }

  try {
    await executeTreeOperations([
      {
        type: 'move',
        ids: orderedDraggedIds,
        parent_id: null,
      },
    ])
  } finally {
    finishDragState()
  }
}

const moveDraggedItemsToTarget = async (
  dragItems: ContentTreeDragItem[],
  targetId: string,
  edge: Edge
) => {
  const orderedDraggedIds = getNormalizedIds(
    dragItems.map((item) => item.id).filter((id, index, array) => array.indexOf(id) === index)
  )

  if (!orderedDraggedIds.length) {
    finishDragState()
    return
  }

  if (orderedDraggedIds.length === 1 && targetId === orderedDraggedIds[0]) {
    finishDragState()
    return
  }

  try {
    // Resolve through the exact same function that drives the indicator, so the
    // committed move always matches the line the user was shown.
    const resolution = resolveDrop(targetId, edge, orderedDraggedIds)
    if (!resolution) {
      return
    }

    // Always commit the move — including reorders within the same parent. The
    // backend resequence is idempotent, so it is the source of truth; suppressing
    // "looks unchanged" drops on the client previously dropped same-parent reorders.
    await executeTreeOperations([
      {
        type: 'move',
        ids: orderedDraggedIds,
        parent_id: resolution.parentId,
        after_id: resolution.afterId,
        position: resolution.insertIndex,
      },
    ])
  } finally {
    finishDragState()
  }
}

const registerItemInteractions = (item: FlatContentMenuItem, element: HTMLElement) => {
  const cleanup = combine(
    draggable({
      element,
      getInitialData: () => {
        const dragItems = getSelectedDragItemsFor(item.id)
        dragSelectionSnapshot.value = dragItems.map((dragItem) => dragItem.id)
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
        restoreDragSelection()
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
      getData: ({ input }) => ({
        id: item.id,
        kind: CONTENT_TREE_DRAG_KIND,
        closestEdge: computeDropEdge(element, input, item),
      }),
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

        if (!canDropItemsOnTarget(dragItems, item.id)) {
          if (!isSelfDrop(dragItems, item.id)) {
            toast.error(t('labels.contentTree.errors.invalidMove') as string)
          }
          clearDropState()
          return
        }

        // Read the edge from the drop event's own payload — it is authoritative
        // for this drop. The reactive `activeDropEdge` can be cleared by an
        // onDragLeave race, and its 'bottom' fallback would silently turn an
        // intended "move before" (top) into a "move after".
        const edge = extractClosestEdge(self.data) ?? activeDropEdge.value ?? 'bottom'
        await moveDraggedItemsToTarget(dragItems, item.id, edge)
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
        await moveDraggedItemsToRoot(dragItems)
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
  titleRefMap.clear()
})
</script>

<template>
  <aside
    ref="treeContainerRef"
    class="relative flex h-full min-h-0 flex-col overflow-hidden"
  >
    <VerticalScrollArea class="h-full min-h-0 w-full">
      <TreeRoot
        v-slot="{ flattenItems }"
        v-model:expanded="settings.content.expanded"
        class="w-full list-none p-2 select-none"
        :class="{ 'pb-8': hasClipboardItem }"
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
            'group relative mb-1 flex w-full items-center gap-2 rounded-md border border-transparent -my-1 pl-2 py-1 transition-all duration-150',
            rootDropMode ? 'bg-accent/50 ring-1 ring-info' : '',
          ]"
          @contextmenu="handleRootContextMenu"
        >
          <button
            type="button"
            class="min-w-0 flex flex-1 items-center gap-2 text-left"
            @click="
              router.push({ name: 'space-content-index', params: { space: route.params.space } })
            "
          >
            <NuxtImg
              v-if="selectedSpace.icon"
              :src="selectedSpace.icon"
              :alt="selectedSpace.name"
              :width="48"
              :height="48"
              class="size-6 shrink-0 rounded-sm object-cover"
            />
            <div class="min-w-0">
              <div class="flex items-center gap-2 -mb-1">
                <Icon
                  v-if="!selectedSpace.icon"
                  name="lucide:cuboid"
                  class="shrink-0 text-muted"
                />
                <span class="truncate font-semibold text-primary">{{ selectedSpace.name }}</span>
              </div>
              <SpaceBadge
                v-if="selectedSpace.badge"
                :badge="selectedSpace.badge"
                size="2xs"
              />
            </div>
          </button>

          <div class="ml-auto flex items-center gap-1">
            <Button
              v-if="canManageContent"
              variant="ghost"
              size="toolbar"
              @click.stop="initCreate(null)"
            >
              <Icon name="lucide:plus" />
            </Button>

            <Button
              v-if="buildRootMenuActions().length > 0"
              variant="ghost"
              size="toolbar"
              class="opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-within:opacity-100"
              @click.stop="handleRootMenuTrigger"
            >
              <Icon name="lucide:ellipsis-vertical" />
            </Button>
          </div>

          <DropIndicator
            v-if="rootDropMode === 'root'"
            edge="bottom"
            gap="0px"
            :label="$t('labels.contentTree.drop.moveToRoot')"
          />
        </div>

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

        <TreeItem
          v-for="item in flattenItems"
          :ref="setItemElement(item.value)"
          v-slot="{ isExpanded }"
          :key="item._id"
          :style="{ 'padding-left': `${item.level - 0.5}rem` }"
          v-bind="item.bind"
          :as="RouterLink"
          :to="buildLink(item.value.id)"
          :class="[
            'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none',
            'transition-colors duration-150 hover:bg-border',
            'cursor-pointer font-semibold',
            item.value.id === selectedItemId ? 'text-primary' : '',
            isCutItem(item.value.id) ? 'opacity-50' : '',
            isItemSelected(item.value.id) ? 'bg-border text-primary' : '',
            dropResolution?.nest && dropResolution.rowId === item.value.id
              ? 'bg-accent/50 ring-1 ring-info/30'
              : '',
          ]"
          @pointerdown="handleItemPointerDown($event, item.value.id)"
          @click="handleItemNavigate($event, item.value.id)"
          @contextmenu="handleItemContextMenu($event, item.value.id)"
          @toggle="handleToggle"
        >
          <DropIndicator
            v-if="dropResolution && dropResolution.rowId === item.value.id"
            :edge="dropResolution.side"
            gap="4px"
            inset="6px"
            :indent="`${dropResolution.level - 0.5}rem`"
            :label="dropResolution.label"
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
            :ref="setTitleRef(item.value.id)"
            :name="item.value.name"
            :disabled="!canManageContent"
            class="w-full truncate text-left"
            @update="handleRename($event, item.value.id)"
            @edit-start="handleEditStart(item.value.id)"
            @cancel="handleEditCancel"
          />

          <div class="ml-auto pl-3 flex items-center gap-2">
            <AvatarList
              v-if="getUsersForContent(item.value.id).length > 0"
              :users="getUsersForContent(item.value.id)"
              size="sm"
              :class="[isItemSelected(item.value.id) ? 'mr-4' : 'mr-1  group-hover:mr-4']"
            />
            <div
              v-if="!item.value.pat"
              class="h-2 w-2 rounded-full bg-text-muted"
              :title="$t('labels.contentTree.status.draft')"
            />
            <SimpleTooltip
              v-else
              :tooltip="item.value.pat"
            >
              <div class="h-2 w-2 rounded-full bg-success" />
            </SimpleTooltip>
          </div>

          <div class="absolute right-4">
            <button
              type="button"
              :aria-label="$t('labels.contentTree.actions.more')"
              :class="[
                'cursor-pointer flex items-center rounded-sm p-1 text-muted transition-opacity duration-200 hover:text-primary focus-visible:opacity-100',
                isItemSelected(item.value.id) || openMenuId === item.value.id
                  ? 'opacity-100'
                  : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100',
              ]"
              @pointerdown.stop.prevent
              @click.stop.prevent="handleItemMenuTrigger($event, item.value.id)"
            >
              <Icon name="lucide:ellipsis-vertical" />
            </button>
          </div>
        </TreeItem>
      </TreeRoot>
    </VerticalScrollArea>

    <DropdownMenu
      :open="openMenuId !== null"
      @update:open="handleMenuOpenChange"
    >
      <DropdownMenuContent
        v-if="activeMenuActions.length > 0"
        align="end"
        :reference="activeMenuAnchor ?? undefined"
      >
        <template
          v-for="action in activeMenuActions"
          :key="action.id"
        >
          <DropdownMenuSeparator v-if="action.separatorBefore" />
          <DropdownMenuItem
            :disabled="action.disabled"
            :class="action.destructive ? 'text-destructive focus:text-destructive' : ''"
            @select.prevent="handleActionSelect(action)"
          >
            <Icon :name="`lucide:${action.icon}`" />
            <span>{{ action.label }}</span>
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenu>

    <div class="absolute inset-x-4 bottom-0 z-10 flex flex-col items-center gap-3 overflow-clip">
      <TransitionGroup
        enter-active-class="transition duration-150 ease-butter"
        leave-active-class="transition duration-150 ease-butter"
        enter-from-class="opacity-0 translate-y-full"
        enter-to-class="opacity-100 translate-y-0"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-full"
      >
        <div
          v-if="hasClipboardItem"
          key="clearClipboard"
        >
          <Button
            :title="t('actions.clearClipboard')"
            size="xs"
            variant="ghost"
            @click="clearTreeClipboard"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ t('actions.clearClipboard') }}</span>
          </Button>
        </div>
      </TransitionGroup>
    </div>

    <CreateContentDialog
      v-if="canManageContent"
      v-model:open="showCreateDialog"
      :space-id="props.spaceId"
      :parent-id="createParentId"
      :on-submit="handleCreateSubmit"
    />
    <PublishDialog
      v-if="publishDialogItem"
      :open="publishDialogOpen"
      :content="publishDialogItem as any"
      :loading="isPublishingAction"
      :publish-type="publishDialogType"
      @update:open="publishDialogOpen = $event"
      @publish="handlePublishDialog"
      @schedule="handleScheduleDialog"
    />
  </aside>
</template>
