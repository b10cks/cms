<script setup lang="ts">
import { dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { TreeItem } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { getAssetManagerDragItems } from '~/lib/assets/assetDragAndDrop'
import type { AssetTagResource } from '~/types/assets'

const props = defineProps<{
  item: { _id: string; level: number; bind: Record<string, unknown>; value: AssetTagResource }
  selectedTagId: string | null
  canManageTags: boolean
}>()

const emit = defineEmits<{
  select: [id: string]
  rename: [name: string, tag: AssetTagResource]
  edit: [tag: AssetTagResource]
  delete: [tag: AssetTagResource]
  assignDrop: [tagId: string, assetIds: string[]]
}>()

const wrapperEl = ref<HTMLElement | null>(null)
const isDraggedOver = ref(false)

watchEffect((onCleanup) => {
  if (!wrapperEl.value) return

  const cleanup = dropTargetForElements({
    element: wrapperEl.value,
    canDrop: ({ source }) => {
      const items = getAssetManagerDragItems(source.data)
      return items.length > 0 && items.some((i) => i.type === 'asset')
    },
    getIsSticky: () => true,
    onDragEnter: () => {
      isDraggedOver.value = true
    },
    onDragLeave: () => {
      isDraggedOver.value = false
    },
    onDrop: ({ source }) => {
      isDraggedOver.value = false
      const items = getAssetManagerDragItems(source.data)
      const assetIds = items.filter((i) => i.type === 'asset').map((i) => i.id)
      if (assetIds.length) {
        emit('assignDrop', props.item.value.id, assetIds)
      }
    },
  })

  onCleanup(() => {
    isDraggedOver.value = false
    cleanup()
  })
})
</script>

<template>
  <div
    ref="wrapperEl"
    class="relative rounded-md transition-colors"
    :class="isDraggedOver ? 'ring-2 ring-accent' : ''"
  >
    <TreeItem
      :style="{ 'padding-left': `${item.level - 0.5}rem` }"
      v-bind="item.bind"
      :class="[
        'group flex w-full items-center gap-2 rounded-md px-2 py-1 outline-none',
        'cursor-pointer font-semibold transition-colors duration-200 hover:bg-input',
        item.value.id === selectedTagId ? 'bg-input text-primary' : '',
        isDraggedOver ? 'bg-accent/10' : '',
      ]"
      @select="$emit('select', item.value.id)"
    >
      <Icon
        v-if="item.value.icon"
        :name="`lucide:${item.value.icon}`"
        :style="{ color: item.value.color }"
        class="shrink-0"
        aria-hidden="true"
      />
      <RenamableTitle
        :name="item.value.name"
        :disabled="!canManageTags"
        class="min-w-0 flex-1 truncate"
        @update="$emit('rename', $event, item.value)"
      />
      <Badge
        variant="secondary"
        class="shrink-0"
      >
        {{ item.value.assets_count ?? 0 }}
      </Badge>
      <DropdownMenu v-if="canManageTags">
        <DropdownMenuTrigger
          class="opacity-0 transition-all duration-200 group-hover:opacity-100 hover:text-primary data-[state=open]:opacity-100"
          @click.stop
        >
          <Icon name="lucide:ellipsis-vertical" />
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem @select="$emit('select', item.value.id)">
            <Icon name="lucide:eye" />
            <span>{{ $t('actions.view') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem @select="$emit('edit', item.value)">
            <Icon name="lucide:edit" />
            <span>{{ $t('actions.edit') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem
            class="text-destructive"
            @select="$emit('delete', item.value)"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ $t('actions.delete') }}</span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </TreeItem>
  </div>
</template>
