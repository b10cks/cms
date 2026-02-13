<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'

import { Checkbox } from '~/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import type { AssetResource } from '~/types/assets'

const { formatFileSize } = useFormat()
const { getFileIcon, getFileType } = useFileUtils()

export interface AssetItemProps {
  asset: AssetResource
  selected?: boolean
  draggable?: boolean
  size?: number
  mode?: 'manage' | 'select'
  showExtension?: boolean
  showCheckbox?: boolean
}

const props = withDefaults(defineProps<AssetItemProps>(), {
  selected: false,
  draggable: false,
  size: 284,
  mode: 'manage',
  showExtension: true,
  showCheckbox: true,
})

const emit = defineEmits<{
  select: [asset: AssetResource, selected?: boolean]
  view: [asset: AssetResource]
  delete: [asset: AssetResource]
  move: [asset: AssetResource]
}>()

const isSelectMode = computed(() => props.mode === 'select')
const isManageMode = computed(() => props.mode === 'manage')
const enableDragAndDrop = computed(() => props.draggable && isManageMode.value)
const displayCheckbox = computed(() => props.showCheckbox && isManageMode.value)

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

// Handle drag functionality
function onDragStart(event: DragEvent) {
  if (!event.dataTransfer || !enableDragAndDrop.value) return

  // Set the drag data with the asset ID and type
  event.dataTransfer.setData(
    'application/json',
    JSON.stringify({
      type: 'asset',
      id: props.asset.id,
      selected: props.selected,
    })
  )

  // Set a drag image/effect
  event.dataTransfer.effectAllowed = 'move'
}
</script>

<template>
  <div
    class="group relative rounded-lg bg-background p-1 shadow-lg transition-all hover:bg-input focus:bg-input focus:outline-2 focus:outline-blue-300"
    :class="{ 'rotate-1 outline-2 outline-accent': selected }"
    :aria-selected="selected"
    role="option"
    tabindex="0"
    :draggable="enableDragAndDrop"
    @keydown="handleKeyDown"
    @dragstart="onDragStart"
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
        <div class="truncate font-semibold">
          {{ asset.filename }}
        </div>
        <div class="text-sm text-muted">
          {{ asset.extension }} • {{ formatFileSize(asset.size) }}
        </div>
      </div>
      <DropdownMenu class="ml-auto">
        <DropdownMenuTrigger class="transition-colors hover:text-primary">
          <Icon name="lucide:ellipsis-vertical" />
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem @select="handleView">
            <Icon name="lucide:pencil" />
            <span>Edit</span>
          </DropdownMenuItem>
          <DropdownMenuItem @select="$emit('move', asset)">
            <Icon name="lucide:folder-input" />
            <span>Move</span>
          </DropdownMenuItem>
          <DropdownMenuItem
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
