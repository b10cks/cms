<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { draggable } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'

import AssetComplianceIndicator from '~/components/assets/AssetComplianceIndicator.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Checkbox } from '~/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import type { AssetRequirementIssue } from '~/composables/useAssetRequirements'
import {
  createAssetManagerDragData,
  setAssetManagerDragPreview,
  type AssetManagerDragItem,
} from '~/lib/assets/assetDragAndDrop'
import type { AssetResource } from '~/types/assets'

const { t } = useI18n()
const { formatFileSize } = useFormat()
const { getFileIcon, getFileType } = useFileUtils()

export interface AssetItemProps {
  asset: AssetResource
  selected?: boolean
  draggable?: boolean
  size?: number
  mode?: 'manage' | 'select'
  canEdit?: boolean
  canDelete?: boolean
  showExtension?: boolean
  showCheckbox?: boolean
  dragItems?: AssetManagerDragItem[]
  complianceIssues?: AssetRequirementIssue[]
}

const props = withDefaults(defineProps<AssetItemProps>(), {
  selected: false,
  draggable: false,
  size: 284,
  mode: 'manage',
  canEdit: true,
  canDelete: true,
  showExtension: true,
  showCheckbox: true,
  dragItems: () => [],
  complianceIssues: () => [],
})

const emit = defineEmits<{
  select: [asset: AssetResource, selected?: boolean]
  view: [asset: AssetResource]
  delete: [asset: AssetResource]
}>()

const isSelectMode = computed(() => props.mode === 'select')
const isManageMode = computed(() => props.mode === 'manage')
const enableDragAndDrop = computed(() => props.draggable && isManageMode.value)
const displayCheckbox = computed(() => props.showCheckbox && isManageMode.value)

const hoverThumbnailIndex = ref(0)
let thumbnailInterval: ReturnType<typeof setInterval> | null = null

const startThumbnailCycle = () => {
  const thumbs = props.asset.metadata.thumbnails
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
  return resolvedDragItems.value.length > 1
    ? String(t('labels.selectionCount', { count: resolvedDragItems.value.length }))
    : props.asset.filename
})
const linkedContentsLabel = computed(() => {
  return props.asset.linked_contents_count === 1
    ? String(t('labels.assets.linkedContentsSingle'))
    : String(
        t('labels.assets.linkedContentsMultiple', { count: props.asset.linked_contents_count })
      )
})

function handleSelect(event: Event) {
  event.stopPropagation()

  if (isSelectMode.value) {
    emit('select', props.asset)
  } else {
    emit('select', props.asset, !props.selected)
  }
}

function handleView(event: Event) {
  if (event.type === 'keydown' && (event as KeyboardEvent).key !== 'Enter') {
    return
  }
  event.stopPropagation()

  if (isSelectMode.value) {
    emit('select', props.asset)
  } else {
    emit('view', props.asset)
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === ' ' || event.key === 'Spacebar') {
    event.preventDefault() // Prevent scrolling
    if (isSelectMode.value) {
      handleView(event)
    } else {
      handleSelect(event)
    }
  } else if (event.key === 'Enter') {
    handleView(event)
  }
}

watchEffect((onCleanup) => {
  if (!rootElement.value) {
    return
  }

  const cleanup = combine(
    draggable({
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
  <div
    ref="rootElement"
    class="group relative rounded-lg bg-background p-1 shadow-lg transition-all hover:bg-input focus:bg-input focus:outline-2 focus:outline-blue-300"
    :class="{ 'rotate-1 outline-2 outline-accent': selected }"
    :aria-selected="selected"
    role="option"
    tabindex="0"
    @keydown="handleKeyDown"
  >
    <div
      class="checkerboard relative aspect-square cursor-pointer overflow-hidden rounded-t-[0.325rem]"
      @click="handleView"
    >
      <NuxtImg
        v-if="getFileType(asset.mime_type) === 'image'"
        :src="asset.full_path"
        :alt="String(asset.data?.alt || asset.filename)"
        :width="size"
        :height="size"
        :modifiers="{ crop: 'fill' }"
        class="pointer-events-none h-full w-full object-cover"
      />
      <template
        v-else-if="getFileType(asset.mime_type) === 'video'"
        @mouseenter="startThumbnailCycle"
        @mouseleave="stopThumbnailCycle"
      >
        <NuxtImg
          v-if="asset.metadata.thumbnails?.[hoverThumbnailIndex]"
          :src="asset.metadata.thumbnails[hoverThumbnailIndex].path"
          :alt="asset.filename"
          :width="size"
          :height="size"
          :modifiers="{ crop: 'fill' }"
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
      </template>
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
    </div>
    <div class="flex items-center gap-2 p-2">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 truncate font-semibold">
          <span class="truncate">{{ asset.filename }}</span>
          <AssetComplianceIndicator
            :issues="complianceIssues"
            severity="error"
          />
        </div>
        <div class="text-sm text-muted">
          {{ asset.extension }} • {{ formatFileSize(asset.size) }}<template
            v-if="asset.metadata.duration"
          > • {{ formatVideoDuration(asset.metadata.duration) }}</template> • {{ linkedContentsLabel }}
        </div>
      </div>
      <DropdownMenu
        v-if="canEdit || canDelete"
        class="ml-auto"
      >
        <DropdownMenuTrigger class="transition-colors hover:text-primary">
          <Icon name="lucide:ellipsis-vertical" />
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem
            v-if="canEdit"
            @select="handleView"
          >
            <Icon name="lucide:pencil" />
            <span>{{ $t('actions.edit') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem
            v-if="canDelete"
            class="text-destructive"
            @select="$emit('delete', asset)"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ $t('actions.delete') }}</span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
    <div
      v-if="displayCheckbox"
      class="absolute top-2 left-2"
    >
      <Checkbox
        :model-value="selected"
        :aria-label="`Select ${asset.filename}`"
        @click.stop="handleSelect"
      />
    </div>
  </div>
</template>
