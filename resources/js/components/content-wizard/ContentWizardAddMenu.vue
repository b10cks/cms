<script setup lang="ts">
import { useEventListener, useVModel } from '@vueuse/core'
import type { ComponentPublicInstance, CSSProperties } from 'vue'

import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Input } from '~/components/ui/input'

import IconName from '../ui/IconName.vue'

const props = withDefaults(
  defineProps<{
    modelValue?: boolean
    blocks: BlockResource[]
    spaceId: string
    side?: 'right' | 'bottom'
    anchorStyle?: CSSProperties | null
  }>(),
  {
    modelValue: false,
    side: 'bottom',
    anchorStyle: null,
  }
)

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void
  (event: 'select', value: { block: BlockResource; template: BlockTemplate | null }): void
}>()

type AddMenuEntry = {
  key: string
  label: string
  icon: string | null | undefined
  color: string | null | undefined
  description?: string | null
  previewFile?: string | null
  /** Set on block entries; opens the template step when the block has any. */
  block?: BlockResource
  template?: BlockTemplate | null
  isBack?: boolean
}

const { t } = useI18n()

const open = useVModel(props, 'modelValue', emit, {
  passive: true,
})
const query = ref('')
const searchRef = ref<InstanceType<typeof Input> | null>(null)
const activeIndex = ref(0)
const optionRefs = new Map<number, HTMLButtonElement>()

/** Non-null while the menu shows that block's templates instead of the blocks. */
const templateBlock = ref<BlockResource | null>(null)

const { useBlockTemplatesQuery } = useBlockTemplates(
  computed(() => props.spaceId),
  computed(() => templateBlock.value?.id || '')
)
// isPlaceholderData matters here: the query keeps previous data across block
// changes, and picking a stale template would hydrate one block's content
// against another block's schema.
const { data: templates, isPlaceholderData } = useBlockTemplatesQuery()
const resolvedTemplates = computed(() => (isPlaceholderData.value ? [] : templates.value || []))

const hasTemplates = (block: BlockResource) => Boolean(block.templates_count)

const matches = (needle: string, ...values: Array<string | null | undefined>) =>
  !needle || values.some((value) => (value || '').toLowerCase().includes(needle))

const blockEntries = computed<AddMenuEntry[]>(() => {
  const needle = query.value.trim().toLowerCase()

  return props.blocks
    .filter((block) => matches(needle, block.name, block.slug, block.type))
    .map((block) => ({
      key: block.id,
      label: block.name,
      icon: block.icon,
      color: block.color,
      description: block.description,
      previewFile: block.preview_file,
      block,
    }))
})

const templateEntries = computed<AddMenuEntry[]>(() => {
  const block = templateBlock.value
  if (!block) {
    return []
  }

  const needle = query.value.trim().toLowerCase()

  return [
    { key: '__back', label: block.name, icon: block.icon, color: block.color, isBack: true },
    // Never filtered out: it is the escape hatch back to a plain entry, not a
    // search result.
    {
      key: '__blank',
      label: t('labels.contents.blankTemplate') as string,
      icon: null,
      color: null,
      template: null,
    },
    ...resolvedTemplates.value
      .filter((template) => matches(needle, template.name, template.description))
      .map((template) => ({
        key: template.id,
        label: template.name,
        icon: template.icon,
        color: template.color,
        description: template.description,
        previewFile: template.preview_file,
        template,
      })),
  ]
})

const entries = computed(() => (templateBlock.value ? templateEntries.value : blockEntries.value))

// Only worth a panel when there is something to show; the back and blank rows
// never have either.
const previewEntry = computed(() => {
  const entry = entries.value[activeIndex.value]
  return entry && (entry.previewFile || entry.description) ? entry : null
})

watch(open, async (value) => {
  if (!value) {
    query.value = ''
    activeIndex.value = 0
    templateBlock.value = null
    return
  }

  activeIndex.value = 0
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select()
})

watch(
  entries,
  async (items) => {
    if (!open.value) {
      return
    }

    activeIndex.value =
      items.length === 0 ? -1 : Math.min(Math.max(activeIndex.value, 0), items.length - 1)
    await nextTick()
    if (activeIndex.value >= 0) {
      optionRefs.get(activeIndex.value)?.scrollIntoView({ block: 'nearest' })
    }
  },
  { flush: 'post' }
)

const openTemplateStep = async (block: BlockResource) => {
  templateBlock.value = block
  query.value = ''
  // Land on the blank entry rather than "back", so Enter twice still means
  // "create a plain entry of this block".
  activeIndex.value = 1
  await nextTick()
  searchRef.value?.focus()
}

const closeTemplateStep = () => {
  templateBlock.value = null
  query.value = ''
  activeIndex.value = 0
}

const selectEntry = (entry: AddMenuEntry) => {
  if (entry.isBack) {
    closeTemplateStep()
    return
  }

  if (entry.block) {
    if (hasTemplates(entry.block)) {
      openTemplateStep(entry.block)
      return
    }

    emit('select', { block: entry.block, template: null })
    open.value = false
    return
  }

  const block = templateBlock.value
  if (!block) {
    return
  }

  emit('select', { block, template: entry.template ?? null })
  open.value = false
}

const closeMenu = () => {
  open.value = false
}

