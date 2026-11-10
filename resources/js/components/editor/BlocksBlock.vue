<script setup lang="ts">
import { unrefElement, usePreferredReducedMotion } from '@vueuse/core'
import { useSortable } from '@vueuse/integrations/useSortable'
import {
  AccordionContent,
  AccordionHeader,
  AccordionItem,
  AccordionRoot,
  AccordionTrigger,
} from 'reka-ui'
import { toast } from 'vue-sonner'

import AddDropdown from '~/components/editor/AddDropdown.vue'
import BlockHeader from '~/components/editor/BlockHeader.vue'
import BlockItemMenu from '~/components/editor/BlockItemMenu.vue'
import Icon from '~/components/Icon.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import SortHandle from '~/components/ui/SortHandle.vue'
import Label from '~/components/ui/form/Label.vue'
import type {
  CollaborationPresenceUser,
  ContentBlockOperationPayload,
  ContentFieldFocusPayload,
  ContentFieldUpdatePayload,
} from '~/composables/useContentLiveCollaboration'
import { resolveItemLimits } from '~/composables/useContentSchemaState'
import type { ContentTreeItem } from '~/composables/useContentTree'
import { type ClipboardItem, clipboardItemBlocks } from '~/composables/useGlobalClipboard'
import {
  createBlockItemWithDefaults,
  createContentDefaultsBlockLookup,
  hydrateContentWithSchema,
  switchBlockItemType,
} from '~/composables/useSchemaDefaults'
import { blockItemPlainTitle, resolveItemBlock } from '~/lib/blockItemTitle'
import {
  type BlockListViewState,
  type BlockPasteRejection,
  type BlockPickerRequest,
  blockRowValue,
  checkBlockPaste,
  createBlockListView,
  DRILL_IN_DEPTH,
  isBlockAllowed,
} from '~/lib/blockTreeEdits'
import type { FieldReveal } from '~/lib/commentLocation'
import { scrollIntoViewAfterExpand } from '~/lib/fieldFocus'
import { buildContentPath, buildPreviewUrl, resolveEnvironment } from '~/lib/preview-url'
import { sortableOptions, sortAnimation } from '~/lib/sortable'
import type { ContentResource } from '~/types/contents'

import EditorComponent from './EditorComponent.vue'

const { $t } = useI18n()

const props = defineProps<{
  item: BlocksSchema & { key: string }
  modelValue?: Array<Record<string, unknown>> | null
  spaceId: string
  pathPrefix?: Array<string | number>
  readOnly?: boolean
}>()

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

/** Blocks lists above this one in the current column. An opened item starts again at 0. */
const depth = inject('blocksDepth', 0)
provide('blocksDepth', depth + 1)

const ulid = useUlid()
const route = useRoute()
const router = useRouter()

const { useAbility } = useAuthorization()
const canManageBlocks = useAbility('blocks.manage', { space_id: props.spaceId })

const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(props.spaceId)
const { settings } = useSpaceSettings(props.spaceId)
const editedContent = inject<Ref<ContentResource | null>>('content', ref(null))

/** Where the edited content lives on the site; item links append `#<item id>`. */
const pageLinks = computed(() => {
  const page = editedContent.value
  const spaceSettings = currentSpace.value?.settings
  const environment = resolveEnvironment(
    settings.value.content.environment as SpaceEnvironment | null,
    spaceSettings
  )
  const segment = settings.value.content.siteLocale

  return {
    url: buildPreviewUrl(
      environment?.url,
      spaceSettings,
      page?.language_iso,
      page?.full_slug,
      segment
    ),
    path: buildContentPath(spaceSettings, page?.language_iso, page?.full_slug, segment),
  }
})

const {
  copyItem: globalCopyItem,
  cutItem: globalCutItem,
  pasteItem: globalPasteItem,
  getClipboardItem,
  clipboardItem,
} = useGlobalClipboard()

const emit = defineEmits<{
  'update:modelValue': [value: Array<Record<string, unknown>>]
  createTemplate: [blockId: string, content: Record<string, unknown>]
  fieldUpdate: [payload: ContentFieldUpdatePayload]
  fieldFocus: [payload: ContentFieldFocusPayload]
  blockOperation: [payload: ContentBlockOperationPayload]
}>()

const getActiveCollaborators = inject<
  (itemId: string, field: string) => CollaborationPresenceUser[]
>('getActiveCollaborators', () => [])
const getSubtreeCollaborators = inject<
  ((itemId: string) => CollaborationPresenceUser[]) | undefined
>('getSubtreeCollaborators', undefined)
const getDraftOwners = inject<
  ((itemId: string, field?: string) => CollaborationPresenceUser[]) | undefined
>('getDraftOwners', undefined)
const getVisibleValidationEntries = inject<
  ((prefix?: string) => Array<{ path: string; messages: string[] }>) | undefined
