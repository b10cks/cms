<script setup lang="ts">
import { unrefElement, usePreferredReducedMotion } from '@vueuse/core'
import { useSortable, type UseSortableOptions } from '@vueuse/integrations/useSortable'
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
import { resolveItemBlock } from '~/lib/blockItemTitle'
import {
  type BlockPasteRejection,
  type BlockPickerRequest,
  checkBlockPaste,
  isBlockAllowed,
} from '~/lib/blockTreeEdits'
import { buildContentPath, buildPreviewUrl } from '~/lib/preview-url'
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
  const environment =
    (settings.value.content.environment as SpaceEnvironment | null) ??
    spaceSettings?.environments?.find((entry) => entry.name === spaceSettings.default_environment)
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
const openItems = ref<string[]>([])

// Only the grip starts a drag, so text inside an item can still be selected.
// Sortable leaves `draggable="false"` on a dropped item, which is why the
// handle must not be the `draggable` attribute itself. Fallback mode draws
// the clone and placeholder ourselves instead of the browser's snapshot.
// Handles and items are scoped to this list, so a nested blocks field never
// picks up the grips of its parent list or the other way round.
const sortId = useId()
const handleSelector = `[data-block-handle="${sortId}"]`
const reducedMotion = usePreferredReducedMotion()
const sortAnimation = () => (reducedMotion.value === 'reduce' ? 0 : 150)

// The sortablejs `Options` type doesn't resolve in this project, hence the cast.
const { option: setSortableOption } = useSortable(accordionContainer, blockItems, {
  handle: handleSelector,
  draggable: '>[data-block-item]',
  forceFallback: true,
  fallbackOnBody: true,
  fallbackTolerance: 4,
  animation: sortAnimation(),
  forceAutoScrollFallback: true,
  scrollSensitivity: 80,
  scrollSpeed: 14,
  ghostClass: 'blocks-sortable-ghost',
  dragClass: 'blocks-sortable-drag',
  chosenClass: 'blocks-sortable-chosen',
  onEnd: () => {
    lastSortEndedAt = Date.now()
  },
} as UseSortableOptions)

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
  unrefElement(accordionContainer)?.querySelectorAll<HTMLElement>(handleSelector)[position]?.focus()
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

const deleteItem = (index: number) => {
  const previousItems = [...blockItems.value]
  const removedId = previousItems[index]?.id
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
  router.push({
    ...route,
    hash: `#${itemId}`,
  })
}

const forwardFieldUpdate = (payload: ContentFieldUpdatePayload) => {
  emit('fieldUpdate', payload)
}

const getItemAccordionValue = (content: Record<string, unknown>, index: number) =>
  `content-${(content.id as string) || index}`

const getItemPathPrefix = (index: number) => [...(props.pathPrefix || []), index]

const getItemFieldPath = (index: number) =>
  `content.${getItemPathPrefix(index).map(String).join('.')}`

const getVisibleItemError = (index: number) => {
  const basePath = getItemFieldPath(index)
  const directEntry = getVisibleValidationEntries?.(basePath)?.[0] || null

  if (directEntry?.messages?.[0]) return directEntry.messages[0]

  const descendantEntry = getVisibleValidationEntries?.(`${basePath}.`)?.[0] || null

  if (!descendantEntry) return null

  return descendantEntry.messages[0] || String($t('labels.contents.validation.nestedItemHint'))
}

const hasVisibleItemError = (index: number) => Boolean(getVisibleItemError(index))

const invalidAccordionValues = computed(() =>
  blockItems.value
    .map((content, index) =>
      hasVisibleItemError(index) ? getItemAccordionValue(content, index) : null
    )
    .filter((value): value is string => Boolean(value))
)