const moveActiveIndex = (direction: 'up' | 'down') => {
  if (entries.value.length === 0) {
    activeIndex.value = -1
    return
  }

  if (activeIndex.value < 0) {
    activeIndex.value = 0
    return
  }

  const delta = direction === 'down' ? 1 : -1
  activeIndex.value = (activeIndex.value + delta + entries.value.length) % entries.value.length
}

const handleMenuKeydown = async (event: KeyboardEvent) => {
  if (!open.value) {
    return
  }

  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    // Backs out of the template step first, so a mis-picked block does not cost
    // you the whole menu.
    if (templateBlock.value) {
      closeTemplateStep()
      return
    }
    closeMenu()
    return
  }

  // Backspace on an empty query is the same "go back" gesture the block editor's
  // add dropdown uses.
  if (
    event.key === 'Backspace' &&
    templateBlock.value &&
    !(event.target as HTMLInputElement | null)?.value
  ) {
    event.preventDefault()
    closeTemplateStep()
    return
  }

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    moveActiveIndex(event.key === 'ArrowDown' ? 'down' : 'up')
    await nextTick()
    if (activeIndex.value >= 0) {
      optionRefs.get(activeIndex.value)?.scrollIntoView({ block: 'nearest' })
    }
    return
  }

  if (event.key === 'Enter') {
    const entry = entries.value[activeIndex.value]
    if (!entry) {
      return
    }

    event.preventDefault()
    event.stopPropagation()
    selectEntry(entry)
  }
}

const setOptionRef = (index: number) => {
  return (element: Element | ComponentPublicInstance | null) => {
    if (element instanceof HTMLButtonElement) {
      optionRefs.set(index, element)
      return
    }

    optionRefs.delete(index)
  }
}

useEventListener(window, 'keydown', (event) => {
  if (!open.value || event.key !== 'Escape') {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  closeMenu()
})

useEventListener(window, 'pointerdown', (event) => {
  if (!open.value) {
    return
  }

  const target = event.target as HTMLElement | null
  if (target?.closest('[data-add-menu-panel], [data-shared-add-controls]')) {
    return
  }

  closeMenu()
})

const panelPositionClass = computed(() => {
  switch (props.side) {
    case 'right':
      return 'left-full top-1/2 ml-2 -translate-y-1/2'
    default:
      return 'left-1/2 top-full mt-2 -translate-x-1/2'
  }
})
</script>

<template>
  <div
    v-if="open && anchorStyle"
    aria-hidden="true"
    data-add-menu
    class="absolute z-30"
    :style="anchorStyle"
  >
    <div
      data-add-menu-panel
      :class="[
        'absolute w-72 rounded-md border border-border bg-background p-3 text-popover-foreground shadow-lg outline-none',
        panelPositionClass,
      ]"
      @keydown.capture="handleMenuKeydown"
    >
      <div class="space-y-2">
        <Input
          ref="searchRef"
          v-model="query"
          :placeholder="
            templateBlock
              ? $t('labels.contents.canvas.searchTemplates')
              : $t('labels.contents.canvas.searchBlocks')
          "
        />

        <div
          v-if="entries.length === 0"
          class="rounded-xl border border-dashed border-border px-3 py-4 text-sm text-muted"
        >
          {{ $t('labels.contents.canvas.noBlocksAvailable') }}
        </div>

        <div
          v-else
          class="max-h-64 space-y-1 overflow-y-auto"
        >
          <button
            v-for="(entry, index) in entries"
            :key="entry.key"
            :ref="setOptionRef(index)"
            type="button"
            tabindex="-1"
            :class="[
              'flex w-full items-center gap-3 rounded px-2 py-1 text-left transition-colors hover:bg-accent',
              index === activeIndex ? 'bg-accent text-accent-foreground' : '',
              entry.isBack ? 'border-b border-border pb-1.5' : '',
            ]"
            @mouseenter="activeIndex = index"
            @click="selectEntry(entry)"
          >
            <Icon
              v-if="entry.isBack"
              name="lucide:chevron-left"
              class="opacity-50"
            />
            <IconName
              :icon="entry.icon"
              :color="entry.color"
              :name="entry.label"
            />
            <Icon
              v-if="entry.block && hasTemplates(entry.block)"
              name="lucide:chevron-right"
              class="ml-auto opacity-50"
            />
          </button>
        </div>
      </div>

      <!--
        Sits to the left of the panel: the menu already opens right/below its
        node, so that is the side with free canvas.
      -->
      <div
        v-if="previewEntry"
        class="absolute top-0 right-full mr-3 w-80 overflow-hidden rounded-md border border-border bg-background shadow-lg"
      >
        <NuxtImg
          v-if="previewEntry.previewFile"
          :src="previewEntry.previewFile"
          :alt="previewEntry.label"
          :width="320"
          :height="240"
          :modifiers="{ crop: 'fit' }"
          class="max-h-60 w-full border-b border-border bg-surface/50 object-contain"
        />
        <div class="grid gap-1 p-3">
          <p class="text-sm font-semibold text-primary">{{ previewEntry.label }}</p>
          <p
            v-if="previewEntry.description"
            class="text-sm text-muted"
          >
            {{ previewEntry.description }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