>('getVisibleValidationEntries', undefined)
const submitValidationAttempted = inject<Ref<boolean> | undefined>(
  'submitValidationAttempted',
  undefined
)

/**
 * The preview asked to insert a block into this list: open the picker at that
 * position. Keyed by the list's path from the content root.
 */
const blockPickerRequest = inject<Ref<BlockPickerRequest | null>>('blockPickerRequest', ref(null))
const openPickerIndex = ref<number | null>(null)

watch(
  blockPickerRequest,
  (request) => {
    if (!request || request.path !== (props.pathPrefix || []).join('.')) return

    openPickerIndex.value = request.index
    blockPickerRequest.value = null
  },
  { immediate: true }
)

const setPickerOpen = (index: number, open: boolean) => {
  if (open) openPickerIndex.value = index
  else if (openPickerIndex.value === index) openPickerIndex.value = null
}

const getBlockHeaderBlock = (content: Record<string, unknown>) =>
  resolveItemBlock(blocks.value?.data, content)

const handlebars = useHandlebars()
const getItemLabel = (content: Record<string, unknown>) =>
  blockItemPlainTitle(content, getBlockHeaderBlock(content), (template, data) =>
    handlebars.render(template, data)
  )

/** An item with no id has no route of its own, so it stays expandable. */
const drillsIn = (content: Record<string, unknown>) =>
  depth >= DRILL_IN_DEPTH && typeof content.id === 'string' && content.id !== ''

const emitBlockOperation = (
  operation: ContentBlockOperationPayload,
  previousItems: Array<Record<string, unknown>>
) => {
  emit('blockOperation', { ...operation, previousValue: [...previousItems] })
}

// A pure reorder (same ids, different order) can only come from drag sorting —
// every other mutation site emits its own, more specific operation.
const deriveReorderOperation = (
  previousItems: Array<Record<string, unknown>>,
  nextItems: Array<Record<string, unknown>>
): ContentBlockOperationPayload | null => {
  if (previousItems.length !== nextItems.length) return null

  const previousIds = previousItems.map((item) => item.id)
  const nextIds = nextItems.map((item) => item.id)

  if (!previousIds.every((id) => typeof id === 'string')) return null
  if (new Set(previousIds).size !== previousIds.length) return null
  if (previousIds.every((id, index) => id === nextIds[index])) return null

  const previousIdSet = new Set(previousIds)
  if (!nextIds.every((id) => typeof id === 'string' && previousIdSet.has(id))) return null

  return { type: 'reorder', order: nextIds as string[] }
}

const blockItems = computed({
  get: () => props.modelValue || [],
  set: (newValue) => {
    if (newValue === props.modelValue) return

    const previousItems = props.modelValue || []
    const reorderOperation = deriveReorderOperation(previousItems, newValue)
    if (reorderOperation) {
      emitBlockOperation(reorderOperation, previousItems)
    }

    emit('update:modelValue', [...newValue])
  },
})

const accordionContainer = ref<HTMLElement | null>(null)
const selectedIndexes = ref<number[]>([])
const listView = inject<BlockListViewState>('blockListView', createBlockListView, true)
const openItems = listView.openItems

// Only the grip starts a drag, so text inside an item can still be selected.
// Sortable leaves `draggable="false"` on a dropped item, which is why the
// handle must not be the `draggable` attribute itself. Fallback mode draws
// the clone and placeholder ourselves instead of the browser's snapshot.
// Handles and items are scoped to this list, so a nested blocks field never
// picks up the grips of its parent list or the other way round.
const sortId = useId()
const handleSelector = `[data-block-handle="${sortId}"]`
const reducedMotion = usePreferredReducedMotion()

const { option: setSortableOption } = useSortable(
  accordionContainer,
  blockItems,
  sortableOptions({
    handle: handleSelector,
    draggable: '>[data-block-item]',
    onEnd: () => {
      lastSortEndedAt = Date.now()
    },
  })
)

watch(reducedMotion, () => setSortableOption('animation', sortAnimation()))

// Releasing a drag fires a click on the header under the pointer, which would
// toggle that item open. Swallow the click that directly follows a sort.
let lastSortEndedAt = 0
const suppressClickAfterSort = (event: MouseEvent) => {
  if (Date.now() - lastSortEndedAt < 300) {
    event.stopPropagation()
    event.preventDefault()
  }
}

/**
 * Alt+Up/Down on a focused block header. Writes through the same `blockItems`
 * setter the drag handle uses, so the reorder operation broadcast to
 * collaborators is identical.
 */
const moveItem = (index: number, direction: -1 | 1) => {
  if (props.readOnly) return false

  const items = [...blockItems.value]
  const target = index + direction
  if (target < 0 || target >= items.length) return false

  const [moved] = items.splice(index, 1)
  items.splice(target, 0, moved)
  blockItems.value = items
  return true
}

/** Read out by screen readers after a keyboard move. */
const moveAnnouncement = ref('')

