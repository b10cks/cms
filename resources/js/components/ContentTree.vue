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
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '~/components/ui/dropdown-menu'
import DropIndicator from '~/components/ui/DropIndicator.vue'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { VerticalScrollArea } from '~/components/ui/scroll-area'
import { Skeleton } from '~/components/ui/skeleton'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { useContentTreeClipboard } from '~/composables/useContentTreeClipboard'
import { useHoverPrefetch } from '~/composables/useHoverPrefetch'
import { queryKeys } from '~/composables/useQueryClient'
import { normalizeLanguageIso } from '~/lib/content-i18n'
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'
import { buildPreviewUrl } from '~/lib/preview-url'
import { isEditableTarget } from '~/lib/shortcuts'
import { automationAppliesToBlock } from '~/utils/automations'
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
  children?: ContentTreeMenuAction[]
  onSelect?: () => void | Promise<void>
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
const {
  useTreeOperationsMutation,
  useUpdateContentMutation,
  usePublishContentMutation,
  useScheduleContentMutation,
} = useContent(props.spaceId)
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageContent = computed(() => access.hasAbility('content.manage'))
const canPublishContent = computed(() => access.hasAbility('content.publish'))
// automations.trigger also implies listing automations (policy viewAny), so
// gating discovery and the submenu on it alone is safe.
const canTriggerAutomations = computed(() => access.hasAbility('automations.trigger'))
// Manual drag-to-reorder is opt-in per space. When disabled, dragging may only
// reparent items (the "into" gesture); before/after reordering is suppressed.
const sortingEnabled = computed(() => !!selectedSpace.value?.settings?.content_sorting)

// Folders can override the space default via their own child_sort_by setting:
// 'manual' enables reordering inside the folder, an attribute sort disables it
// (the attribute dictates the order), and 'inherit' falls back to the space.
const allowsManualSort = (parentId: string | null): boolean => {
  const sortBy = parentId ? data.value?.[parentId]?.settings?.child_sort_by : undefined
  if (!sortBy || sortBy === 'inherit') {
    return sortingEnabled.value
  }

  return sortBy === 'manual'
}
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

const { useAutomationsQuery, useTriggerAutomationMutation } = useAutomations(props.spaceId)
const { data: manualAutomationsData } = useAutomationsQuery(
  { trigger_type: 'manual', table: 'contents', is_active: true, per_page: 100 },
  canTriggerAutomations
)
const { mutateAsync: triggerAutomation, isPending: isTriggeringAutomation } =
  useTriggerAutomationMutation()
const manualContentAutomations = computed(() => manualAutomationsData.value?.data || [])

const { isLoading, error, data } = useContentMenuQuery()

// Skeleton rows shown on first load; indents/widths hint at a tree shape and
// the rows fade out toward the bottom. Indent values mirror the real rows'
// `padding-left: ${level - 0.5}rem`.
const skeletonRows = [
  { indent: 0.5, width: 'w-2/5' },
  { indent: 0.5, width: 'w-3/5' },
  { indent: 1.5, width: 'w-1/2' },
  { indent: 1.5, width: 'w-2/5' },
  { indent: 0.5, width: 'w-3/4' },
  { indent: 1.5, width: 'w-1/3' },
  { indent: 0.5, width: 'w-1/2' },
  { indent: 0.5, width: 'w-2/5' },
]

// Warm the detail cache after ~150ms of sustained hover/focus on a tree row,
// mirroring useContent().useContentQuery() exactly (same key + queryFn) so the
// editor page gets an instant cache hit. prefetchQuery never throws.
const { start: startDetailPrefetch, cancel: cancelDetailPrefetch } = useHoverPrefetch(
  (contentId: string) =>
    queryClient.prefetchQuery({
      queryKey: queryKeys.contents(props.spaceId).detail(contentId),
      queryFn: async () => {
        const response = await api.forSpace(props.spaceId).contents.get(contentId)
        return response.data
      },
    })
)
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

