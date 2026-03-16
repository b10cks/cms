<script lang="ts" setup>
import { useVirtualList } from '@vueuse/core'
import { SelectItem, SelectTrigger } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { Select, SelectContent } from '~/components/ui/select'

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

const props = defineProps<{
  color?: string
}>()

const selectedIcon = defineModel<string | null>()

const open = ref(false)
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
  }
  finally {
    isLoading.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    void loadIconList()
  }
})

const virtualRows = computed<VirtualRow[]>(() => {
  return iconList.value.flatMap((group, groupIndex) => {
    const iconRows = Array.from({ length: Math.ceil(group.items.length / 8) }, (_, rowIndex) => ({
      type: 'icons' as const,
      id: `icons-${groupIndex}-${rowIndex}`,
      icons: group.items.slice(rowIndex * 8, rowIndex * 8 + 8),
    }))

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

const { list, containerProps, wrapperProps } = useVirtualList(virtualRows, {
  itemHeight: 32,
  overscan: 12,
})
</script>

<template>
  <Select
    v-model="selectedIcon"
    :open="open"
    @update:open="open = $event"
  >
    <SelectTrigger
      class="flex cursor-pointer items-center justify-between rounded-md border border-input px-2 py-1 font-semibold shadow-sm ring-offset-background data-placeholder:text-muted focus:outline-none focus:ring-1 focus:ring-ring hover:bg-input disabled:cursor-not-allowed disabled:opacity-50 [&>span]:truncate text-start gap-2"
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
    </SelectTrigger>
    <SelectContent class="sm:max-w-md">
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
        class="max-h-96 overflow-y-auto"
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
              <SelectItem
                v-for="icon in row.data.icons"
                :key="icon"
                :title="icon"
                :value="icon"
                class="inline-flex size-8 items-center justify-center rounded-md p-1 hover:bg-surface"
              >
                <Icon :name="`lucide:${icon}`" />
              </SelectItem>
            </div>
          </template>
        </div>
      </div>
    </SelectContent>
  </Select>
</template>
