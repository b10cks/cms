<script lang="ts" setup>
import { useVirtualList } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import { InputField } from '~/components/ui/form'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'

type IconGroup = {
  title: string
  items: string[]
}

type VirtualRow =
  | {
      type: 'label'
      id: string
      title: string
    }
  | {
      type: 'icons'
      id: string
      icons: string[]
    }

const COLUMNS = 8

const props = defineProps<{
  color?: string
  disabled?: boolean
}>()

const { t } = useI18n()

const selectedIcon = defineModel<string | null>()

const open = ref(false)
const search = ref('')
const iconList = shallowRef<IconGroup[]>([])
const isLoading = ref(false)

const loadIconList = async () => {
  if (iconList.value.length || isLoading.value) {
    return
  }

  isLoading.value = true

  try {
    const module = await import('./iconlist.json')
    iconList.value = module.default as IconGroup[]
  } finally {
    isLoading.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    search.value = ''
    void loadIconList()
  }
})

const filteredGroups = computed<IconGroup[]>(() => {
  const query = search.value.trim().toLowerCase()
  if (!query) return iconList.value

  return iconList.value
    .map((group) => ({
      title: group.title,
      items: group.title.toLowerCase().includes(query)
        ? group.items
        : group.items.filter((icon) => icon.includes(query)),
    }))
    .filter((group) => group.items.length)
})

const virtualRows = computed<VirtualRow[]>(() => {
  return filteredGroups.value.flatMap((group, groupIndex) => {
    const iconRows = Array.from(
      { length: Math.ceil(group.items.length / COLUMNS) },
      (_, rowIndex) => ({
        type: 'icons' as const,
        id: `icons-${groupIndex}-${rowIndex}`,
        icons: group.items.slice(rowIndex * COLUMNS, rowIndex * COLUMNS + COLUMNS),
      })
    )

    return [
      {
        type: 'label' as const,
        id: `label-${groupIndex}-${group.title}`,
        title: group.title,
      },
      ...iconRows,
    ]
  })
})

const { list, containerProps, wrapperProps, scrollTo } = useVirtualList(virtualRows, {
  itemHeight: 32,
  overscan: 12,
})

watch(search, () => scrollTo(0))

const select = (icon: string) => {
  selectedIcon.value = icon
  open.value = false
}

// Enter picks the first match, so search + Enter works without the mouse.
const selectFirstMatch = () => {
  const icon = filteredGroups.value[0]?.items[0]
  if (icon) select(icon)
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      :disabled="disabled"
      class="flex cursor-pointer items-center justify-between rounded-md border border-input px-2 py-1 font-semibold shadow-sm ring-offset-background focus:outline-none focus:ring-1 focus:ring-ring hover:bg-input disabled:cursor-not-allowed disabled:opacity-50 gap-2"
    >
      <Icon
        v-if="selectedIcon"
        :name="`lucide:${selectedIcon}`"
        :style="{ color: color || 'inherit' }"
      />
      <span
        v-else
        class="h-4 w-4 rounded bg-input"
      />
    </PopoverTrigger>
    <PopoverContent
      class="w-76"
      align="start"
    >
      <div class="-m-3 flex max-h-96 flex-col">
        <div class="shrink-0 p-2">
          <InputField
            v-model="search"
            name="icon-search"
            autofocus
            :placeholder="t('labels.icons.iconPickerPlaceholder')"
            :actions="['clear']"
            @keydown.enter.prevent="selectFirstMatch"
          />
        </div>
        <div
          v-if="isLoading"
          class="px-3 py-2 text-sm text-muted-foreground"
        >
          Loading icons…
        </div>
        <div
          v-else-if="!virtualRows.length"
          class="px-3 py-2 text-sm text-muted-foreground"
        >
          No icons available.
        </div>
        <div
          v-else
          v-bind="containerProps"
          class="min-h-0 flex-1 overflow-y-auto px-2 pb-2"
        >
          <div v-bind="wrapperProps">
            <template
              v-for="row in list"
              :key="row.data.id"
            >
              <div
                v-if="row.data.type === 'label'"
                class="py-1.5 text-xs tracking-widest text-muted uppercase select-none font-semibold"
              >
                {{ row.data.title }}
              </div>
              <div
                v-else
                class="grid grid-cols-8"
                :style="{ color: props.color || 'inherit' }"
              >
                <button
                  v-for="icon in row.data.icons"
                  :key="icon"
                  type="button"
                  :aria-label="icon"
                  :title="icon"
                  :class="[
                    'inline-flex size-8 cursor-pointer items-center justify-center rounded-md p-1 hover:bg-surface',
                    icon === selectedIcon && 'bg-input',
                  ]"
                  @click="select(icon)"
                >
                  <Icon :name="`lucide:${icon}`" />
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
