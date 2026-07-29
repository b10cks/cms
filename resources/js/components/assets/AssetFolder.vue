<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import {
  draggable as makeDraggable,
  dropTargetForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter'

import Icon from '~/components/Icon.vue'
import { Checkbox } from '~/components/ui/checkbox'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '~/components/ui/context-menu'
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

defineOptions({ inheritAttrs: false })

const { t } = useI18n()
const props = defineProps<{
  folder: AssetFolderResource
  selected?: boolean
  cut?: boolean
  draggable?: boolean
  canEdit?: boolean
  canDelete?: boolean
  canCreateChildren?: boolean
  canShare?: boolean
  dragItems?: AssetManagerDragItem[]
  canReceiveDrop?: (items: AssetManagerDragItem[]) => boolean
  onItemsDrop?: (items: AssetManagerDragItem[]) => void | Promise<void>
}>()

const emit = defineEmits<{
  select: [folder: AssetFolderResource, selected: boolean]
  click: [folder: AssetFolderResource, event: MouseEvent]
  open: [folder: AssetFolderResource]
  create: [folder: AssetFolderResource]
  edit: [folder: AssetFolderResource]
  move: [folder: AssetFolderResource]
  share: [folder: AssetFolderResource]
  delete: [folder: AssetFolderResource]
  'context-menu': [folder: AssetFolderResource]
}>()

const rootElement = ref<HTMLElement | null>(null)
const isDraggedOver = ref(false)
const resolvedDragItems = computed(() => {
  return props.dragItems?.length
    ? props.dragItems
    : [{ id: props.folder.id, type: 'folder' as const }]
})
const dragPreviewTitle = computed(() => {
  const items = resolvedDragItems.value

  if (items.length <= 1) {
    return props.folder.name
  }

  const folderCount = items.filter((item) => item.type === 'folder').length
  const assetCount = items.length - folderCount

  if (folderCount && assetCount) {
    return String(t('labels.assets.dragMixed', { folders: folderCount, assets: assetCount }))
  }

  return String(t('labels.selectionCount', { count: items.length }))
})

function handleCheckboxSelect(event: Event) {
  event.stopPropagation()
  emit('select', props.folder, !props.selected)
}

function handleClick(event: MouseEvent) {
  emit('click', props.folder, event)
}

function handleDoubleClick() {
  emit('open', props.folder)
}

watchEffect((onCleanup) => {
  if (!rootElement.value) {
    return
  }

  const cleanup = combine(
    makeDraggable({
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
  <ContextMenu>
    <ContextMenuTrigger as-child>
      <div
        ref="rootElement"
        v-bind="$attrs"
        class="group relative flex cursor-pointer items-center gap-2 rounded-md bg-background p-3 transition-all duration-200 select-none focus:bg-input focus:outline-2 focus:outline-offset-2 focus:outline-blue-300"
        :class="{
          'outline-2 outline-accent': selected,
          'opacity-50': cut,
          'bg-input/70 ring-1 ring-border': isDraggedOver,
        }"
        :aria-label="folder.name"
        :aria-selected="selected"
        role="option"
        tabindex="0"
        @click="handleClick"
        @dblclick="handleDoubleClick"
        @contextmenu="emit('context-menu', folder)"
      >
        <Checkbox
          :model-value="selected"
          :aria-label="`Select folder ${folder.name}`"
          class="transition-opacity"
          :class="
            selected
              ? 'opacity-100'
              : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'
          "
          @click.stop="handleCheckboxSelect"
          @dblclick.stop
        />

        <div class="flex flex-1 items-center gap-3">
          <div class="flex h-12 w-12 items-center justify-center rounded-md bg-surface p-2 shadow">
            <Icon
              :name="`lucide:${folder.icon}`"
              :style="{ color: folder.color || 'inherit' }"
            />
          </div>
          <div class="flex-1 group-hover:text-primary">
            <h4 class="font-semibold text-primary">{{ folder.name }}</h4>
            <div class="text-sm text-muted">
              {{ folder.children_count }} Folder, {{ folder.assets_count }} Asset
            </div>
          </div>
        </div>
        <DropdownMenu
          v-if="canEdit || canDelete || canCreateChildren || canShare"
          class="ml-auto"
        >
          <DropdownMenuTrigger
            class="transition-colors hover:text-primary"
            :aria-label="$t('actions.moreActions')"
            @click.stop
            @dblclick.stop
          >
            <Icon name="lucide:ellipsis-vertical" />
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem @select="emit('open', folder)">
              <Icon name="lucide:eye" />
              <span>{{ $t('actions.open') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canEdit"
              @select="emit('edit', folder)"
            >
              <Icon name="lucide:edit" />
              <span>{{ $t('actions.edit') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canCreateChildren"
              @select="emit('create', folder)"
            >
              <Icon name="lucide:folder-plus" />
              <span>{{ $t('actions.createFolder') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canEdit"
              @select="emit('move', folder)"
            >
              <Icon name="lucide:folder-input" />
              <span>{{ $t('actions.move') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canShare"
              @select="emit('share', folder)"
            >
              <Icon name="lucide:share-2" />
              <span>{{ $t('actions.assetShares.shareFolder') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canDelete"
              class="text-destructive"
              @select="emit('delete', folder)"
            >
              <Icon name="lucide:trash-2" />
              <span>{{ $t('actions.delete') }}</span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </ContextMenuTrigger>

    <ContextMenuContent>
      <ContextMenuItem @select="emit('open', folder)">
        <Icon name="lucide:folder-open" />
        <span>{{ $t('actions.open') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canEdit"
        @select="emit('edit', folder)"
      >
        <Icon name="lucide:edit" />
        <span>{{ $t('actions.edit') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canCreateChildren"
        @select="emit('create', folder)"
      >
        <Icon name="lucide:folder-plus" />
        <span>{{ $t('actions.createFolder') }}</span>
      </ContextMenuItem>
      <ContextMenuSeparator v-if="canEdit || canDelete" />
      <ContextMenuItem
        v-if="canEdit"
        @select="emit('move', folder)"
      >
        <Icon name="lucide:folder-input" />
        <span>{{ $t('actions.move') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canShare"
        @select="emit('share', folder)"
      >
        <Icon name="lucide:share-2" />
        <span>{{ $t('actions.assetShares.shareFolder') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canDelete"
        class="text-destructive"
        @select="emit('delete', folder)"
      >
        <Icon name="lucide:trash-2" />
        <span>{{ $t('actions.delete') }}</span>
      </ContextMenuItem>
    </ContextMenuContent>
  </ContextMenu>
</template>
