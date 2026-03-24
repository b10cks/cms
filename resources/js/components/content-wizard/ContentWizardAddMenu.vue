<script setup lang="ts">
import { useVModel } from '@vueuse/core'

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
    return
  }

  await nextTick()
  searchRef.value?.focus()
  searchRef.value?.select()
})


const selectBlock = (block: BlockResource) => {
  emit('select', block)
  open.value = false
}


const openMenu = () => {
  if (!props.disabled && props.blocks.length > 0) {
    open.value = true
  }
}


const positionClass = computed(() => {
  switch (props.side) {
    case 'right':
      return 'top-1/2 -right-6 -translate-y-1/2'
    default:
      return '-bottom-6 left-1/2 -translate-x-1/2'
  }
})


defineExpose({
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
        </Button>
      </slot>
    </PopoverTrigger>

    <PopoverContent
      :side="side"
      align="center"
      class="w-72 border border-border"
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
            v-for="block in filteredBlocks"
            :key="block.id"
            type="button"
            class="flex w-full items-center gap-3 rounded px-2 py-1 text-left transition-colors hover:bg-accent"
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
