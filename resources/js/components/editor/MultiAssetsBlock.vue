<script setup lang="ts">
import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetGrid from '~/components/assets/AssetGrid.vue'
import AssetInsertTrigger from '~/components/editor/AssetInsertTrigger.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import useSpaceSettings from '~/composables/useSpaceSettings'

import Label from '../ui/form/Label.vue'

const props = defineProps<{
  modelValue?: AssetValue[] | null
  item: {
    key: string
    name?: string
    required?: boolean
    max?: number | null
  }
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AssetValue[] | null]
}>()

const { settings } = useSpaceSettings(props.spaceId)
const { $t } = useI18n()
const { getFileIcon, getFileType } = useFileUtils()

const localValue = ref<AssetValue[]>([])
const showAssetPicker = ref(false)
const showAssetDetails = ref(false)
const editingAsset = ref<AssetValue | null>(null)
const draggedIndex = ref<number | null>(null)
const pickerSelection = ref<AssetResource[]>([])
// Position new assets are inserted at; null appends to the end.
const insertIndex = ref<number | null>(null)

watch(
  () => props.modelValue,
  (newValue) => {
    localValue.value = newValue ? [...newValue] : []
  },
  { immediate: true, deep: true }
)

const hasAssets = computed(() => localValue.value.length > 0)
const canAddMore = computed(() => {
  if (!props.item.max) return true
  return localValue.value.length < props.item.max
})

const remainingSlots = computed(() => {
  if (!props.item.max) return null
  return props.item.max - localValue.value.length
})

const initialFolderId = computed(() => settings.value.assets.lastDialogFolderId)

const updateValue = () => {
  emit('update:modelValue', localValue.value.length > 0 ? localValue.value : null)
}

const toAssetValue = (asset: AssetResource): AssetValue => ({
  id: asset.id,
  type: 'asset',
  full_path: asset.full_path,
  extension: asset.extension,
  mime_type: asset.mime_type,
  size: asset.size,
  filename: asset.filename,
  data: {},
})

// Opens the multi-select picker, inserting the result at `index` (null appends).
const openPickerAt = (index: number | null) => {
  replaceIndex.value = null
  insertIndex.value = index
  showAssetPicker.value = true
}

const handleAssetSelect = (asset: AssetResource) => {
  if (props.item.max && localValue.value.length >= props.item.max) {
    showAssetPicker.value = false
    return
  }

  localValue.value.push(toAssetValue(asset))
  updateValue()
  showAssetPicker.value = false
}

const handlePickerSelectionChange = (payload: { assets: AssetResource[] }) => {
  pickerSelection.value = payload.assets
}

// Number of selected assets that will actually be added, capped by `item.max`.
const addableCount = computed(() => {
  const remaining = remainingSlots.value
  return remaining === null
    ? pickerSelection.value.length
    : Math.min(pickerSelection.value.length, remaining)
})

const confirmSelection = () => {
  const count = addableCount.value
  if (count <= 0) return

  const newAssets = pickerSelection.value.slice(0, count).map(toAssetValue)
  const at = insertIndex.value ?? localValue.value.length
  localValue.value.splice(at, 0, ...newAssets)

  updateValue()
  pickerSelection.value = []
  insertIndex.value = null
  showAssetPicker.value = false
}

const handleAssetEdit = (asset: AssetValue) => {
  editingAsset.value = { ...asset }
  showAssetDetails.value = true
}

const handleAssetDelete = (index: number) => {
  if (!localValue.value[index]) return

  localValue.value.splice(index, 1)
  updateValue()
}

const handleAssetReplace = (index: number) => {
  insertIndex.value = null
  replaceIndex.value = index
  showAssetPicker.value = true
}

const replaceIndex = ref<number | null>(null)

const handleAssetSelectForReplace = (asset: AssetResource) => {
  if (replaceIndex.value === null) {
    handleAssetSelect(asset)
  } else {
    localValue.value[replaceIndex.value] = toAssetValue(asset)
    updateValue()
    replaceIndex.value = null
  }
  showAssetPicker.value = false
}

const handleDragStart = (index: number) => {
  draggedIndex.value = index
}

const handleDragOver = (e: DragEvent) => {
  e.preventDefault()
}

const handleDrop = (e: DragEvent, targetIndex: number) => {
  if (draggedIndex.value === null) return
  e.preventDefault()

  const draggedAsset = localValue.value[draggedIndex.value]
  localValue.value.splice(draggedIndex.value, 1)
  localValue.value.splice(targetIndex, 0, draggedAsset)

  draggedIndex.value = null
  updateValue()
}

const isImage = (asset: AssetValue) => {
  return getFileType(asset.mime_type) === 'image'
}

const handleAssetDetailsUpdate = (updatedAsset: AssetValue) => {
  const index = localValue.value.findIndex((a) => a.id === updatedAsset.id)
  if (index !== -1) {
    localValue.value[index] = { ...updatedAsset }
    updateValue()
  }
}

const closeAssetDetails = () => {
  showAssetDetails.value = false
  editingAsset.value = null
}

const editingAssetResource = computed(() => editingAsset.value as unknown as AssetResource | null)

const handleAssetDetailsDialogUpdate = (asset: AssetResource) => {
  handleAssetDetailsUpdate(asset as unknown as AssetValue)
}

const handleFolderChange = (folderId: string | null) => {
  settings.value.assets.lastDialogFolderId = folderId
}
</script>

