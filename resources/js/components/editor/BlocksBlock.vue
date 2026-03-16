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
import type {
  CollaborationPresenceUser,
  ContentFieldFocusPayload,
  ContentFieldUpdatePayload,
} from '~/composables/useContentLiveCollaboration'

import EditorComponent from './EditorComponent.vue'

const { $t } = useI18n()

const props = defineProps<{
  item: BlocksSchema & { key: string }
  modelValue?: Array<Record<string, unknown>> | null
  spaceId: string
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

const getBlockForContent = (content: Record<string, unknown>) =>
  blocks.value?.data?.find((entry) => entry.slug === (content.block as string))

const blockItems = computed({
  get: () => props.modelValue || [],
  set: (newValue) => {
    if (newValue === props.modelValue) return

    emit('update:modelValue', [...newValue])
  },
})

const accordionContainer = ref<HTMLElement | null>(null)
const selectedIndexes = ref<number[]>([])

const addItem = (slug: string, index: number = -1) => {
  const newItem = { block: slug, id: ulid() }
  const updatedItems = [...blockItems.value]

  if (index === -1) {
    updatedItems.push(newItem)
  } else {
    updatedItems.splice(index, 0, newItem)
  }

  emit('update:modelValue', updatedItems)
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

const normalizeClipboardItems = (pastedItem: Record<string, unknown> | null) => {
  if (!pastedItem) return []

  const items = Array.isArray(pastedItem.items) ? pastedItem.items : [pastedItem]

  return items
    .filter((item): item is Record<string, unknown> => !!item && typeof item === 'object')
    .map((item) => ({ ...item }))
}

const copyItems = async (index?: number) => {
  const indexes = getActionIndexes(index)
  if (indexes.length === 0) return

  const itemsToCopy = indexes.map((itemIndex) => ({ ...blockItems.value[itemIndex] }))
  const payload =
    itemsToCopy.length === 1
      ? itemsToCopy[0]
      : ({
          items: itemsToCopy,
        } as Record<string, unknown>)

  await globalCopyItem(payload, props.spaceId, getClipboardLabel(itemsToCopy))
}

const cutItems = async (index?: number) => {
  const indexes = getActionIndexes(index)
  if (indexes.length === 0) return

  const itemsToCut = indexes.map((itemIndex) => ({ ...blockItems.value[itemIndex] }))
  const payload =
    itemsToCut.length === 1
      ? itemsToCut[0]
      : ({
          items: itemsToCut,
        } as Record<string, unknown>)

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

const setupSortable = () => {
  nextTick(() => {
    if (!accordionContainer.value) return

    useSortable(accordionContainer.value, blockItems, {
      handle: '[draggable]',
    })
  })
}

watch(
  () => blockItems.value.length,
  () => {
    selectedIndexes.value = selectedIndexes.value.filter((index) => index < blockItems.value.length)
    setupSortable()
  },
  { immediate: true }
)

const updateContent = (index: number, newContent: Record<string, unknown>) => {
  if (newContent === blockItems.value[index]) return

  const updatedItems = [...blockItems.value]
  updatedItems[index] = newContent
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

const forwardFieldFocus = (payload: ContentFieldFocusPayload) => {
  emit('fieldFocus', payload)
}
</script>

<template>
  <div class="grid gap-2">
    <div class="relative z-10 mr-8 text-sm font-semibold text-primary">
      {{ item.name || item.key || 'Untitled' }}
    </div>
    <div class="rounded-2xl border border-border bg-surface px-2">
      <div
        v-if="hasSelectedItems"
        class="flex items-center justify-between gap-2 pt-2 px-2"
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
        type="multiple"
        class="relative pt-2"
      >
        <AccordionItem
          v-for="(content, i) in blockItems"
          :key="(content.id as string) || i"
          :value="`content-${(content.id as string) || i}`"
          class="relative mb-2 rounded-lg border border-border bg-background p-2"
        >
          <AccordionHeader class="group">
            <AddDropdown
              :item="item"
              :space-id="spaceId"
              :has-clipboard-item="hasClipboardItem"
              @paste="() => pasteItems(null, i)"
              @select="(slug: string) => addItem(slug, i)"
            />
            <div
              class="absolute left-8 top-3.5 z-10"
              @click.stop
            >
              <Checkbox
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
                v-if="getBlockForContent(content)"
                :content="content"
                :block="getBlockForContent(content)!"
              />
              <div
                v-else
                class="flex grow items-center gap-2 pl-6 text-left"
              >
                <button
                  type="button"
                  class="flex shrink-0 cursor-ns-resize items-center text-muted-foreground hover:text-primary"
                  :title="$t('actions.blocks.tooltips.drag')"
                  @click.stop
                >
                  <Icon name="lucide:grip-vertical" />
                </button>
                <div class="relative flex size-4 items-center justify-center">
                  <Icon
                    name="lucide:box"
                    class="shrink-0 text-muted-foreground transition-opacity group-hover:opacity-0"
                  />
                </div>
                <div class="grid grow leading-none">
                  <h4 class="font-semibold text-primary">{{ content.block as string }}</h4>
                  <div class="flex text-sm text-muted">{{ content.block as string }}</div>
                </div>
              </div>
              <div class="ml-auto flex items-center gap-2 opacity-0 group-hover:opacity-100">
                <button
                  v-if="content.id"
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
                  type="button"
                  :title="$t('actions.blocks.tooltips.copy')"
                  class="flex transform cursor-pointer items-center hover:text-primary"
                  @click.stop="copyItems(i)"
                >
                  <Icon name="lucide:copy" />
                </button>
                <button
                  type="button"
                  :title="$t('actions.blocks.tooltips.cut')"
                  class="flex transform cursor-pointer items-center hover:text-primary"
                  @click.stop="cutItems(i)"
                >
                  <Icon name="lucide:scissors" />
                </button>
                <button
                  type="button"
                  :title="$t('actions.blocks.tooltips.delete')"
                  class="flex transform cursor-pointer items-center hover:text-red-500"
                  @click.stop="deleteItem(i)"
                >
                  <Icon name="lucide:trash-2" />
                </button>
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
                  :model-value="content"
                  :block-slug="content.block as string"
                  :get-active-collaborators="getActiveCollaborators"
                  :root-id="content.id as string"
                  :space-id="spaceId"
                  is-child
                  @update:model-value="(value) => value && updateContent(i, value)"
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
          :has-clipboard-item="hasClipboardItem"
          @paste="pasteItems"
          @select="(slug: string) => addItem(slug, blockItems.length)"
        />
        <div
          v-if="hasClipboardItem"
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