/**
 * Up/Down on a focused grip. The item's DOM node moves, which drops focus, so
 * the grip at the new position takes it back.
 */
const moveItemFromHandle = async (index: number, direction: -1 | 1) => {
  if (!moveItem(index, direction)) return

  const position = index + direction
  moveAnnouncement.value = String(
    $t('actions.blocks.moved', { position: position + 1, count: blockItems.value.length })
  )

  await nextTick()
  focusHandle(position)
}

/** The grip at `position`, the last one when the list got shorter, the Add button when empty. */
const focusHandle = (position: number) => {
  const list = unrefElement(accordionContainer)
  const handles = list?.querySelectorAll<HTMLElement>(handleSelector)
  if (handles?.length) handles[Math.min(position, handles.length - 1)].focus()
  else list?.querySelector('button')?.focus()
}

useShortcut({
  keys: 'alt+arrowup',
  scope: 'fields',
  description: () => $t('shortcuts.fields.moveBlockUp'),
  handler: null,
})

useShortcut({
  keys: 'alt+arrowdown',
  scope: 'fields',
  description: () => $t('shortcuts.fields.moveBlockDown'),
  handler: null,
})

const blocksBySlug = computed(() =>
  Object.fromEntries((blocks.value?.data || []).map((block) => [block.slug, block]))
)
const blockLookup = computed<Record<string, Pick<BlockResource, 'slug' | 'schema'>>>(() =>
  createContentDefaultsBlockLookup(blocks.value?.data || [])
)
const allowedBlocks = computed(() =>
  (blocks.value?.data || []).filter((block) => isBlockAllowed(props.item, block))
)

const maxItems = computed(() => resolveItemLimits(props.item).max)
/** A full field offers no way to add more, by hand or by paste. */
const canAdd = computed(
  () => !props.readOnly && (maxItems.value === null || blockItems.value.length < maxItems.value)
)

const checkPaste = (item: ClipboardItem) =>
  checkBlockPaste(
    { spaceId: item.spaceId, items: clipboardItemBlocks(item) },
    {
      spaceId: props.spaceId,
      field: props.item,
      length: blockItems.value.length,
      max: maxItems.value,
      lookup: (slug) => blocksBySlug.value[slug],
    }
  )

/** Paste is offered only when this field takes everything on the clipboard. */
const canPaste = computed(
  () => canAdd.value && !!clipboardItem.value && !checkPaste(clipboardItem.value)
)

const pasteRejectionMessage = (rejection: BlockPasteRejection) => {
  switch (rejection.reason) {
    case 'space':
      return $t('labels.contents.paste.otherSpace')
    case 'max':
      return $t('labels.contents.paste.tooMany', { max: rejection.max })
    case 'block':
      return $t('labels.contents.paste.blockNotAllowed', {
        name: blocksBySlug.value[rejection.slug]?.name ?? rejection.slug,
      })
  }
}

const insertItem = (item: Record<string, unknown>, index: number = -1) => {
  // A collaborator may have filled the field while the picker was open.
  if (!canAdd.value) return

  const previousItems = [...blockItems.value]
  const updatedItems = [...previousItems]
  const insertIndex = index === -1 ? updatedItems.length : index

  updatedItems.splice(insertIndex, 0, item)
  openItems.value = [...openItems.value, getItemAccordionValue(item, insertIndex)]

  emitBlockOperation({ type: 'add', index: insertIndex, items: [item] }, previousItems)
  emit('update:modelValue', updatedItems)

  // A row at this depth has nowhere to expand, so the new item opens to be filled in.
  if (drillsIn(item)) navigateToItem(item.id as string)
}

const addItem = (slug: string, index: number = -1, template?: BlockTemplate | null) => {
  const block = blocksBySlug.value[slug]
  const templateContent =
    template && typeof template.content === 'object' && !Array.isArray(template.content)
      ? template.content
      : undefined

  if (!block) {
    insertItem({ block: slug, id: ulid(), ...templateContent }, index)
    return
  }

  const baseItem = createBlockItemWithDefaults(block, blocksBySlug.value)
  const hydratedTemplateContent = templateContent
    ? hydrateContentWithSchema(block.schema, templateContent, blockLookup.value)
    : null

  const newItem = hydratedTemplateContent
    ? {
        ...baseItem,
        ...hydratedTemplateContent,
        id: (hydratedTemplateContent.id as string) || baseItem.id,
        block: slug,
      }
    : baseItem

  insertItem(newItem, index)
}

/** Undo stays on offer only while this list shows the entry the item was removed from. */
const undoToasts: Array<string | number> = []
const dismissUndoToasts = () => {
  undoToasts.splice(0).forEach((id) => toast.dismiss(id))
}
watch(() => editedContent.value?.id, dismissUndoToasts)
onBeforeUnmount(dismissUndoToasts)

