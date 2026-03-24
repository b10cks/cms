<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'

import Icon from '~/components/Icon.vue'
import { Checkbox } from '~/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import {
  createAssetManagerDragData,
  getAssetManagerDragItems,
  setAssetManagerDragPreview,
  type AssetManagerDragItem,
} from '~/lib/assets/assetDragAndDrop'
import type { AssetFolderResource } from '~/types/assets'

const { t } = useI18n()
const props = defineProps<{
  folder: AssetFolderResource
  selected?: boolean
  draggable?: boolean
  canEdit?: boolean
  canDelete?: boolean
  canCreateChildren?: boolean
  dragItems?: AssetManagerDragItem[]
  canReceiveDrop?: (items: AssetManagerDragItem[]) => boolean
  onItemsDrop?: (items: AssetManagerDragItem[]) => void | Promise<void>
}>()

const emit = defineEmits<{
  select: [folder: AssetFolderResource, selected: boolean]
  click: [folder: AssetFolderResource]
  create: [folder: AssetFolderResource]
  edit: [folder: AssetFolderResource]
  delete: [folder: AssetFolderResource]
}>()

const rootElement = ref<HTMLElement | null>(null)
const isDraggedOver = ref(false)
const resolvedDragItems = computed(() => {
  return props.dragItems?.length
    ? props.dragItems
    : [{ id: props.folder.id, type: 'folder' as const }]
})
const dragPreviewTitle = computed(() => {
  return resolvedDragItems.value.length > 1
    ? String(t('labels.selectionCount', { count: resolvedDragItems.value.length }))
    : props.folder.name
})

function handleSelect(event: Event) {
  event.stopPropagation()
  emit('select', props.folder, !props.selected)
}

function handleClick(event?: MouseEvent) {
  if (event && (event.shiftKey || event.ctrlKey || event.metaKey)) {
    emit('select', props.folder, !props.selected)
  } else {
    emit('click', props.folder)
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === ' ' || event.key === 'Spacebar') {
    event.preventDefault() // Prevent scrolling
    handleSelect(event)
  } else if (event.key === 'Enter') {
    event.preventDefault()
    handleClick()
  }
}

watchEffect((onCleanup) => {
  if (!rootElement.value) {
    return
  }

  const cleanup = combine(
    draggable({
      element: rootElement.value,
      canDrag: () => props.draggable !== false,
      getInitialData: () => {
        return createAssetManagerDragData(resolvedDragItems.value, {
          id: props.folder.id,
          type: 'folder',
        })
      },
      onGenerateDragPreview: ({ nativeSetDragImage }) => {
        setAssetManagerDragPreview({
          nativeSetDragImage,
          count: resolvedDragItems.value.length,
          title: dragPreviewTitle.value,
        })
      },
    }),
    dropTargetForElements({
      element: rootElement.value,
      canDrop: ({ source }) => {
        const items = getAssetManagerDragItems(source.data)
        return props.canReceiveDrop ? props.canReceiveDrop(items) : false
      },
      getIsSticky: () => true,
      onDragEnter: () => {
        isDraggedOver.value = true
      },
      onDragLeave: () => {
        isDraggedOver.value = false
      },
      onDrop: async ({ source }) => {
        isDraggedOver.value = false
        const items = getAssetManagerDragItems(source.data)

        if (items.length && props.onItemsDrop) {
          await props.onItemsDrop(items)
        }
      },
    })
  )

  onCleanup(() => {
    isDraggedOver.value = false
    cleanup()
  })
})
</script>

<template>
  <div
    ref="rootElement"
    class="group relative flex cursor-pointer items-center gap-2 rounded-md bg-background p-3 transition-all duration-200 focus:bg-input focus:outline-2 focus:outline-offset-2 focus:outline-blue-300"
    :class="{
      'outline-2 outline-accent': selected,
      'bg-input/70 ring-1 ring-border': isDraggedOver,
    }"
    :aria-label="folder.name"
    :aria-selected="selected"
    role="option"
    tabindex="0"
    @keydown="handleKeyDown"
  >
    <Checkbox
      :model-value="selected"
      :aria-label="`Select folder ${folder.name}`"
      @click.stop="handleSelect"
    />

    <div
      class="flex flex-1 items-center gap-3"
      @click="handleClick"
    >
      <div class="flex h-12 w-12 items-center justify-center rounded-md bg-surface p-2 shadow">
        <Icon
          :name="`lucide:${folder.icon}`"
          :style="{ color: folder.color || 'inherit' }"
        />
      </div>
      <div class="flex-1 group-hover:text-primary">
        <h4 class="font-semibold text-primary">{{ folder.name }}</h4>
        <div class="text-sm">
          {{ folder.children_count }} Folder, {{ folder.assets_count }} Asset
        </div>
      </div>
    </div>
    <DropdownMenu
      v-if="canEdit || canDelete || canCreateChildren"
      class="ml-auto"
    >
      <DropdownMenuTrigger class="transition-colors hover:text-primary">
        <Icon name="lucide:ellipsis-vertical" />
      </DropdownMenuTrigger>
      <DropdownMenuContent>
        <DropdownMenuItem @select="handleClick">
          <Icon name="lucide:eye" />
          <span>{{ $t('actions.view') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="canEdit"
          @select="$emit('edit', folder)"
        >
          <Icon name="lucide:edit" />
          <span>{{ $t('actions.edit') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="canCreateChildren"
          @select="$emit('create', folder)"
        >
          <Icon name="lucide:folder-plus" />
          <span>{{ $t('actions.createFolder') }}</span>
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="canDelete"
          class="text-destructive"
          @select="$emit('delete', folder)"
        >
          <Icon name="lucide:trash-2" />
          <span>{{ $t('actions.delete') }}</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
