<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { draggable as makeDraggable } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'

import AssetComplianceIndicator from '~/components/assets/AssetComplianceIndicator.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Badge } from '~/components/ui/badge'
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
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import IconName from '~/components/ui/IconName.vue'
import type { AssetRequirementIssue } from '~/composables/useAssetRequirements'
import {
  createAssetManagerDragData,
  setAssetManagerDragPreview,
  type AssetManagerDragItem,
} from '~/lib/assets/assetDragAndDrop'

defineOptions({ inheritAttrs: false })

const { t } = useI18n()
const { formatFileSize } = useFormat()
const { getFileIcon, getFileType } = useFileUtils()

export interface AssetItemProps {
  asset: AssetResource
  selected?: boolean
  cut?: boolean
  draggable?: boolean
  size?: number
  mode?: 'manage' | 'select' | 'multi-select'
  canEdit?: boolean
  canDelete?: boolean
  showExtension?: boolean
  showCheckbox?: boolean
  dragItems?: AssetManagerDragItem[]
  complianceIssues?: AssetRequirementIssue[]
  resolvedTags?: AssetTagResource[]
  canAddToCollection?: boolean
  canRemoveFromCollection?: boolean
}

const props = withDefaults(defineProps<AssetItemProps>(), {
  selected: false,
  cut: false,
  draggable: false,
  size: 284,
  mode: 'manage',
  canEdit: true,
  canDelete: true,
  showExtension: true,
  showCheckbox: true,
  dragItems: () => [],
  complianceIssues: () => [],
  resolvedTags: () => [],
  canAddToCollection: false,
  canRemoveFromCollection: false,
})

const emit = defineEmits<{
  select: [asset: AssetResource, selected?: boolean]
  click: [asset: AssetResource, event: MouseEvent]
  view: [asset: AssetResource]
  delete: [asset: AssetResource]
  move: [asset: AssetResource]
  tag: [asset: AssetResource]
  'add-to-collection': [asset: AssetResource]
  'remove-from-collection': [asset: AssetResource]
  download: [asset: AssetResource]
  'copy-url': [asset: AssetResource]
  'context-menu': [asset: AssetResource]
}>()

const isSelectMode = computed(() => props.mode === 'select')
const isManageMode = computed(() => props.mode === 'manage')
const enableDragAndDrop = computed(() => props.draggable && isManageMode.value)
// The checkbox is shown whenever multi-selection is active (manage or the
// multi-select picker), but never in the single-pick select mode.
const displayCheckbox = computed(() => props.showCheckbox && !isSelectMode.value)

// In a collection view the menu also offers "Remove from collection", so the
// destructive action is spelled out as a library-wide delete to make clear it
// removes the asset everywhere - not just from this collection.
const deleteLabel = computed(() =>
  props.canRemoveFromCollection
    ? String(t('actions.assets.deleteFromLibrary'))
    : String(t('actions.delete'))
)

const hoverThumbnailIndex = ref(0)
let thumbnailInterval: ReturnType<typeof setInterval> | null = null

const startThumbnailCycle = () => {
  const thumbs = props.asset.metadata?.thumbnails
  if (!thumbs || thumbs.length <= 1) return
  thumbnailInterval = setInterval(() => {
    hoverThumbnailIndex.value = (hoverThumbnailIndex.value + 1) % thumbs.length
  }, 900)
}

const stopThumbnailCycle = () => {
  if (thumbnailInterval) {
    clearInterval(thumbnailInterval)
    thumbnailInterval = null
  }
  hoverThumbnailIndex.value = 0
}