/** Puts removed items back on their old indexes, given in ascending order. */
const restoreItems = (entries: Array<{ item: Record<string, unknown>; index: number }>) => {
  const previousItems = [...blockItems.value]
  // A discard or a collaborator's undo may have brought some back already.
  const missing = entries.filter(
    ({ item }) =>
      !(typeof item.id === 'string' && item.id !== '' && previousItems.some((entry) => entry.id === item.id))
  )
  if (missing.length === 0) return

  if (props.readOnly || (maxItems.value !== null && previousItems.length + missing.length > maxItems.value)) {
    toast.error(String($t('labels.contents.paste.tooMany', { max: maxItems.value })))
    return
  }

  const updatedItems = [...previousItems]
  const positions: number[] = []
  for (const { item, index } of missing) {
    const position = Math.min(index, updatedItems.length)
    const snapshot = [...updatedItems]
    updatedItems.splice(position, 0, item)
    positions.push(position)
    emitBlockOperation(
      typeof item.id === 'string' && item.id !== ''
        ? { type: 'add', index: position, items: [item] }
        : { type: 'replace', items: [...updatedItems] },
      snapshot
    )
  }

  emit('update:modelValue', updatedItems)
  selectedIndexes.value = positions.reduce(
    (selected, position) => selected.map((index) => (index >= position ? index + 1 : index)),
    selectedIndexes.value
  )

  nextTick(() => focusHandle(positions[0]))
}

const deleteItem = (index: number) => {
  const previousItems = [...blockItems.value]
  const removed = previousItems[index]
  const removedId = removed?.id
  const updatedItems = [...previousItems]
  updatedItems.splice(index, 1)

  if (typeof removedId === 'string' && removedId) {
    emitBlockOperation({ type: 'remove', itemIds: [removedId] }, previousItems)
  } else {
    emitBlockOperation({ type: 'replace', items: updatedItems }, previousItems)
  }

  blockItems.value = updatedItems
  selectedIndexes.value = selectedIndexes.value
    .filter((selectedIndex) => selectedIndex !== index)
    .map((selectedIndex) => (selectedIndex > index ? selectedIndex - 1 : selectedIndex))

  undoToasts.push(
    toast(String($t('actions.blocks.removed', { name: getItemLabel(removed) })), {
      duration: 8000,
      action: { label: String($t('actions.undo')), onClick: () => restoreItems([{ item: removed, index }]) },
    })
  )

  // The closing menu hands focus back after the row is gone, and its trigger went with the row.
  nextTick(() => setTimeout(() => focusHandle(index)))
}

/** Removes every selected item at once, with one undo that puts them all back. */
const deleteSelectedItems = () => {
  const indexes = [...selectedIndexes.value].sort((a, b) => a - b)
  if (indexes.length === 0) return

  const previousItems = [...blockItems.value]
  const removed = indexes.map((index) => ({ index, item: previousItems[index] }))
  const removedIndexes = new Set(indexes)
  const updatedItems = previousItems.filter((_, index) => !removedIndexes.has(index))

  const removedIds = removed.map(({ item }) => item.id)
  if (removedIds.every((id): id is string => typeof id === 'string' && id !== '')) {
    emitBlockOperation({ type: 'remove', itemIds: removedIds }, previousItems)
  } else {
    emitBlockOperation({ type: 'replace', items: updatedItems }, previousItems)
  }

  blockItems.value = updatedItems
  selectedIndexes.value = []

  undoToasts.push(
    toast(String($t('actions.blocks.removedMany', { count: removed.length })), {
      duration: 8000,
      action: { label: String($t('actions.undo')), onClick: () => restoreItems(removed) },
    })
  )
}

const getClipboardLabel = (items: Array<Record<string, unknown>>) => {
  if (items.length === 1) {
    return items[0].block as string
  }

  return `${items.length} items`
}

const toggleSelected = (index: number, checked: boolean | 'indeterminate') => {
  if (checked) {
    if (!selectedIndexes.value.includes(index)) {
      selectedIndexes.value = [...selectedIndexes.value, index].sort((a, b) => a - b)
    }
    return
  }

  selectedIndexes.value = selectedIndexes.value.filter((selectedIndex) => selectedIndex !== index)
}

const isSelected = (index: number) => selectedIndexes.value.includes(index)

const selectedItems = computed(() =>
  selectedIndexes.value
    .filter((index) => index >= 0 && index < blockItems.value.length)
    .map((index) => blockItems.value[index])
)

const hasSelectedItems = computed(() => selectedItems.value.length > 0)
const isAllSelected = computed(
  () => blockItems.value.length > 0 && selectedIndexes.value.length === blockItems.value.length
)

const selectAllItems = () => {
  if (isAllSelected.value) {
    selectedIndexes.value = []
    return
  }

  selectedIndexes.value = blockItems.value.map((_, index) => index)
}