<template>
  <div class="grid w-full min-w-0 gap-2">
    <Label
      :label="item.name || item.key"
      :required="item.required"
    />
    <div class="min-w-0 rounded-2xl border border-border bg-surface px-2">
      <p
        v-if="props.readOnly && !hasAssets"
        class="px-1 py-2 text-center text-sm text-muted"
      >
        {{ $t('labels.assets.noAssetsFound') }}
      </p>
      <div
        class="relative"
        :class="hasAssets ? 'pt-2' : 'pt-6'"
      >
        <div
          v-for="(asset, index) in localValue"
          :key="asset.id"
          class="group relative mb-2 min-w-0 rounded-lg border border-border bg-background p-2 transition-colors"
          :draggable="!props.readOnly"
          @dragstart="!props.readOnly && handleDragStart(index)"
          @dragover="!props.readOnly && handleDragOver($event)"
          @drop="!props.readOnly && handleDrop($event, index)"
        >
          <AssetInsertTrigger
            v-if="!props.readOnly && canAddMore"
            @add="openPickerAt(index)"
          />
          <div class="flex min-w-0 items-center gap-3">
            <div
              v-if="!props.readOnly"
              class="cursor-ns-resize opacity-0 group-hover:opacity-100"
              :title="$t('actions.assets.reorder')"
            >
              <Icon
                name="lucide:grip-vertical"
                class="text-muted hover:text-primary"
              />
            </div>
            <div class="flex-shrink-0">
              <div
                v-if="isImage(asset)"
                class="h-14 w-14 overflow-hidden rounded-md border border-border bg-surface"
              >
                <NuxtImg
                  :src="asset.full_path"
                  :alt="String((asset.data as Record<string, unknown>)?.altText || asset.filename)"
                  :width="128"
                  :height="128"
                  :modifiers="{ crop: 'fill' }"
                  class="h-full w-full object-cover"
                />
              </div>
              <div
                v-else-if="asset.metadata?.thumbnails?.[0]?.full_path"
                class="h-14 w-14 overflow-hidden rounded-md border border-border bg-surface"
              >
                <NuxtImg
                  :src="asset.metadata.thumbnails[0].full_path"
                  :alt="asset.filename"
                  :width="128"
                  :height="128"
                  :modifiers="{ crop: 'fill' }"
                  class="h-full w-full object-cover"
                />
              </div>
              <div
                v-else
                class="flex h-12 w-12 items-center justify-center rounded-md border border-border bg-surface"
              >
                <Icon
                  :name="getFileIcon(getFileType(asset.mime_type))"
                  class="text-muted"
                />
              </div>
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate font-semibold text-primary">
                {{ asset.filename }}
              </p>
              <p class="text-sm text-muted">
                {{ $t('labels.assets.asset') }}
              </p>
            </div>

            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100">
              <button
                v-if="!props.readOnly"
                class="flex transform cursor-pointer items-center hover:text-primary"
                :aria-label="$t('actions.assets.replace') as string"
                :title="$t('actions.assets.replace') as string"
                @click.stop="handleAssetReplace(index)"
              >
                <Icon name="lucide:replace" />
              </button>
              <button
                class="flex transform cursor-pointer items-center hover:text-primary"
                :aria-label="$t('actions.assets.edit') as string"
                :title="$t('actions.assets.edit') as string"
                @click.stop="handleAssetEdit(asset)"
              >
                <Icon name="lucide:pencil" />
              </button>
              <button
                v-if="!props.readOnly"
                class="flex transform cursor-pointer items-center hover:text-red-500"
                :aria-label="$t('actions.assets.remove') as string"
                :title="$t('actions.assets.remove') as string"
                @click.stop="handleAssetDelete(index)"
              >
                <Icon name="lucide:trash-2" />
              </button>
            </div>
          </div>
        </div>

        <AssetInsertTrigger
          v-if="!props.readOnly && canAddMore"
          @add="openPickerAt(null)"
        />
      </div>
    </div>
    <div
      v-if="hasAssets && item.max"
      class="text-center text-xs text-muted"
    >
      {{ $t('labels.assets.assetsCount', { current: localValue.length, max: item.max }) }}
    </div>

    <Dialog
      v-if="!props.readOnly"
      v-model:open="showAssetPicker"
      :modal="true"
      @update:open="
        () => {
          replaceIndex = null
          insertIndex = null
          pickerSelection = []
        }
      "
    >
      <DialogContent class="!flex h-[90dvh] flex-col !max-w-[90dvw] p-0">
        <DialogHeader>
          <DialogTitle>
            {{
              replaceIndex === null
                ? $t('labels.assets.selectAssets')
                : $t('labels.assets.replaceAsset')
            }}
          </DialogTitle>
        </DialogHeader>

        <ScrollArea class="min-h-0 flex-1">
          <AssetGrid
            :space-id="spaceId"
            :initial-folder-id="initialFolderId"
            :mode="replaceIndex === null ? 'multi-select' : 'select'"
            @asset-select="handleAssetSelectForReplace"
            @selection-change="handlePickerSelectionChange"
            @folder-change="handleFolderChange"
          />
        </ScrollArea>

        <div
          v-if="replaceIndex === null"
          class="flex items-center justify-between gap-4 border-t border-input px-6 py-3"
        >
          <p class="text-sm text-muted">
            {{ $t('labels.selectionCount', { count: pickerSelection.length }) }}
          </p>
          <Button
            variant="primary"
            :disabled="addableCount === 0"
            @click="confirmSelection"
          >
            <Icon name="lucide:plus" />
            <span>{{ $t('actions.assets.addSelected', { count: addableCount }) }}</span>
          </Button>
        </div>
      </DialogContent>
    </Dialog>

    <AssetDetailsDialog
      v-if="editingAsset && showAssetDetails"
      :asset="editingAssetResource"
      :folder-id="null"
      :space-id="spaceId"
      :read-only="props.readOnly"
      mode="reduced"
      @close="closeAssetDetails"
      @update:asset="handleAssetDetailsDialogUpdate"
    />
  </div>
</template>