const expandedSet = computed(() => new Set(treeExpanded.value))
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
  cancelExpandOnHover()
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
  // Sorting disabled for the row's folder: only reparenting (nesting) is
  // permitted, never reordering.
  if (!allowsManualSort(item.pid ?? null)) {
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
    (value.closestEdge === 'top' || value.closestEdge === 'bottom' || value.closestEdge === 'left')
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
      preview.style.border = '1px solid var(--popover-border)'
      preview.style.background = 'var(--popover)'
      preview.style.boxShadow = 'var(--shadow-soft-sm, 0 4px 12px rgb(0 0 0 / 0.15))'
      preview.style.color = 'var(--popover-foreground)'
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
        badge.style.background = 'var(--muted-background)'
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
  const expanded = treeExpanded.value

  treeExpanded.value = expanded.includes(contentId)
    ? expanded.filter((id) => id !== contentId)
    : [...expanded, contentId]
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

    const url = buildPreviewUrl(
      environment.url,
      selectedSpace.value?.settings,
      content.language_iso,
      content.full_slug
    )

    if (url) {
      window.open(url, '_blank', 'noopener,noreferrer')
    }
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

// A single block exists once per tree, so copying or cutting one can only ever
// produce something that cannot be placed.
const containsSingleItem = (ids: string[]) =>
  ids.some((id) => data.value?.[id]?.type === 'single')

const canCopyOrCutSelection = (context: ContentTreeActionContext) =>
  canManageContent.value &&
  context.resolved_ids.length > 0 &&
  !containsSingleItem(context.resolved_ids)

const copyToClipboard = async (context: ContentTreeActionContext) => {
  if (!canCopyOrCutSelection(context)) {
    return
  }

  const snapshot = createClipboardSnapshot(context.resolved_ids)
  await copyClipboardItem(snapshot.length === 1 ? snapshot[0] : snapshot, props.spaceId)
  await syncActiveClipboardItem()
}

const cutToClipboard = async (context: ContentTreeActionContext) => {
  if (!canCopyOrCutSelection(context)) {
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

// There is something to publish when the draft differs from the published
// version, or when the entry is offline and can be put back live as it stands.
const hasPendingChanges = (item: FlatContentMenuItem) => item.drf || !item.pat

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

const handleScheduleDialog = async (payload: {
  message?: string
  scheduled_at?: string | null
}) => {
  if (!publishDialogItem.value) return
  await scheduleContent({ id: publishDialogItem.value.id, payload })
  publishDialogOpen.value = false
  publishDialogItem.value = null
}

const buildItemAutomationActions = (item: FlatContentMenuItem): ContentTreeMenuAction[] => {
  if (!canTriggerAutomations.value) {
    return []
  }

  const automations = manualContentAutomations.value.filter((automation) =>
    automationAppliesToBlock(automation, item.block_id)
  )

  return [
    {
      id: 'automations',
      label: t('labels.contentTree.actions.automations') as string,
      icon: 'zap',
      separatorBefore: true,
      disabled: automations.length === 0,
      children: automations.map((automation) => ({
        id: `automation-${automation.id}`,
        label: automation.name,
        icon: 'zap',
        disabled: isTriggeringAutomation.value,
        onSelect: async () => {
          await triggerAutomation({ id: automation.id, payload: { content_id: item.id } })
        },
      })),
    },
  ]
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
      disabled: !canPublishContent.value || isPublishingAction.value || !hasPendingChanges(item),
      onSelect: () => executePublish(item),
    },
    {
      id: 'publish-with-message',
      label: t('labels.contentTree.actions.publishWithMessage') as string,
      icon: 'message-square-share',
      disabled: !canPublishContent.value || isPublishingAction.value || !hasPendingChanges(item),
      onSelect: () => openPublishWithMessage(item),
    },
    {
      id: 'schedule',
      label: t('labels.contentTree.actions.schedule') as string,
      icon: 'clock-fading',
      disabled: !canPublishContent.value || isPublishingAction.value || !hasPendingChanges(item),
      onSelect: () => openSchedulePublish(item),
    },
    ...buildItemAutomationActions(item),
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
      disabled: !canCopyOrCutSelection(context),
      onSelect: () => copyToClipboard(context),
    },
    {
      id: 'cut',
      label: t('labels.contentTree.actions.cut') as string,
      icon: 'scissors',
      disabled: !canCopyOrCutSelection(context),
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
  if (action.disabled || !action.onSelect) {
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

  // The click has resolved against the filtered list, so it is now safe to
  // tear the filter down (see handleSearchBlur).
  if (searchOpen.value) {
    closeSearch()
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
// what feedback to render. Both the live feedback and the committed move derive
// from this single function, so what the user sees is always what lands.
//   lineRowId      – row whose `side` edge carries the insertion line, drawn at
//                    `level`; null when the drop only re-parents (the receiving
//                    folder dictates its own order, so a position would lie).
//   containerRowId – row painted as the receiving container: the hovered folder
//                    for a nest, or the (unsorted) parent of a hovered leaf.
// The destination parent's indent guide is highlighted on top of either.
type DropResolution = {
  parentId: string | null
  insertIndex: number
  afterId: string | null
  lineRowId: string | null
  side: 'top' | 'bottom'
  level: number
  containerRowId: string | null
  label: string
}

const resolveDrop = (targetId: string, edge: Edge, draggedIds: string[]): DropResolution | null => {
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
      lineRowId: targetId,
      side: 'top',
      level: targetLevel,
      containerRowId: null,
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
      lineRowId: lastVisibleDescendant(targetId),
      side: 'bottom',
      level: targetLevel,
      containerRowId: null,
      label: t('labels.contentTree.drop.moveAfter') as string,
    }
  }

  // Nest as the first child so the indicator (drawn one level deeper, just below
  // the row) always sits exactly where the item will appear.
  const nestAsFirstChild = (): DropResolution => ({
    parentId: targetId,
    insertIndex: 0,
    afterId: null,
    lineRowId: targetId,
    side: 'bottom',
    level: targetLevel + 1,
    containerRowId: targetId,
    label: t('labels.contentTree.drop.moveInto') as string,
  })

  // A leaf inside a folder that dictates its own order: the drop can only file
  // the items into that folder, so highlight the folder instead of drawing a
  // position line that would not hold.
  const fileIntoParent = (parentId: string): DropResolution => ({
    parentId,
    insertIndex: siblingsOf(parentId).length,
    afterId: null,
    lineRowId: null,
    side: 'bottom',
    level: targetLevel,
    containerRowId: parentId,
    label: t('labels.contentTree.drop.moveInto') as string,
  })

  if (edge === 'top') {
    return reorderBefore()
  }

  // The middle band ('left') nests into a container. A leaf cannot hold
  // children: it reorders after itself where its parent is manually sorted,
  // otherwise the drop just files the items into that parent.
  if (edge === 'left') {
    if (isContainer) {
      return nestAsFirstChild()
    }

    return targetParentId !== null && !allowsManualSort(targetParentId)
      ? fileIntoParent(targetParentId)
      : reorderAfter()
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

// Indent guides: one vertical hairline per ancestor level, drawn in that
// ancestor's chevron column. Level k's column is (k - 0.5)rem of row padding
// plus half of the 16px chevron icon, so a 1rem-period gradient starting at
// level 1 covers every ancestor of a row in a single element.
const guideOffset = (level: number) => `calc(${level - 0.5}rem + 8px)`

// While dragging, the destination parent's guide lights up along its whole
// visible subtree, connecting the insertion line to the folder it lands in.
const dropGuide = computed(() => {
  const resolution = dropResolution.value
  if (!resolution?.parentId) {
    return null
  }

  return { parentId: resolution.parentId, level: resolution.level - 1 }
})

const isInDropGuideSubtree = (id: string): boolean => {
  const guide = dropGuide.value
  return !!guide && (descendantIdsMap.value.get(guide.parentId)?.has(id) ?? false)
}

// The insertion line starts on the parent's guide so its terminal dot reads as
// a node on the connector; root-level lines have no guide to join.
const dropLineIndent = (level: number) => (level > 1 ? guideOffset(level - 1) : '0.5rem')

// Hovering a collapsed folder with nest intent expands it after a short dwell,
// so items can be dropped between its children without leaving the drag.
const EXPAND_ON_HOVER_DELAY = 600
let expandOnHoverTimer: ReturnType<typeof setTimeout> | null = null
let expandOnHoverId: string | null = null

const cancelExpandOnHover = () => {
  if (expandOnHoverTimer) {
    clearTimeout(expandOnHoverTimer)
  }
  expandOnHoverTimer = null
  expandOnHoverId = null
}

const scheduleExpandOnHover = (item: FlatContentMenuItem, edge: Edge | null) => {
  const wantsExpand =
    edge === 'left' && hasVisibleChildren(item.id) && !expandedSet.value.has(item.id)

  if (!wantsExpand) {
    if (expandOnHoverId === item.id) {
      cancelExpandOnHover()
    }
    return
  }

  if (expandOnHoverId === item.id) {
    return
  }

  cancelExpandOnHover()
  expandOnHoverId = item.id
  expandOnHoverTimer = setTimeout(() => {
    expandOnHoverTimer = null
    expandOnHoverId = null
    if (activeDropTargetId.value === item.id && !expandedSet.value.has(item.id)) {
      treeExpanded.value = [...treeExpanded.value, item.id]
    }
  }, EXPAND_ON_HOVER_DELAY)
}

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

// A renamed row must be explicitly undraggable, not merely stripped of
// pragmatic-dnd's `draggable="true"`: rows are links, and a link drags by
// default. Chrome then drags the row when text is selected in the rename
// field, and Firefox focuses the row on mousedown — which ends the rename.
const suspendDragging = (element: HTMLElement) => {
  element.setAttribute('draggable', 'false')

  return () => element.removeAttribute('draggable')
}

const registerItemInteractions = (item: FlatContentMenuItem, element: HTMLElement) => {
  // `data-renaming` keeps this in sync by re-running the ref callback.
  const isRenaming = currentlyEditingId.value === item.id

  const cleanup = combine(
    isRenaming
      ? suspendDragging(element)
      : draggable({
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
        scheduleExpandOnHover(item, closestEdge)
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
        scheduleExpandOnHover(item, closestEdge)
      },
      onDragLeave: () => {
        if (expandOnHoverId === item.id) {
          cancelExpandOnHover()
        }
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

// --- Type-to-search (JetBrains-style speed search) ---

const searchOpen = ref(false)
const searchQuery = ref('')
const activeMatchIndex = ref(0)
const searchInputRef = ref<HTMLInputElement | null>(null)
const searchPanelRef = ref<HTMLElement | null>(null)

type ContentSearchMatch = {
  item: FlatContentMenuItem
  score: number
  indices: number[]
}

// Names are normalized once per tree change, not once per keystroke.
const searchTargets = computed(() =>
  flatItems.value.map((item) => ({ item, target: prepareFuzzyTarget(item.name) }))
)

// All fuzzy matches in tree order, so ↑/↓ walks the tree visually instead of
// jumping around by score. The best-scoring match is only used as the entry point.
const searchMatches = computed<ContentSearchMatch[]>(() => {
  const query = prepareFuzzyQuery(searchQuery.value)
  if (!query) {
    return []
  }

  const matches: ContentSearchMatch[] = []
  for (const { item, target } of searchTargets.value) {
    const match = fuzzyMatch(query, target)
    if (match) {
      matches.push({ item, score: match.score, indices: match.indices })
    }
  }

  return matches
})

// While a query is active the tree is filtered down to the matches plus the
// ancestor chain ("root line") needed to show them in context.
const searchFilterActive = computed(() => searchOpen.value && searchQuery.value.trim().length > 0)

const searchAncestorIds = computed(() => {
  const ancestors = new Set<string>()
  for (const match of searchMatches.value) {
    let parentId = parentIdMap.value.get(match.item.id) ?? null
    while (parentId && !ancestors.has(parentId)) {
      ancestors.add(parentId)
      parentId = parentIdMap.value.get(parentId) ?? null
    }
  }

  return ancestors
})

const searchVisibleIds = computed<Set<string> | null>(() => {
  if (!searchFilterActive.value) {
    return null
  }

  const visible = new Set(searchAncestorIds.value)
  for (const match of searchMatches.value) {
    visible.add(match.item.id)
  }

  return visible
})

const visibleRootItems = computed(() => {
  const visible = searchVisibleIds.value
  if (!visible) {
    return rootItems.value
  }

  return rootItems.value.filter((item) => visible.has(item.id))
})

// Filtered child lists, computed once per query instead of on every
// getVisibleChildren call during render.
const visibleChildrenMap = computed<Map<string, FlatContentMenuItem[]> | null>(() => {
  const visible = searchVisibleIds.value
  if (!visible) {
    return null
  }

  const map = new Map<string, FlatContentMenuItem[]>()
  for (const id of visible) {
    map.set(
      id,
      getChildren(data.value, id).filter((child) => visible.has(child.id))
    )
  }

  return map
})

const getVisibleChildren = (item: FlatContentMenuItem) => {
  const map = visibleChildrenMap.value
  if (!map) {
    return getChildren(data.value, item.id)
  }

  return map.get(item.id) ?? []
}

// The filtered view auto-expands every ancestor of a match. Toggles made while
// searching land in a throwaway overlay so the user's persisted expanded state
// stays untouched; it resets whenever the query changes.
const searchExpandedOverride = ref<string[] | null>(null)

const treeExpanded = computed<string[]>({
  get: () => {
    if (!searchFilterActive.value) {
      return settings.value.content.expanded || []
    }

    if (searchExpandedOverride.value) {
      return searchExpandedOverride.value
    }

    return Array.from(searchAncestorIds.value)
  },
  set: (value) => {
    if (searchFilterActive.value) {
      searchExpandedOverride.value = value
    } else {
      settings.value.content.expanded = value
    }
  },
})

watch(searchQuery, () => {
  searchExpandedOverride.value = null
})

const searchHighlightMap = computed(() => {
  const map = new Map<string, number[]>()
  if (!searchOpen.value) {
    return map
  }

  for (const match of searchMatches.value) {
    map.set(match.item.id, match.indices)
  }

  return map
})

const searchCounterText = computed(() => {
  if (!searchQuery.value.trim()) {
    return ''
  }

  if (searchMatches.value.length === 0) {
    return t('labels.contentTree.search.noMatches') as string
  }

  return t('labels.contentTree.search.matches', {
    current: activeMatchIndex.value + 1,
    total: searchMatches.value.length,
  }) as string
})

const getItemRowElement = (id: string) =>
  treeContainerRef.value?.querySelector<HTMLElement>(`[data-content-id="${CSS.escape(id)}"]`) ??
  null

const focusItemRow = (id: string | null) => {
  if (!id) {
    return
  }

  void nextTick(() => {
    const row = getItemRowElement(id)
    row?.focus({ preventScroll: true })
    row?.scrollIntoView({ block: 'nearest' })
  })
}

const revealAncestors = (id: string) => {
  const expanded = new Set(settings.value.content.expanded || [])
  let changed = false
  let parentId = parentIdMap.value.get(id) ?? null

  while (parentId) {
    if (!expanded.has(parentId)) {
      expanded.add(parentId)
      changed = true
    }
    parentId = parentIdMap.value.get(parentId) ?? null
  }

  if (changed) {
    settings.value.content.expanded = Array.from(expanded)
  }
}

const setActiveMatch = (index: number) => {
  const match = searchMatches.value[index]
  if (!match) {
    return
  }

  activeMatchIndex.value = index
  selectSingleItem(match.item.id)
  void nextTick(() => {
    getItemRowElement(match.item.id)?.scrollIntoView({ block: 'nearest' })
  })
}

watch(searchMatches, (matches) => {
  if (!searchOpen.value || matches.length === 0) {
    return
  }

  let best = 0
  matches.forEach((match, index) => {
    if (match.score > matches[best].score) {
      best = index
    }
  })

  setActiveMatch(best)
})

const cycleMatch = (direction: 1 | -1) => {
  const total = searchMatches.value.length
  if (total === 0) {
    return
  }

  setActiveMatch((activeMatchIndex.value + direction + total) % total)
}

const toggleSearch = () => {
  if (searchOpen.value) {
    closeSearch()
  } else {
    openSearch()
  }
}

const openSearch = (initial = '') => {
  if (searchOpen.value) {
    if (initial) {
      searchQuery.value += initial
    }
  } else {
    searchQuery.value = initial
    activeMatchIndex.value = 0
    searchOpen.value = true
  }

  void nextTick(() => {
    const input = searchInputRef.value
    if (!input) {
      return
    }

    input.focus()
    input.setSelectionRange(input.value.length, input.value.length)
  })
}

const closeSearch = (focusTree = true) => {
  if (!searchOpen.value) {
    return
  }

  searchOpen.value = false
  searchQuery.value = ''
  activeMatchIndex.value = 0
  searchExpandedOverride.value = null

  if (focusTree && selectedItemId.value) {
    // Persist the ancestor expansion so the row stays visible once the
    // filtered view is gone, then move focus back onto it.
    revealAncestors(selectedItemId.value)
    focusItemRow(selectedItemId.value)
  }
}

const openActiveMatch = async () => {
  const match = searchMatches.value[activeMatchIndex.value]
  closeSearch(false)

  if (match) {
    revealAncestors(match.item.id)
    await openEdit(match.item.id)
    focusItemRow(match.item.id)
  }
}

const handleSearchInputKeydown = (event: KeyboardEvent) => {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      cycleMatch(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      cycleMatch(-1)
      break
    case 'Enter':
      event.preventDefault()
      void openActiveMatch()
      break
    case 'Escape':
      event.preventDefault()
      event.stopPropagation()
      closeSearch()
      break
  }
}

const handleSearchBlur = (event: FocusEvent) => {
  const next = event.relatedTarget
  if (next instanceof Node && searchPanelRef.value?.contains(next)) {
    return
  }

  // Clicking a filtered row moves focus onto that row, firing this blur between
  // pointerdown and click. Closing here would clear the filter and reflow the
  // tree mid-click, landing the click on the wrong row. Let the row's click
  // handler close the search once the correct item has been resolved.
  if (next instanceof HTMLElement && next.closest('[data-content-id]')) {
    return
  }

  closeSearch(false)
}

// Capture-phase so a printable keystroke anywhere in the tree opens the search
// before reka-ui's built-in single-character typeahead can swallow it.
const handleTreeKeydownCapture = (event: KeyboardEvent) => {
  if (event.defaultPrevented || currentlyEditingId.value || isEditableTarget(event.target)) {
    return
  }

  const printable = event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey

  if (!printable) {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  openSearch(event.key)
}

/**
 * Alt+Up/Down: the very same move operation a drag commits, resolved through
 * `resolveDrop` so keyboard and pointer reordering cannot drift apart.
 */
const moveAmongSiblings = async (item: FlatContentMenuItem, direction: -1 | 1) => {
  const parentId = item.pid ?? null
  if (!canManageContent.value || !allowsManualSort(parentId)) return

  const siblings = childIdMap.value.get(parentId) || []
  const index = siblings.indexOf(item.id)
  const neighbourId = siblings[index + direction]
  if (index < 0 || !neighbourId) return

  const resolution = resolveDrop(neighbourId, direction === -1 ? 'top' : 'bottom', [item.id])
  if (!resolution) return

  await executeTreeOperations([
    {
      type: 'move',
      ids: [item.id],
      parent_id: resolution.parentId,
      after_id: resolution.afterId,
      position: resolution.insertIndex,
    },
  ])
}

// --- Keyboard actions on tree rows (arrows / expand / collapse come from reka-ui) ---

const handleItemKeydown = (event: KeyboardEvent, item: FlatContentMenuItem) => {
  // Only act when the row itself is focused — child buttons (chevron, menu
  // trigger) keep their own Enter/Space semantics.
  if (event.target !== event.currentTarget || currentlyEditingId.value) {
    return
  }

  const meta = event.metaKey || event.ctrlKey

  if (event.key === 'Enter') {
    event.preventDefault()
    event.stopPropagation()
    void openEdit(item.id)
    return
  }

  if (event.key === 'F2') {
    event.preventDefault()
    void openRename(item.id)
    return
  }

  if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
    event.preventDefault()
    event.stopPropagation()
    void moveAmongSiblings(item, event.key === 'ArrowUp' ? -1 : 1)
    return
  }

  if (
    meta &&
    event.shiftKey &&
    event.key.toLowerCase() === 'n' &&
    canManageContent.value &&
    item.type !== 'single'
  ) {
    event.preventDefault()
    event.stopPropagation()
    initCreate(item.id)
    return
  }

  if ((event.key === 'Delete' || (event.key === 'Backspace' && meta)) && canManageContent.value) {
    event.preventDefault()
    void executeDelete(resolveMenuContext(item.id))
    return
  }

  if (event.key === 'ContextMenu' || (event.key === 'F10' && event.shiftKey)) {
    event.preventDefault()
    event.stopPropagation()
    if (!selectedItemsSet.value.has(item.id)) {
      selectSingleItem(item.id)
    }
    void openMenu(item.id, resolveMenuAnchorElement(event.currentTarget))
    return
  }

  if (meta && canManageContent.value) {
    const key = event.key.toLowerCase()

    if (key === 'c') {
      event.preventDefault()
      void copyToClipboard(resolveMenuContext(item.id))
      return
    }

    if (key === 'x') {
      event.preventDefault()
      void cutToClipboard(resolveMenuContext(item.id))
      return
    }

    if (key === 'v') {
      event.preventDefault()
      void syncActiveClipboardItem().then(() =>
        pasteClipboard(item, item.type !== 'single' ? 'in' : 'after')
      )
    }
  }
}

// Documentation-only: every one of these is handled on the focused row by
// `handleItemKeydown` / reka-ui's tree primitive, not by the registry.
const treeShortcuts: Array<[string, () => string]> = [
  ['arrowup+arrowdown+arrowleft+arrowright', () => t('shortcuts.contentTree.open')],
  ['enter', () => t('shortcuts.contentTree.open')],
  ['F2', () => t('shortcuts.contentTree.rename')],
  ['shift+mod+n', () => t('shortcuts.contentTree.newChild')],
  ['alt+arrowup', () => t('shortcuts.contentTree.moveUp')],
  ['alt+arrowdown', () => t('shortcuts.contentTree.moveDown')],
  ['mod+c', () => t('shortcuts.contentTree.copy')],
  ['mod+x', () => t('shortcuts.contentTree.cut')],
  ['mod+v', () => t('shortcuts.contentTree.paste')],
  ['delete', () => t('shortcuts.contentTree.delete')],
  ['shift+F10', () => t('shortcuts.contentTree.contextMenu')],
  ['A-Z', () => t('shortcuts.contentTree.search')],
]

for (const [keys, description] of treeShortcuts) {
  useShortcut({ keys, scope: 'content-tree', description, handler: null })
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
    class="relative flex h-full min-h-0 flex-col overflow-hidden bg-sidebar text-sidebar-foreground"
    @keydown.capture="handleTreeKeydownCapture"
  >
    <div class="shrink-0 px-2 pt-2">
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
          'group relative flex w-full items-center gap-2 rounded-md border border-transparent pl-2 py-1 transition-all duration-150',
          rootDropMode ? 'bg-tree-drop ring-1 ring-ring' : '',
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
            variant="ghost"
            size="toolbar"
            :aria-label="$t('labels.contentTree.search.open')"
            :title="$t('labels.contentTree.search.open')"
            :aria-expanded="searchOpen"
            :class="searchOpen ? 'text-primary' : ''"
            @pointerdown.prevent
            @click.stop="toggleSearch()"
          >
            <Icon name="lucide:search" />
          </Button>

          <Button
            v-if="canManageContent"
            variant="ghost"
            size="toolbar"
            :aria-label="$t('labels.contents.createContent')"
            @click.stop="initCreate(null)"
          >
            <Icon name="lucide:plus" />
          </Button>

          <Button
            v-if="buildRootMenuActions().length > 0"
            variant="ghost"
            size="toolbar"
            class="opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-within:opacity-100"
            :aria-label="$t('labels.contentTree.actions.more')"
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

      <Transition
        enter-active-class="transition duration-150 ease-butter"
        leave-active-class="transition duration-150 ease-butter"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-2"
      >
        <div
          v-if="searchOpen"
          ref="searchPanelRef"
          class="mt-1 flex items-center gap-2 rounded-md border border-border bg-background py-1 pr-1 pl-2 shadow-sm"
        >
          <Icon
            name="lucide:search"
            class="shrink-0 text-muted"
          />
          <input
            ref="searchInputRef"
            v-model="searchQuery"
            type="text"
            :placeholder="$t('labels.contentTree.search.placeholder')"
            :aria-label="$t('labels.contentTree.search.open')"
            class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted"
            @keydown="handleSearchInputKeydown"
            @blur="handleSearchBlur"
          />
          <span
            v-if="searchCounterText"
            aria-live="polite"
            :class="[
              'shrink-0 text-xs tabular-nums',
              searchMatches.length > 0 ? 'text-muted' : 'text-destructive',
            ]"
          >
            {{ searchCounterText }}
          </span>
          <Button
            variant="ghost"
            size="toolbar"
            :aria-label="$t('labels.contentTree.search.close')"
            @click="closeSearch()"
          >
            <Icon name="lucide:x" />
          </Button>
        </div>
      </Transition>
    </div>

    <VerticalScrollArea class="min-h-0 w-full flex-1">
      <TreeRoot
        v-slot="{ flattenItems }"
        v-model:expanded="treeExpanded"
        class="w-full list-none p-2 pt-1 select-none"
        :class="{ 'pb-8': hasClipboardItem }"
        :items="visibleRootItems"
        :get-children="getVisibleChildren"
        :get-key="({ id }) => id"
      >
        <div
          v-if="isLoading"
          aria-hidden="true"
        >
          <div
            v-for="(row, index) in skeletonRows"
            :key="index"
            class="my-0.5 flex h-7 items-center gap-2 py-1 pr-2"
            :style="{
              paddingLeft: `${row.indent}rem`,
              opacity: 1 - index * 0.11,
            }"
          >
            <Skeleton class="size-4 shrink-0" />
            <Skeleton :class="['h-4', row.width]" />
          </div>
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
          :data-content-id="item.value.id"
          :data-renaming="currentlyEditingId === item.value.id || undefined"
          :class="[
            'group relative my-0.5 flex items-center gap-2 rounded-md border border-transparent py-1 pr-2 pl-0 outline-none',
            'transition-colors duration-150 hover:bg-tree-hover',
            'focus-visible:ring-1 focus-visible:ring-ring',
            'cursor-pointer font-semibold',
            item.value.id === selectedItemId ? 'text-tree-selected-foreground' : '',
            isCutItem(item.value.id) ? 'opacity-50' : '',
            isItemSelected(item.value.id)
              ? 'bg-tree-selected border-tree-selected-border text-tree-selected-foreground'
              : '',
            dropResolution?.containerRowId === item.value.id
              ? 'bg-tree-drop ring-1 ring-ring/40'
              : '',
          ]"
          @mouseenter="startDetailPrefetch(item.value.id)"
          @mouseleave="cancelDetailPrefetch(item.value.id)"
          @focus="startDetailPrefetch(item.value.id)"
          @blur="cancelDetailPrefetch(item.value.id)"
          @pointerdown="handleItemPointerDown($event, item.value.id)"
          @click="handleItemNavigate($event, item.value.id)"
          @contextmenu="handleItemContextMenu($event, item.value.id)"
          @keydown="handleItemKeydown($event, item.value)"
          @toggle="handleToggle"
        >
          <span
            v-if="item.level > 1"
            aria-hidden="true"
            class="pointer-events-none absolute -top-0.5 -bottom-0.5 bg-[linear-gradient(to_right,var(--color-tree-guide)_1px,transparent_1px)] bg-[length:1rem_100%] bg-repeat-x"
            :style="{ left: guideOffset(1), width: `${item.level - 1}rem` }"
          />
          <span
            v-if="dropGuide && isInDropGuideSubtree(item.value.id)"
            aria-hidden="true"
            class="pointer-events-none absolute -top-0.5 -bottom-0.5 w-0.5 -translate-x-px bg-info"
            :style="{ left: guideOffset(dropGuide.level) }"
          />
          <DropIndicator
            v-if="dropResolution && dropResolution.lineRowId === item.value.id"
            :edge="dropResolution.side"
            gap="4px"
            inset="6px"
            :indent="dropLineIndent(dropResolution.level)"
            :label="dropResolution.label"
          />

          <button
            v-if="
              searchVisibleIds ? getVisibleChildren(item.value).length > 0 : item.value.children
            "
            class="z-10 h-4 w-3 cursor-pointer"
            :aria-label="$t('actions.toggleExpand')"
            :aria-expanded="isExpanded"
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
            :highlight="searchHighlightMap.get(item.value.id)"
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
              v-else-if="item.value.drf"
              :tooltip="`${$t('labels.contentTree.status.pendingChanges')} · ${item.value.pat}`"
            >
              <div
                class="h-2 w-2 rounded-full bg-text-muted ring-1 ring-success ring-offset-1 ring-offset-background"
              />
            </SimpleTooltip>
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
          <DropdownMenuSub v-if="action.children">
            <DropdownMenuSubTrigger :disabled="action.disabled">
              <Icon :name="`lucide:${action.icon}`" />
              <span>{{ action.label }}</span>
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
              <DropdownMenuItem
                v-for="child in action.children"
                :key="child.id"
                :disabled="child.disabled"
                @select.prevent="handleActionSelect(child)"
              >
                <Icon :name="`lucide:${child.icon}`" />
                <span>{{ child.label }}</span>
              </DropdownMenuItem>
            </DropdownMenuSubContent>
          </DropdownMenuSub>
          <DropdownMenuItem
            v-else
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