const getActionIndexes = (index?: number) => {
  if (typeof index === 'number') {
    return isSelected(index) ? [...selectedIndexes.value].sort((a, b) => a - b) : [index]
  }

  return [...selectedIndexes.value].sort((a, b) => a - b)
}

const isClipboardBlockItem = (value: unknown): value is Record<string, unknown> =>
  !!value && typeof value === 'object' && !Array.isArray(value)

const normalizeClipboardItems = (
  pastedItem: Record<string, unknown> | Record<string, unknown>[] | null
) => {
  if (!pastedItem) return []

  if (Array.isArray(pastedItem)) {
    return pastedItem.filter(isClipboardBlockItem).map((item) => ({ ...item }))
  }

  return [pastedItem]
}

const copyItems = async (index?: number) => {
  const indexes = getActionIndexes(index)
  if (indexes.length === 0) return

  const itemsToCopy = indexes.map((itemIndex) => ({ ...blockItems.value[itemIndex] }))
  const payload = itemsToCopy.length === 1 ? itemsToCopy[0] : itemsToCopy

  await globalCopyItem(payload, props.spaceId, getClipboardLabel(itemsToCopy))
}

const cutItems = async (index?: number) => {
  const indexes = getActionIndexes(index)
  if (indexes.length === 0) return

  const itemsToCut = indexes.map((itemIndex) => ({ ...blockItems.value[itemIndex] }))
  const payload = itemsToCut.length === 1 ? itemsToCut[0] : itemsToCut

  await globalCutItem(payload, props.spaceId, getClipboardLabel(itemsToCut))

  const previousItems = [...blockItems.value]
  const updatedItems = [...previousItems]
  indexes
    .sort((a, b) => b - a)
    .forEach((itemIndex) => {
      updatedItems.splice(itemIndex, 1)
    })

  const removedIds = itemsToCut.map((item) => item.id)
  if (removedIds.every((id): id is string => typeof id === 'string' && id !== '')) {
    emitBlockOperation({ type: 'remove', itemIds: removedIds }, previousItems)
  } else {
    emitBlockOperation({ type: 'replace', items: updatedItems }, previousItems)
  }

  blockItems.value = updatedItems
  selectedIndexes.value = []
}

const pasteItems = async (event?: ClipboardEvent | null, insertIndex?: number) => {
  if (event) {
    event.stopPropagation()
    event.preventDefault()
  }

  // The clipboard may have changed since the paste button was shown.
  const clipboard = await getClipboardItem()
  if (!clipboard) return

  const rejection = checkPaste(clipboard)
  if (rejection) {
    toast.error(String(pasteRejectionMessage(rejection)))
    return
  }

  const pastedItems = normalizeClipboardItems(await globalPasteItem(clipboard))

  if (pastedItems.length > 0) {
    const previousItems = [...blockItems.value]
    const updatedItems = [...previousItems]
    const index = insertIndex ?? updatedItems.length
    updatedItems.splice(index, 0, ...pastedItems)

    // Pasting a copy keeps the source ids; a remote "add" would skip those as
    // duplicates, so ship the whole array instead.
    const existingIds = new Set(previousItems.map((item) => item.id))
    const hasDuplicateIds = pastedItems.some((item) => existingIds.has(item.id))

    emitBlockOperation(
      hasDuplicateIds
        ? { type: 'replace', items: updatedItems }
        : { type: 'add', index, items: pastedItems },
      previousItems
    )
    emit('update:modelValue', updatedItems)
    selectedIndexes.value = []
  }
}

const handleTemplateTrigger = (content: Record<string, unknown>) => {
  const block = getBlockHeaderBlock(content)
  if (!block?.id) return

  emit('createTemplate', block.id, content)
}

watch(
  () => blockItems.value.length,
  () => {
    selectedIndexes.value = selectedIndexes.value.filter((index) => index < blockItems.value.length)
  },
  { immediate: true }
)

const updateContent = (index: number, newContent: Record<string, unknown>) => {
  if (newContent === blockItems.value[index]) return

  const updatedItems = [...blockItems.value]
  updatedItems[index] = newContent
  blockItems.value = updatedItems
}

const toggleHidden = (index: number) => {
  const previousItems = [...blockItems.value]
  const item = previousItems[index]
  const updatedItems = [...previousItems]
  updatedItems[index] = { ...item, hidden: !item.hidden }

  if (typeof item.id === 'string' && item.id) {
    emitBlockOperation({ type: 'visibility', itemId: item.id, hidden: !item.hidden }, previousItems)
  } else {
    emitBlockOperation({ type: 'replace', items: updatedItems }, previousItems)
  }

  blockItems.value = updatedItems
}