const formatVideoDuration = (seconds: number): string => {
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}:${s.toString().padStart(2, '0')}`
}

onUnmounted(() => stopThumbnailCycle())
const rootElement = ref<HTMLElement | null>(null)
const resolvedDragItems = computed(() => {
  return props.dragItems.length ? props.dragItems : [{ id: props.asset.id, type: 'asset' as const }]
})
const dragPreviewTitle = computed(() => {
  const items = resolvedDragItems.value

  if (items.length <= 1) {
    return props.asset.filename
  }

  const folderCount = items.filter((item) => item.type === 'folder').length
  const assetCount = items.length - folderCount

  if (folderCount && assetCount) {
    return String(
      t('labels.assets.dragMixed', { folders: folderCount, assets: assetCount })
    )
  }

  return String(t('labels.selectionCount', { count: items.length }))
})
const linkedContentsLabel = computed(() => {
  return props.asset.linked_contents_count === 1
    ? String(t('labels.assets.linkedContentsSingle'))
    : String(
        t('labels.assets.linkedContentsMultiple', { count: props.asset.linked_contents_count })
      )
})

function handleCheckboxSelect(event: Event) {
  event.stopPropagation()

  if (isSelectMode.value) {
    emit('select', props.asset)
  } else {
    emit('select', props.asset, !props.selected)
  }
}

function handleCardClick(event: MouseEvent) {
  if (isSelectMode.value) {
    emit('select', props.asset)
    return
  }

  emit('click', props.asset, event)
}

function handleCardDoubleClick() {
  if (isSelectMode.value) {
    return
  }

  emit('view', props.asset)
}

function handleContextMenu() {
  if (isManageMode.value) {
    emit('context-menu', props.asset)
  }
}

watchEffect((onCleanup) => {
  if (!rootElement.value) {
    return
  }

  const cleanup = combine(
    makeDraggable({
      element: rootElement.value,
      canDrag: () => enableDragAndDrop.value,
      getInitialData: () => {
        return createAssetManagerDragData(resolvedDragItems.value, {
          id: props.asset.id,
          type: 'asset',
        })
      },
      onGenerateDragPreview: ({ nativeSetDragImage }) => {
        setAssetManagerDragPreview({
          nativeSetDragImage,
          count: resolvedDragItems.value.length,
          title: dragPreviewTitle.value,
        })
      },
    })
  )

  onCleanup(() => cleanup())
})
</script>

<template>
  <ContextMenu>
    <ContextMenuTrigger
      as-child
      :disabled="!isManageMode"
    >
      <div
        ref="rootElement"
        v-bind="$attrs"
        class="group relative rounded-lg bg-background p-1 shadow-lg transition-all select-none hover:bg-input focus:bg-input focus:outline-2 focus:outline-blue-300"
        :class="{
          'outline-2 outline-accent': selected,
          'opacity-50': cut,
        }"
        :aria-selected="selected"
        role="option"
        tabindex="0"
        @click="handleCardClick"
        @dblclick="handleCardDoubleClick"
        @contextmenu="handleContextMenu"
      >
        <div class="checkerboard relative aspect-square cursor-pointer overflow-hidden rounded-t-[0.325rem]">
          <NuxtImg
            v-if="getFileType(asset.mime_type) === 'image'"
            :src="asset.full_path"
            :alt="String(asset.data?.alt || asset.filename)"
            :width="size"
            :height="size"
            :modifiers="{ crop: 'fill' }"
            class="pointer-events-none h-full w-full object-cover"
          />
          <div
            v-else-if="getFileType(asset.mime_type) === 'video'"
            class="h-full"
            @mouseenter="startThumbnailCycle"
            @mouseleave="stopThumbnailCycle"
          >
            <NuxtImg
              v-if="asset.metadata?.thumbnails?.[hoverThumbnailIndex]"
              :src="asset.metadata.thumbnails[hoverThumbnailIndex].full_path"
              :alt="asset.filename"
              :width="size"
              :height="size"
              crop="fill"
              class="pointer-events-none h-full w-full object-cover transition-opacity duration-300"
            />
            <div
              v-else
              class="flex h-full items-center justify-center"
            >
              <Icon
                name="lucide:file-video"
                size="3rem"
              />
            </div>
            <div class="absolute inset-0 flex items-center justify-center">
              <div class="rounded-full bg-black/50 p-2">
                <Icon
                  name="lucide:play"
                  size="1.5rem"
                  class="text-white"
                />
              </div>
            </div>
          </div>
          <NuxtImg
            v-else-if="asset.metadata?.thumbnails?.[0]?.full_path"
            :src="asset.metadata.thumbnails[0].full_path"
            :alt="asset.filename"
            :width="size"
            :height="size"
            crop="fill"
            class="pointer-events-none h-full w-full object-cover"
          />
          <div
            v-else
            class="flex h-full items-center justify-center"
          >
            <Icon
              :name="getFileIcon(getFileType(asset.mime_type))"
              size="3rem"
            />
          </div>

          <div
            v-if="isSelectMode"
            class="absolute inset-0 flex items-center justify-center rounded-md bg-accent/50 opacity-0 transition-opacity group-hover:opacity-100"
          >
            <Icon
              name="lucide:check"
              size="2rem"
              class="rounded-full border-2 border-accent bg-background p-1 text-accent"
            />
          </div>

          <div
            v-if="asset.rights_status === 'expired'"
            class="absolute top-2 right-2"
          >
            <Badge
              variant="destructive"
              class="shadow-sm backdrop-blur-sm"
            >
              {{ $t('labels.assets.rights.status.expired') }}
            </Badge>
          </div>

          <div
            v-if="resolvedTags.length"
            class="absolute bottom-2 left-2 right-2 flex flex-wrap gap-1"
          >
            <span
              v-for="tag in resolvedTags"
              :key="tag.id"
              class="inline-flex max-w-full items-center rounded-md px-1.5 py-0.5 text-xs font-medium shadow-sm backdrop-blur-sm"
              :style="tag.color ? { backgroundColor: tag.color + 'cc', color: '#fff' } : {}"
              :class="tag.color ? '' : 'bg-black/60 text-white'"
            >
              <IconName
                :icon="tag.icon"
                :name="tag.name"
                class="truncate"
              />
            </span>
          </div>
        </div>
        <div class="flex items-center gap-2 p-2 select-none">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 truncate font-semibold">
              <span class="truncate">{{ asset.filename }}</span>
              <AssetComplianceIndicator
                :issues="complianceIssues"
                severity="error"
              />
            </div>
            <div class="text-sm text-muted">
              {{ asset.extension }} • {{ formatFileSize(asset.size)
              }}<template v-if="asset.metadata?.duration">
                • {{ formatVideoDuration(asset.metadata.duration) }}</template
              >
              • {{ linkedContentsLabel }}
            </div>
          </div>
          <DropdownMenu
            v-if="isManageMode && (canEdit || canDelete)"
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
              <DropdownMenuItem @select="emit('view', asset)">
                <Icon name="lucide:pencil" />
                <span>{{ $t('actions.edit') }}</span>
              </DropdownMenuItem>
              <DropdownMenuItem @select="emit('copy-url', asset)">
                <Icon name="lucide:link" />
                <span>{{ $t('actions.assets.copyUrl') }}</span>
              </DropdownMenuItem>
              <DropdownMenuItem @select="emit('download', asset)">
                <Icon name="lucide:download" />
                <span>{{ $t('actions.assets.download') }}</span>
              </DropdownMenuItem>
              <DropdownMenuSeparator v-if="canEdit" />
              <DropdownMenuItem
                v-if="canEdit"
                @select="emit('move', asset)"
              >
                <Icon name="lucide:folder-input" />
                <span>{{ $t('actions.move') }}</span>
              </DropdownMenuItem>
              <DropdownMenuItem
                v-if="canEdit"
                @select="emit('tag', asset)"
              >
                <Icon name="lucide:tags" />
                <span>{{ $t('actions.assets.tag') }}</span>
              </DropdownMenuItem>
              <DropdownMenuItem
                v-if="canAddToCollection"
                @select="emit('add-to-collection', asset)"
              >
                <Icon name="lucide:layers" />
                <span>{{ $t('actions.assets.addToCollection') }}</span>
              </DropdownMenuItem>
              <DropdownMenuItem
                v-if="canRemoveFromCollection"
                @select="emit('remove-from-collection', asset)"
              >
                <Icon name="lucide:layers" />
                <span>{{ $t('actions.assets.removeFromCollection') }}</span>
              </DropdownMenuItem>
              <DropdownMenuSeparator v-if="canDelete" />
              <DropdownMenuItem
                v-if="canDelete"
                class="text-destructive"
                @select="emit('delete', asset)"
              >
                <Icon name="lucide:trash-2" />
                <span>{{ deleteLabel }}</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
        <div
          v-if="displayCheckbox"
          class="absolute top-2 left-2 transition-opacity"
          :class="selected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'"
        >
          <Checkbox
            :model-value="selected"
            :aria-label="`Select ${asset.filename}`"
            @click.stop="handleCheckboxSelect"
            @dblclick.stop
          />
        </div>
      </div>
    </ContextMenuTrigger>

    <ContextMenuContent v-if="isManageMode">
      <ContextMenuItem @select="emit('view', asset)">
        <Icon name="lucide:eye" />
        <span>{{ $t('actions.open') }}</span>
      </ContextMenuItem>
      <ContextMenuItem @select="emit('copy-url', asset)">
        <Icon name="lucide:link" />
        <span>{{ $t('actions.assets.copyUrl') }}</span>
      </ContextMenuItem>
      <ContextMenuItem @select="emit('download', asset)">
        <Icon name="lucide:download" />
        <span>{{ $t('actions.assets.download') }}</span>
      </ContextMenuItem>
      <ContextMenuSeparator v-if="canEdit" />
      <ContextMenuItem
        v-if="canEdit"
        @select="emit('move', asset)"
      >
        <Icon name="lucide:folder-input" />
        <span>{{ $t('actions.move') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canEdit"
        @select="emit('tag', asset)"
      >
        <Icon name="lucide:tags" />
        <span>{{ $t('actions.assets.tag') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canAddToCollection"
        @select="emit('add-to-collection', asset)"
      >
        <Icon name="lucide:layers" />
        <span>{{ $t('actions.assets.addToCollection') }}</span>
      </ContextMenuItem>
      <ContextMenuItem
        v-if="canRemoveFromCollection"
        @select="emit('remove-from-collection', asset)"
      >
        <Icon name="lucide:layers" />
        <span>{{ $t('actions.assets.removeFromCollection') }}</span>
      </ContextMenuItem>
      <ContextMenuSeparator v-if="canDelete" />
      <ContextMenuItem
        v-if="canDelete"
        class="text-destructive"
        @select="emit('delete', asset)"
      >
        <Icon name="lucide:trash-2" />
        <span>{{ deleteLabel }}</span>
      </ContextMenuItem>
    </ContextMenuContent>
  </ContextMenu>
</template>
