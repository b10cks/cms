<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'
import {
  AccordionContent,
  AccordionHeader,
  AccordionItem,
  AccordionRoot,
  AccordionTrigger,
} from 'reka-ui'

import AddDropdown from '~/components/editor/AddDropdown.vue'
import BlockHeader from '~/components/editor/BlockHeader.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import Label from '~/components/ui/form/Label.vue'
import {
  createBlockItemWithDefaults,
  createContentDefaultsBlockLookup,
  hydrateContentWithSchema,
} from '~/composables/useSchemaDefaults'
import type {
  CollaborationPresenceUser,
  ContentFieldFocusPayload,
  ContentFieldUpdatePayload,
} from '~/composables/useContentLiveCollaboration'
import type { ContentTreeItem } from '~/composables/useContentTree'

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

const {
  copyItem: globalCopyItem,
  cutItem: globalCutItem,
  pasteItem: globalPasteItem,
  hasClipboardItem,
} = useGlobalClipboard()

const emit = defineEmits<{
  'update:modelValue': [value: Array<Record<string, unknown>>]
  createTemplate: [blockId: string, content: Record<string, unknown>]
  fieldUpdate: [payload: ContentFieldUpdatePayload]
  fieldFocus: [payload: ContentFieldFocusPayload]
}>()

const getActiveCollaborators = inject<
  (itemId: string, field: string) => CollaborationPresenceUser[]
>('getActiveCollaborators', () => [])
const getVisibleValidationEntries = inject<
  ((prefix?: string) => Array<{ path: string; messages: string[] }>) | undefined
>('getVisibleValidationEntries', undefined)
const submitValidationAttempted = inject<Ref<boolean> | undefined>(
  'submitValidationAttempted',
  undefined
)

const getBlockForContent = (content: Record<string, unknown>) =>
  blocks.value?.data?.find((entry) => entry.slug === (content.block as string))

const getBlockHeaderBlock = (content: Record<string, unknown>) =>
  (getBlockForContent(content) || {
    id: '',
    slug: String(content.block || ''),
    name: String(content.block || ''),
    icon: null,
    color: null,
    preview_template: null,
  }) as BlockResource

const blockItems = computed({
  get: () => props.modelValue || [],
  set: (newValue) => {
    if (newValue === props.modelValue) return

    emit('update:modelValue', [...newValue])
  },
})

const accordionContainer = ref<HTMLElement | null>(null)
const selectedIndexes = ref<number[]>([])
const openItems = ref<string[]>([])

;(useSortable as any)(accordionContainer, blockItems, {
  handle: '[draggable]',
})

const blocksBySlug = computed(() =>
  Object.fromEntries((blocks.value?.data || []).map((block) => [block.slug, block]))
)
const blockLookup = computed<Record<string, Pick<BlockResource, 'slug' | 'schema'>>>(() =>
  createContentDefaultsBlockLookup(blocks.value?.data || [])
)

const insertItem = (item: Record<string, unknown>, index: number = -1) => {
  const updatedItems = [...blockItems.value]

  if (index === -1) {
    updatedItems.push(item)
  } else {
    updatedItems.splice(index, 0, item)
  }

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
  const updatedItems = [...blockItems.value]
  updatedItems.splice(index, 1)
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

  const updatedItems = [...blockItems.value]
  indexes
    .sort((a, b) => b - a)
    .forEach((itemIndex) => {
      updatedItems.splice(itemIndex, 1)
    })

  blockItems.value = updatedItems
  selectedIndexes.value = []
}

const pasteItems = async (event?: ClipboardEvent | null, insertIndex?: number) => {
  if (event) {
    event.stopPropagation()
    event.preventDefault()
  }

  const pastedItem = await globalPasteItem()
  const pastedItems = normalizeClipboardItems(pastedItem)

  if (pastedItems.length > 0) {
    const updatedItems = [...blockItems.value]
    const index = insertIndex ?? updatedItems.length
    updatedItems.splice(index, 0, ...pastedItems)
    emit('update:modelValue', updatedItems)
    selectedIndexes.value = []
  }
}