const ensureInvalidItemsOpen = () => {
  if (invalidAccordionValues.value.length === 0) return

  openItems.value = Array.from(new Set([...openItems.value, ...invalidAccordionValues.value]))
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
    <div class="min-w-0 rounded-2xl border border-border bg-surface px-2">
      <div
        v-if="!props.readOnly && hasSelectedItems"
        class="flex items-center justify-between pt-2"
      >
        <div class="text-xs font-medium text-muted-foreground">
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
        class="relative pt-2"
        @click.capture="suppressClickAfterSort"
      >
        <AccordionItem
          v-for="(content, i) in blockItems"
          :key="(content.id as string) || i"
          :value="getItemAccordionValue(content, i)"
          data-block-item
          :class="[
            'relative mb-2 min-w-0 rounded-lg border bg-background p-2 transition-colors',
            hasVisibleItemError(i) ? 'border-destructive/40 bg-destructive/5' : 'border-border',
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
                  isSelected(i) ? 'opacity-100' : 'opacity-0 group-hover:opacity-100',
                ]"
                @update:model-value="(checked) => toggleSelected(i, checked)"
              />
            </div>
            <div
              class="flex w-full items-center gap-2"
              @keydown.alt.up.prevent.stop="moveItem(i, -1)"
              @keydown.alt.down.prevent.stop="moveItem(i, 1)"
            >
              <div
                v-if="!props.readOnly"
                role="button"
                tabindex="0"
                :data-block-handle="sortId"
                :aria-label="$t('actions.blocks.tooltips.reorder')"
                :title="$t('actions.blocks.tooltips.reorder')"
                class="-m-1 flex shrink-0 cursor-grab touch-none items-center rounded-sm p-1 text-muted-foreground select-none hover:text-foreground focus-visible:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                @keydown.up.prevent.stop="moveItemFromHandle(i, -1)"
                @keydown.down.prevent.stop="moveItemFromHandle(i, 1)"
              >
                <Icon name="lucide:grip-vertical" />
              </div>
              <AccordionTrigger class="flex min-w-0 grow items-center gap-2">
                <BlockHeader
                  :content="content"
                  :block="getBlockHeaderBlock(content)"
                />
              </AccordionTrigger>
              <div
                class="flex items-center gap-2 opacity-0 group-hover:opacity-100 has-[[data-state=open]]:opacity-100"
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
                  class="flex transform cursor-pointer items-center hover:text-primary"
                  @click.stop="toggleHidden(i)"
                >
                  <Icon :name="content.hidden ? 'lucide:eye-off' : 'lucide:eye'" />
                </button>
                <button
                  v-if="content.id"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.editNested')"
                  :title="$t('actions.blocks.tooltips.editNested')"
                  class="flex transform cursor-pointer items-center hover:text-primary"
                  @click.stop="navigateToItem(content.id as string)"
                >
                  <Icon name="lucide:edit-3" />
                </button>
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.copy')"
                  :title="$t('actions.blocks.tooltips.copy')"
                  class="flex transform cursor-pointer items-center hover:text-primary"
                  @click.stop="copyItems(i)"
                >
                  <Icon name="lucide:copy" />
                </button>
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.cut')"
                  :title="$t('actions.blocks.tooltips.cut')"
                  class="flex transform cursor-pointer items-center hover:text-primary"
                  @click.stop="cutItems(i)"
                >
                  <Icon name="lucide:scissors" />
                </button>
                <button
                  v-if="!props.readOnly"
                  type="button"
                  :aria-label="$t('actions.blocks.tooltips.delete')"
                  :title="$t('actions.blocks.tooltips.delete')"
                  class="flex transform cursor-pointer items-center hover:text-red-500"
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
            </div>
            <div
              v-if="!isItemOpen(content, i) && getItemSubtreePresence(content).length > 0"
              class="pointer-events-none absolute top-1/2 right-1 z-10 flex -translate-y-1/2 items-center transition-opacity group-hover:opacity-0"
            >
              <AvatarList
                :users="getItemSubtreePresence(content)"
                :max="3"
                size="sm"
              />
            </div>
          </AccordionHeader>
          <AccordionContent>
            <div class="mt-2 grid items-start gap-4 border-t-2 border-surface p-1 pt-2">
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
          @update:open="(open: boolean) => setPickerOpen(blockItems.length, open)"
          @paste="pasteItems"
          @select="
            ({ blockSlug, template }: { blockSlug: string; template: BlockTemplate | null }) =>
              addItem(blockSlug, blockItems.length, template)
          "
        />
        <div
          v-if="canPaste"
          class="mt-2 flex justify-center pb-2"
        >
          <Button
            type="button"
            :title="$t('actions.blocks.tooltips.paste')"
            variant="ghost"
            size="xs"
            class="relative z-10"
            @click="pasteItems()"
          >
            <Icon
              name="lucide:clipboard-paste"
              size="0.75rem"
            />
            <span>{{ $t('actions.paste') }}</span>
          </Button>
        </div>
      </AccordionRoot>
    </div>
  </div>
</template>

<style>
/* Placeholder left in the list while an item is dragged. */
.blocks-sortable-ghost {
  border-style: dashed;
  border-color: var(--color-ring);
  background-color: var(--color-tree-drop);
}

.blocks-sortable-ghost > * {
  visibility: hidden;
}

/* The clone that follows the pointer. */
.blocks-sortable-drag {
  box-shadow: var(--shadow-soft-lg);
  cursor: grabbing;
}
</style>