const switchItemType = (index: number, slug: string) => {
  const block = blocksBySlug.value[slug]
  if (!block) return

  const previousItems = [...blockItems.value]
  const item = previousItems[index]
  const updatedItems = [...previousItems]
  updatedItems[index] = switchBlockItemType(
    item,
    blocksBySlug.value[String(item.block)],
    block,
    blocksBySlug.value
  )

  emitBlockOperation({ type: 'replace', items: updatedItems }, previousItems)
  blockItems.value = updatedItems
}

const navigateToItem = (itemId: string) => {
  listView.returnTo.value = itemId
  router.push({
    ...route,
    hash: `#${itemId}`,
  })
}

// Back from an opened item: its row takes focus, unless focus is already somewhere.
onMounted(async () => {
  const itemId = listView.returnTo.value
  if (!itemId || !blockItems.value.some((content) => content.id === itemId)) return

  await nextTick()
  if (document.activeElement !== document.body) return

  listView.returnTo.value = null
  unrefElement(accordionContainer)
    ?.querySelector<HTMLElement>(`[data-block-row="${CSS.escape(itemId)}"]`)
    ?.focus()
})

// A thread about a block in this list asked for its row.
const fieldReveal = inject<Ref<FieldReveal | null>>('fieldReveal', ref(null))
const answerRowReveal = () => {
  const row = fieldReveal.value?.row
  if (!row || !blockItems.value.some((content) => content.id === row)) return

  fieldReveal.value = null
  const element = unrefElement(accordionContainer)?.querySelector<HTMLElement>(
    `[data-block-row="${CSS.escape(row)}"]`
  )
  element?.focus({ focusVisible: true } as FocusOptions)
  scrollIntoViewAfterExpand(element)
}
onMounted(answerRowReveal)
watch(fieldReveal, answerRowReveal, { flush: 'post' })

const forwardFieldUpdate = (payload: ContentFieldUpdatePayload) => {
  emit('fieldUpdate', payload)
}

const getItemAccordionValue = (content: Record<string, unknown>, index: number) =>
  blockRowValue((content.id as string) || `${(props.pathPrefix || []).join('.')}.${index}`)

const getItemPathPrefix = (index: number) => [...(props.pathPrefix || []), index]

const getItemFieldPath = (index: number) =>
  `content.${getItemPathPrefix(index).map(String).join('.')}`

const getVisibleItemError = (index: number) => {
  const basePath = getItemFieldPath(index)
  const directEntry = getVisibleValidationEntries?.(basePath)?.[0] || null

  if (directEntry?.messages?.[0]) return directEntry.messages[0]

  // A nested field's own message ("required") means nothing without the field beside it.
  return getVisibleValidationEntries?.(`${basePath}.`)?.length
    ? String($t('labels.contents.validation.nestedItemHint'))
    : null
}

/** The row hides its fields, so the row has to say what is wrong. */
const getRowError = (content: Record<string, unknown>, index: number) =>
  drillsIn(content) || !isItemOpen(content, index) ? getVisibleItemError(index) : null

const getRowErrorId = (index: number) => `${sortId}-error-${index}`

const hasVisibleItemError = (index: number) => Boolean(getVisibleItemError(index))

const invalidAccordionValues = computed(() =>
  blockItems.value
    .map((content, index) =>
      hasVisibleItemError(index) ? getItemAccordionValue(content, index) : null
    )
    .filter((value): value is string => Boolean(value))
)

const ensureInvalidItemsOpen = () => {
  const closed = invalidAccordionValues.value.filter((value) => !openItems.value.includes(value))
  // The array is shared by every list, so an equal copy would re-render them all.
  if (closed.length > 0) openItems.value = [...openItems.value, ...closed]
}

watch(
  invalidAccordionValues,
  () => {
    nextTick(() => {
      ensureInvalidItemsOpen()
    })
  },
  { immediate: true }
)

watch(
  () => submitValidationAttempted?.value,
  (attempted, previousAttempted) => {
    if (!attempted || attempted === previousAttempted) return

    nextTick(() => {
      ensureInvalidItemsOpen()
    })
  }
)

const forwardFieldFocus = (payload: ContentFieldFocusPayload) => {
  emit('fieldFocus', payload)
}

const forwardBlockOperation = (payload: ContentBlockOperationPayload) => {
  emit('blockOperation', payload)
}

const isItemOpen = (content: Record<string, unknown>, index: number) =>
  openItems.value.includes(getItemAccordionValue(content, index))

const getItemSubtreePresence = (content: Record<string, unknown>) =>
  typeof content.id === 'string' && content.id ? getSubtreeCollaborators?.(content.id) || [] : []

const getItemDraftOwners = (content: Record<string, unknown>) =>
  typeof content.id === 'string' && content.id ? getDraftOwners?.(content.id) || [] : []

// Ring the block card in the color of whoever has unsaved changes inside it;
// validation-error styling takes priority.
const getItemRingStyle = (content: Record<string, unknown>, index: number) => {
  if (hasVisibleItemError(index)) return undefined

  const owner = getItemDraftOwners(content)[0]
  return owner ? { outline: `1px solid ${owner.color}` } : undefined
}
</script>

