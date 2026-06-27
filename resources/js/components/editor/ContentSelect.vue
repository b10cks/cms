<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'

import Icon from '~/components/Icon.vue'

const props = defineProps<{
  modelValue?: string | null
  spaceId: string
  placeholder?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  cancel: []
}>()

const { $t } = useI18n()
const { useContentMenuQuery } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const open = ref(false)
const search = ref('')
const highlightedIndex = ref(0)
const inputRef = ref<HTMLInputElement | null>(null)
const listRef = ref<HTMLElement | null>(null)
const containerRef = ref<HTMLElement | null>(null)

// Build depth-first flat list with computed level
const flatItems = computed(() => {
  if (!contentMenu.value) return []

  const menuData = contentMenu.value
  const result: Array<FlatContentMenuItem & { level: number }> = []

  const addItem = (item: FlatContentMenuItem, level: number) => {
    result.push({ ...item, level })
    Object.values(menuData)
      .filter((i) => i.pid === item.id)
      .sort(sort)
      .forEach((child) => addItem(child, level + 1))
  }

  const sort = (a: FlatContentMenuItem, b: FlatContentMenuItem) =>
    (a.position ?? 0) - (b.position ?? 0) ||
    (a.name || '').localeCompare(b.name || '') ||
    a.id.localeCompare(b.id)

  const roots = Object.values(menuData)
    .filter((i) => !i.pid && i.type !== 'single')
    .sort(sort)
  const singles = Object.values(menuData)
    .filter((i) => !i.pid && i.type === 'single')
    .sort(sort)

  ;[...roots, ...singles].forEach((item) => addItem(item, 0))
  return result
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return flatItems.value
  return flatItems.value.filter((item) => (item.name || '').toLowerCase().includes(q))
})

const selectedItem = computed(() => {
  if (!props.modelValue || !contentMenu.value) return null
  return contentMenu.value[props.modelValue] ?? null
})

watch(open, async (isOpen) => {
  if (isOpen) {
    search.value = ''
    highlightedIndex.value = 0
    await nextTick()
    inputRef.value?.focus()
  }
})

watch(filteredItems, () => {
  highlightedIndex.value = 0
})

const scrollToHighlighted = () => {
  nextTick(() => {
    listRef.value
      ?.querySelector<HTMLElement>(`[data-index="${highlightedIndex.value}"]`)
      ?.scrollIntoView({ block: 'nearest' })
  })
}

const handleKeydown = (e: KeyboardEvent) => {
  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault()
      highlightedIndex.value = Math.min(highlightedIndex.value + 1, filteredItems.value.length - 1)
      scrollToHighlighted()
      break
    case 'ArrowUp':
      e.preventDefault()
      highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
      scrollToHighlighted()
      break
    case 'Enter':
      e.preventDefault()
      selectItem(filteredItems.value[highlightedIndex.value]?.id)
      break
    case 'Escape':
      if (open.value) {
        open.value = false
      } else {
        emit('cancel')
      }
      break
  }
}

const selectItem = (id: string | undefined) => {
  if (!id) return
  emit('update:modelValue', id)
  open.value = false
}

const handleOutsideClick = (e: MouseEvent) => {
  if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
    open.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('mousedown', handleOutsideClick)
  } else {
    document.removeEventListener('mousedown', handleOutsideClick)
  }
})

onUnmounted(() => {
  document.removeEventListener('mousedown', handleOutsideClick)
})
</script>

<template>
  <div
    ref="containerRef"
    class="relative"
  >
    <!-- Trigger -->
    <button
      type="button"
      :class="[
        'flex w-full items-center justify-between gap-2 rounded-md border border-input bg-input px-3 py-2 text-sm',
        'transition-colors hover:bg-accent hover:text-accent-foreground',
        'focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
        open && 'ring-1 ring-ring',
      ]"
      @click="open = !open"
    >
      <span class="flex min-w-0 items-center gap-2">
        <Icon
          v-if="selectedItem"
          :name="`lucide:${selectedItem.icon || 'file'}`"
          class="shrink-0"
          :style="{ color: selectedItem.color || undefined }"
        />
        <Icon
          v-else
          name="lucide:link-2"
          class="shrink-0"
        />
        <span class="truncate">
          {{ selectedItem?.name ?? placeholder ?? $t('labels.references.selectContent') }}
        </span>
      </span>
      <Icon
        name="lucide:chevrons-up-down"
        class="size-4 shrink-0"
      />
    </button>

    <!-- Dropdown -->
    <Transition
      enter-active-class="transition-all duration-100 ease-out"
      enter-from-class="opacity-0 translate-y-[-4px]"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-75 ease-in"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-[-4px]"
    >
      <div
        v-if="open"
        class="absolute left-0 right-0 z-50 mt-1 overflow-hidden rounded-md border border-popover-border bg-popover text-popover-foreground shadow-md"
      >
        <!-- Search input -->
        <div class="flex items-center gap-2 border-b border-popover-border px-3 py-2">
          <Icon
            name="lucide:search"
            class="size-4 shrink-0 text-muted"
          />
          <input
            ref="inputRef"
            v-model="search"
            type="text"
            :placeholder="$t('labels.references.searchPlaceholder')"
            class="flex-1 bg-transparent text-sm outline-none placeholder:text-muted"
            @keydown="handleKeydown"
          />
          <button
            v-if="search"
            type="button"
            class="text-muted transition-colors hover:text-primary"
            @click="search = ''"
          >
            <Icon
              name="lucide:x"
              class="size-3"
            />
          </button>
        </div>

        <!-- Items list -->
        <div
          ref="listRef"
          class="max-h-64 overflow-y-auto p-1"
        >
          <p
            v-if="filteredItems.length === 0"
            class="py-4 text-center text-sm text-muted"
          >
            {{ $t('labels.references.noResults') }}
          </p>
          <button
            v-for="(item, index) in filteredItems"
            :key="item.id"
            type="button"
            :data-index="index"
            :style="{
              paddingLeft: search.trim() ? '0.5rem' : `${0.5 + item.level * 1.25}rem`,
            }"
            :class="[
              'flex w-full cursor-pointer items-center gap-2 rounded-sm py-1.5 pr-2 text-left text-sm transition-colors',
              index === highlightedIndex
                ? 'bg-accent text-accent-foreground'
                : 'hover:bg-accent hover:text-accent-foreground',
            ]"
            @click="selectItem(item.id)"
            @mouseenter="highlightedIndex = index"
          >
            <Icon
              :name="`lucide:${item.icon || 'file'}`"
              class="size-4 shrink-0"
              :style="{ color: item.color || undefined }"
            />
            <span class="truncate font-medium">{{ item.name }}</span>
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>
