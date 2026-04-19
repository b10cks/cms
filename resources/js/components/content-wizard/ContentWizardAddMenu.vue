<script setup lang="ts">
import { useEventListener, useVModel } from '@vueuse/core'
import type { ComponentPublicInstance, CSSProperties } from 'vue'

import { Input } from '~/components/ui/input'

import IconName from '../ui/IconName.vue'

const props = withDefaults(
  defineProps<{
    modelValue?: boolean
    blocks: BlockResource[]
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
  (event: 'select', value: BlockResource): void
}>()

const open = useVModel(props, 'modelValue', emit, {
  passive: true,
})
const query = ref('')
const searchRef = ref<InstanceType<typeof Input> | null>(null)
const activeIndex = ref(0)
const optionRefs = new Map<number, HTMLButtonElement>()

const filteredBlocks = computed(() => {
  const needle = query.value.trim().toLowerCase()
  if (!needle) {
    return props.blocks
  }

  return props.blocks.filter((block) =>
    [block.name, block.slug, block.type].some((value) => value.toLowerCase().includes(needle))
  )
})

watch(open, async (value) => {
  if (!value) {
    query.value = ''
    activeIndex.value = 0
    return
  }

  activeIndex.value = 0
  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select()
})

watch(
  filteredBlocks,
  async (blocks) => {
    if (!open.value) {
      return
    }

    activeIndex.value =
      blocks.length === 0 ? -1 : Math.min(Math.max(activeIndex.value, 0), blocks.length - 1)
    await nextTick()
    if (activeIndex.value >= 0) {
      optionRefs.get(activeIndex.value)?.scrollIntoView({ block: 'nearest' })
    }
  },
  { flush: 'post' }
)

const selectBlock = (block: BlockResource) => {
  emit('select', block)
  open.value = false
}

const closeMenu = () => {
  open.value = false
}

const moveActiveIndex = (direction: 'up' | 'down') => {
  if (filteredBlocks.value.length === 0) {
    activeIndex.value = -1
    return
  }

  if (activeIndex.value < 0) {
    activeIndex.value = 0
    return
  }

  const delta = direction === 'down' ? 1 : -1
  activeIndex.value =
    (activeIndex.value + delta + filteredBlocks.value.length) % filteredBlocks.value.length
}

const handleMenuKeydown = async (event: KeyboardEvent) => {
  if (!open.value) {
    return
  }

  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    closeMenu()
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
    const block = filteredBlocks.value[activeIndex.value]
    if (!block) {
      return
    }

    event.preventDefault()
    event.stopPropagation()
    selectBlock(block)
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
          :placeholder="$t('labels.contents.canvas.searchBlocks')"
        />

        <div
          v-if="filteredBlocks.length === 0"
          class="rounded-xl border border-dashed border-border px-3 py-4 text-sm text-muted"
        >
          {{ $t('labels.contents.canvas.noBlocksAvailable') }}
        </div>

        <div
          v-else
          class="max-h-64 space-y-1 overflow-y-auto"
        >
          <button
            v-for="(block, index) in filteredBlocks"
            :key="block.id"
            :ref="setOptionRef(index)"
            type="button"
            tabindex="-1"
            :class="[
              'flex w-full items-center gap-3 rounded px-2 py-1 text-left transition-colors hover:bg-accent',
              index === activeIndex ? 'bg-accent text-accent-foreground' : '',
            ]"
            @mouseenter="activeIndex = index"
            @click="selectBlock(block)"
          >
            <IconName
              :icon="block.icon"
              :color="block.color"
              :name="block.name"
            />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