<template>
  <div class="grid gap-2">
    <Label
      :label="item.name || item.key || 'Untitled'"
      :required="item.required"
      class="relative z-10 mr-8"
    />
    <div
      :class="[
        'min-w-0',
        depth === 0
          ? 'rounded-2xl border border-border bg-surface px-2'
          : 'rounded-xl border border-border bg-surface px-1.5',
      ]"
    >
      <div
        v-if="!props.readOnly && hasSelectedItems"
        class="relative z-20 flex items-center justify-between pt-2"
      >
        <div class="text-sm font-medium text-muted">
          {{ selectedItems.length }} selected
        </div>
        <div class="flex items-center">
          <Button
            type="button"
            variant="ghost"
            size="xs"
            :aria-label="$t('actions.selectAll')"
            @click="selectAllItems()"
          >
            <Icon name="lucide:square-check" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="xs"
            :aria-label="$t('actions.blocks.tooltips.copy')"
            @click="copyItems()"
          >
            <Icon name="lucide:copy" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="xs"
            :aria-label="$t('actions.blocks.tooltips.cut')"
            @click="cutItems()"
          >
            <Icon name="lucide:scissors" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="xs"
            class="hover:text-destructive-ink"
            :aria-label="$t('actions.blocks.tooltips.deleteSelected')"
            :title="$t('actions.blocks.tooltips.deleteSelected')"
            @click="deleteSelectedItems()"
          >
            <Icon name="lucide:trash-2" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="xs"
            @click="selectedIndexes = []"
          >
            <span>{{ $t('actions.cancel') }}</span>
          </Button>
        </div>
      </div>

      <p
        class="sr-only"
        aria-live="polite"
      >
        {{ moveAnnouncement }}
      </p>
      <AccordionRoot
        ref="accordionContainer"
        v-model="openItems"
        type="multiple"
        :class="['relative', depth === 0 ? 'pt-2' : 'pt-1.5']"
        @click.capture="suppressClickAfterSort"
      >
        <AccordionItem
          v-for="(content, i) in blockItems"
          :key="(content.id as string) || i"
          :value="getItemAccordionValue(content, i)"
          data-block-item
          :class="[
            'relative mb-2 min-w-0 rounded-lg border bg-card py-2 transition-colors duration-100',
            depth === 0 ? 'px-3 [--row-inset:calc(0.75rem+1px)]' : 'px-2 [--row-inset:calc(0.5rem+1px)]',
            hasVisibleItemError(i) ? 'border-destructive/40 bg-destructive/5' : 'border-border hover:border-border-strong',
            content.hidden ? 'opacity-50' : '',
          ]"
          :style="getItemRingStyle(content, i)"
        >
          <AccordionHeader class="group relative">
            <AddDropdown
              :open="openPickerIndex === i"
              :item="item"
              :space-id="spaceId"
              :can-mutate="canAdd"
              :has-clipboard-item="canPaste"
              @update:open="(open: boolean) => setPickerOpen(i, open)"
              @paste="() => pasteItems(null, i)"
              @select="
                ({ blockSlug, template }: { blockSlug: string; template: BlockTemplate | null }) =>
                  addItem(blockSlug, i, template)
              "
            />
            <div
              class="absolute left-6 top-1/2 z-10 -translate-y-1/2"
              @click.stop
            >
              <Checkbox
                v-if="!props.readOnly"
                :model-value="isSelected(i)"
                :aria-label="`Select ${content.block || `item ${i + 1}`}`"
                :class="[
                  'transition-opacity',
                  isSelected(i)
                    ? 'opacity-100'
                    : 'opacity-0 group-focus-within:opacity-100 group-hover:opacity-100',
                ]"
                @update:model-value="(checked) => toggleSelected(i, checked)"
              />
            </div>
            <div
              class="flex w-full items-center gap-2"
              @keydown.alt.up.prevent.stop="moveItem(i, -1)"
              @keydown.alt.down.prevent.stop="moveItem(i, 1)"
            >
              <SortHandle
                v-if="!props.readOnly"
                :data-block-handle="sortId"
                @move="(delta) => moveItemFromHandle(i, delta)"
              />
              <button
                v-if="drillsIn(content)"
                type="button"
                :data-block-row="content.id"
                :aria-describedby="getRowError(content, i) ? getRowErrorId(i) : undefined"
                class="flex min-w-0 grow cursor-pointer items-center gap-2 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="navigateToItem(content.id as string)"
              >
                <BlockHeader
                  :content="content"
                  :block="getBlockHeaderBlock(content)"
                />
              </button>
              <AccordionTrigger
                v-else
                :data-block-row="content.id"
                :aria-describedby="getRowError(content, i) ? getRowErrorId(i) : undefined"
                class="flex min-w-0 grow items-center gap-2 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              >
                <BlockHeader
                  :content="content"
                  :block="getBlockHeaderBlock(content)"
                />
              </AccordionTrigger>
              <div
                class="flex items-center opacity-0 group-focus-within:opacity-100 group-hover:opacity-100 has-[[data-state=open]]:opacity-100"
              >
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="
                    content.hidden
                      ? $t('actions.blocks.tooltips.show')
                      : $t('actions.blocks.tooltips.hide')
                  "
                  :title="
                    content.hidden
                      ? $t('actions.blocks.tooltips.show')
                      : $t('actions.blocks.tooltips.hide')
                  "
                  class="row-action"
                  @click.stop="toggleHidden(i)"
                >
                  <Icon :name="content.hidden ? 'lucide:eye-off' : 'lucide:eye'" />
                </button>
                <button
                  v-if="content.id"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.editNested')"
                  :title="$t('actions.blocks.tooltips.editNested')"
                  class="row-action"
                  @click.stop="navigateToItem(content.id as string)"
                >
                  <Icon name="lucide:edit-3" />
                </button>
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.copy')"
                  :title="$t('actions.blocks.tooltips.copy')"
                  class="row-action"
                  @click.stop="copyItems(i)"
                >
                  <Icon name="lucide:copy" />
                </button>
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.cut')"
                  :title="$t('actions.blocks.tooltips.cut')"
                  class="row-action"
                  @click.stop="cutItems(i)"
                >
                  <Icon name="lucide:scissors" />
                </button>
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.delete')"
                  :title="$t('actions.blocks.tooltips.delete')"
                  class="row-action hover:text-destructive-ink"
                  @click.stop="deleteItem(i)"
                >
                  <Icon name="lucide:trash-2" />
                </button>
                <BlockItemMenu
                  :space-id="spaceId"
                  :item-id="content.id as string | undefined"
                  :block="getBlockHeaderBlock(content)"
                  :allowed-blocks="allowedBlocks"
                  :page-url="pageLinks.url"
                  :page-path="pageLinks.path"
                  :can-manage-block="canManageBlocks"
                  :read-only="props.readOnly"
                  @switch-type="(slug: string) => switchItemType(i, slug)"
                  @create-template="handleTemplateTrigger(content)"
                />
              </div>
              <span
                v-if="drillsIn(content)"
                aria-hidden="true"
                class="flex shrink-0 cursor-pointer items-center text-muted"
                @click="navigateToItem(content.id as string)"
              >
                <Icon name="lucide:chevron-right" />
              </span>
            </div>
            <div
              v-if="
                (drillsIn(content) || !isItemOpen(content, i)) &&
                getItemSubtreePresence(content).length > 0
              "
              :class="[
                'pointer-events-none absolute top-1/2 z-10 flex -translate-y-1/2 items-center transition-opacity group-focus-within:opacity-0 group-hover:opacity-0',
                drillsIn(content) ? 'right-6' : 'right-1',
              ]"
            >
              <AvatarList
                :users="getItemSubtreePresence(content)"
                :max="3"
                size="sm"
              />
            </div>
          </AccordionHeader>
          <p
            v-if="getRowError(content, i)"
            :id="getRowErrorId(i)"
            class="mt-1 flex items-center gap-1 pl-6 text-sm text-foreground"
          >
            <Icon
              name="lucide:circle-alert"
              class="shrink-0 text-destructive"
            />
            <span class="truncate">{{ getRowError(content, i) }}</span>
          </p>
          <AccordionContent v-if="!drillsIn(content)">
            <div class="mt-2 grid items-start gap-4 border-t border-border pt-2">
              <div
                @focusin.stop
                @focusout.stop
              >
                <EditorComponent
                  :key="`${content.id || i}:${content.block}`"
                  :model-value="content as ContentTreeItem"
                  :block-slug="content.block as string"
                  :read-only="props.readOnly"
                  :get-active-collaborators="getActiveCollaborators"
                  :path-prefix="[...(pathPrefix || []), i]"
                  :root-id="content.id as string"
                  :space-id="spaceId"
                  is-child
                  @update:model-value="
                    (value) => value && updateContent(i, value as Record<string, unknown>)
                  "
                  @field-update="forwardFieldUpdate"
                  @field-focus="forwardFieldFocus"
                  @block-operation="forwardBlockOperation"
                />
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
        <AddDropdown
          :open="openPickerIndex === blockItems.length"
          :item="item"
          :space-id="spaceId"
          :can-mutate="canAdd"
          :has-clipboard-item="canPaste"
          placement="end"
          @update:open="(open: boolean) => setPickerOpen(blockItems.length, open)"
          @paste="pasteItems"
          @select="
            ({ blockSlug, template }: { blockSlug: string; template: BlockTemplate | null }) =>
              addItem(blockSlug, blockItems.length, template)
          "
        />
      </AccordionRoot>
    </div>
  </div>
</template>
