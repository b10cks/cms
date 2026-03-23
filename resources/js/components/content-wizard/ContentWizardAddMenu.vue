<script setup lang="ts">
import { useVModel } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'

const props = withDefaults(
  defineProps<{
    modelValue?: boolean
    blocks: BlockResource[]
    disabled?: boolean
    side?: 'left' | 'right' | 'bottom'
  }>(),
  {
    modelValue: false,
    disabled: false,
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
        <Button
          variant="outline"
          size="icon"
          class="size-7 rounded-full border-dashed"
          :disabled="disabled"
        >
          <Icon name="lucide:plus" />
        </Button>
      </slot>
    </PopoverTrigger>

    <PopoverContent
      :side="side"
      align="center"
      class="w-72 rounded-2xl border border-border bg-background p-2 shadow-soft-lg"
    >
      <div class="space-y-2">
        <input
          ref="searchRef"
          v-model="query"
          :placeholder="$t('labels.contents.canvas.searchBlocks')"
          class="flex h-9 w-full rounded-md border border-input-border bg-input px-3 py-1 text-sm text-primary shadow-sm outline-none placeholder:text-muted"
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
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition-colors hover:bg-accent"
            @click="selectBlock(block)"
          >
            <div
              class="flex size-8 items-center justify-center rounded-lg border border-border bg-muted/50"
              :style="block.color ? { color: block.color } : undefined"
            >
              <Icon :name="block.icon ? `lucide:${block.icon}` : 'lucide:layout-template'" />
            </div>
            <div class="min-w-0">
              <div class="truncate font-medium text-primary">{{ block.name }}</div>
              <div class="truncate text-xs text-muted">{{ block.type }}</div>
            </div>
          </button>
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