const handleTemplateTrigger = (content: Record<string, unknown>) => {
  const block = getBlockForContent(content)
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
  const item = blockItems.value[index]
  const updatedItems = [...blockItems.value]
  updatedItems[index] = { ...item, hidden: !item.hidden }
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
            @click="selectAllItems()"
          >
            <Icon name="lucide:square-check" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="xs"
            @click="copyItems()"
          >
            <Icon name="lucide:copy" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="xs"
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

      <AccordionRoot
        ref="accordionContainer"
        v-model="openItems"
        type="multiple"
        class="relative pt-2"
      >
        <AccordionItem
          v-for="(content, i) in blockItems"
          :key="(content.id as string) || i"
          :value="getItemAccordionValue(content, i)"
          :class="[
            'relative mb-2 min-w-0 rounded-lg border bg-background p-2 transition-colors',
            hasVisibleItemError(i) ? 'border-destructive/40 bg-destructive/5' : 'border-border',
            content.hidden ? 'opacity-50' : '',
          ]"
        >
          <AccordionHeader class="group relative">
            <AddDropdown
              :item="item"
              :space-id="spaceId"
              :can-mutate="!props.readOnly"
              :has-clipboard-item="hasClipboardItem"
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
            <AccordionTrigger class="flex w-full items-center gap-2">
              <BlockHeader
                :content="content"
                :block="getBlockHeaderBlock(content)"
              />
              <div class="ml-auto flex items-center gap-2">
                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100">
                  <button
                    v-if="!props.readOnly"
                    type="button"
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
                    v-if="!props.readOnly && content.id"
                    type="button"
                    :title="$t('actions.blocks.tooltips.createTemplate')"
                    class="flex transform cursor-pointer items-center hover:text-primary"
                    @click.stop="handleTemplateTrigger(content)"
                  >
                    <Icon name="lucide:notepad-text-dashed" />
                  </button>
                  <button
                    v-if="content.id"
                    type="button"
                    :title="$t('actions.blocks.tooltips.editNested')"
                    class="flex transform cursor-pointer items-center hover:text-primary"
                    @click.stop="navigateToItem(content.id as string)"
                  >
                    <Icon name="lucide:edit-3" />
                  </button>
                  <button
                    v-if="!props.readOnly"
                    type="button"
                    :title="$t('actions.blocks.tooltips.copy')"
                    class="flex transform cursor-pointer items-center hover:text-primary"
                    @click.stop="copyItems(i)"
                  >
                    <Icon name="lucide:copy" />
                  </button>
                  <button
                    v-if="!props.readOnly"
                    type="button"
                    :title="$t('actions.blocks.tooltips.cut')"
                    class="flex transform cursor-pointer items-center hover:text-primary"
                    @click.stop="cutItems(i)"
                  >
                    <Icon name="lucide:scissors" />
                  </button>
                  <button
                    v-if="!props.readOnly"
                    type="button"
                    :title="$t('actions.blocks.tooltips.delete')"
                    class="flex transform cursor-pointer items-center hover:text-red-500"
                    @click.stop="deleteItem(i)"
                  >
                    <Icon name="lucide:trash-2" />
                  </button>
                </div>
              </div>
            </AccordionTrigger>
          </AccordionHeader>
          <AccordionContent>
            <div class="mt-2 grid items-start gap-4 border-t-2 border-surface p-1 pt-2">
              <div
                @focusin.stop
                @focusout.stop
              >
                <EditorComponent
                  :key="(content.id as string) || i"
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
                />
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
        <AddDropdown
          :item="item"
          :space-id="spaceId"
          :can-mutate="!props.readOnly"
          :has-clipboard-item="hasClipboardItem"
          @paste="pasteItems"
          @select="
            ({ blockSlug, template }: { blockSlug: string; template: BlockTemplate | null }) =>
              addItem(blockSlug, blockItems.length, template)
          "
        />
        <div
          v-if="!props.readOnly && hasClipboardItem"
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
