<script setup lang="ts">
import { useEventListener, useVModel } from '@vueuse/core'
import type { ComponentPublicInstance } from 'vue'

import Icon from '~/components/Icon.vue'
import { Input } from '~/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'

import IconName from '../ui/IconName.vue'

const props = withDefaults(
  defineProps<{
    modelValue?: boolean
    blocks: BlockResource[]
    disabled?: boolean
    isActionActive: boolean
    side?: 'right' | 'bottom'
  }>(),
  {
    modelValue: false,
    disabled: false,
    isActionActive: false,
    side: 'bottom',
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
const searchRef = ref<HTMLInputElement | null>(null)
const optionRefs = new Map<number, HTMLButtonElement>()
const activeIndex = ref(0)

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

const openMenu = () => {
  if (props.disabled || props.blocks.length === 0) {
    return
  }

  if (props.blocks.length === 1) {
    selectBlock(props.blocks[0])
    return
  }

  open.value = true
}

const setOptionRef = (index: number) => {
  return (element: Element | ComponentPublicInstance | null) => {
    const button = element as HTMLButtonElement | null
    if (button) {
      optionRefs.set(index, button)
      return
    }

    optionRefs.delete(index)
  }
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

useEventListener(window, 'keydown', (event) => {
  if (!open.value || event.key !== 'Escape') {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  closeMenu()
})

const positionClass = computed(() => {
  switch (props.side) {
    case 'right':
      return 'top-1/2 -right-6 -translate-y-1/2'
    default:
      return '-bottom-6 left-1/2 -translate-x-1/2'
  }
})

defineExpose({
  closeMenu,
  openMenu,
})
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      as-child
      :disabled="disabled"
      data-add-menu
    >
      <slot>
        <button
          tabindex="-1"
          :class="[
            'absolute flex size-6 items-center justify-center rounded-full bg-accent text-primary transition',
            positionClass,
            isActionActive
              ? 'pointer-events-auto cursor-pointer opacity-100 scale-50 hover:scale-100'
              : 'pointer-events-none opacity-0 group-hover:pointer-events-auto hover:text-primary',
          ]"
          :disabled="disabled"
        >
          <Icon name="lucide:plus" />
        </button>
      </slot>
    </PopoverTrigger>

    <PopoverContent
      :side="side"
      align="center"
      class="w-72 border border-border"
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
    </PopoverContent>
  </Popover>
</template>
