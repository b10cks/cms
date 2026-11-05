<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'

import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetGrid from '~/components/assets/AssetGrid.vue'
import AssetInsertTrigger from '~/components/editor/AssetInsertTrigger.vue'
import AssetRow from '~/components/editor/AssetRow.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import SortHandle from '~/components/ui/SortHandle.vue'
import useSpaceSettings from '~/composables/useSpaceSettings'
import { toAssetValue } from '~/lib/assetFacts'
import { focusGrip, moveInList, sortableOptions } from '~/lib/sortable'

import Label from '../ui/form/Label.vue'

const props = defineProps<{
  modelValue?: AssetValue[] | null
  item: {
    key: string
    name?: string
    required?: boolean
    max?: number | null
    file_types?: FileTypes[]
  }
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AssetValue[] | null]
}>()

const { settings } = useSpaceSettings(props.spaceId)
const { $t } = useI18n()

const localValue = ref<AssetValue[]>([])
const showAssetPicker = ref(false)
const showAssetDetails = ref(false)
const editingAsset = ref<AssetValue | null>(null)
const pickerSelection = ref<AssetResource[]>([])
// Position new assets are inserted at; null appends to the end.
const insertIndex = ref<number | null>(null)
const replaceIndex = ref<number | null>(null)
const listRef = ref<HTMLElement | null>(null)

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

const imagesOnly = computed(() => {
  const types = props.item.file_types ?? []
  return types.length === 1 && types[0] === 'image'
})

const initialFolderId = computed(() => settings.value.assets.lastDialogFolderId)

const updateValue = () => {
  emit('update:modelValue', localValue.value.length > 0 ? localValue.value : null)
}

// Only the grip starts a drag; the handle selector is scoped to this field, so a gallery
// nested in a blocks row never picks up the grips around it.
const sortId = useId()
const handleSelector = `[data-asset-handle="${sortId}"]`

useSortable(
  listRef,
  localValue,
  sortableOptions({
    handle: handleSelector,
    draggable: '>[data-asset-row]',
    disabled: props.readOnly,
    // useSortable writes the new order on the next tick.
    onEnd: () => nextTick(updateValue),
  })
)

const moveAsset = async (index: number, delta: -1 | 1) => {
  if (props.readOnly || !moveInList(localValue.value, index, delta)) return

  updateValue()
  await nextTick()
  focusGrip(listRef.value, handleSelector, index + delta)
}

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
  closeAssetDetails()
}

const handleFolderChange = (folderId: string | null) => {
  settings.value.assets.lastDialogFolderId = folderId
}
</script>

<template>
  <div class="grid w-full min-w-0 gap-2">
    <div class="flex items-center justify-between gap-2">
      <Label
        :label="item.name || item.key"
        :required="item.required"
      />
      <span
        v-if="hasAssets && item.max"
        class="text-sm text-muted tabular-nums"
      >
        {{ $t('labels.assetField.count', { current: localValue.length, max: item.max }) }}
      </span>
    </div>

    <div class="min-w-0 rounded-2xl border border-border bg-surface px-2">
      <p
        v-if="props.readOnly && !hasAssets"
        class="px-1 py-2 text-center text-sm text-muted"
      >
        {{ $t('labels.assets.noAssetsFound') }}
      </p>
      <div
        ref="listRef"
        class="pt-2"
      >
        <div
          v-for="(asset, index) in localValue"
          :key="asset.id"
          data-asset-row
          class="group relative mb-2 flex min-w-0 items-center gap-3 rounded-lg border border-border bg-card p-2 transition-colors duration-100 hover:border-border-strong"
        >
          <AssetInsertTrigger
            v-if="!props.readOnly && canAddMore"
            @add="openPickerAt(index)"
          />
          <SortHandle
            v-if="!props.readOnly"
            :data-asset-handle="sortId"
            :label="$t('actions.assets.reorder')"
            class="-mr-1.5 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100"
            @move="(delta) => moveAsset(index, delta)"
          />
          <AssetRow :asset="asset" />
          <div
            class="absolute top-1/2 right-1.5 flex -translate-y-1/2 rounded-md bg-card opacity-0 group-hover:opacity-100 group-focus-within:opacity-100"
          >
            <button
              v-if="!props.readOnly"
              type="button"
              class="row-action"
              :aria-label="$t('actions.assets.replace')"
              :title="$t('actions.assets.replace')"
              @click.stop="handleAssetReplace(index)"
            >
              <Icon name="lucide:replace" />
            </button>
            <button
              type="button"
              class="row-action"
              :aria-label="$t('labels.assetField.details')"
              :title="$t('labels.assetField.details')"
              @click.stop="handleAssetEdit(asset)"
            >
              <Icon name="lucide:sliders-horizontal" />
            </button>
            <button
              v-if="!props.readOnly"
              type="button"
              class="row-action hover:text-destructive-ink"
              :aria-label="$t('actions.assets.remove')"
              :title="$t('actions.assets.remove')"
              @click.stop="handleAssetDelete(index)"
            >
              <Icon name="lucide:trash-2" />
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="!props.readOnly && canAddMore"
        class="group/add relative mb-0.5 flex h-7 items-center justify-center"
      >
        <div
          class="absolute inset-x-0 top-1/2 border-t-2 border-accent opacity-0 transition-opacity group-hover/add:opacity-100 group-has-[:focus-visible]/add:opacity-100"
        />
        <button
          type="button"
          class="relative flex size-6 cursor-pointer items-center justify-center rounded-full border border-border-strong bg-background text-muted transition-colors group-hover/add:border-transparent group-hover/add:bg-accent-fill group-hover/add:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          :aria-label="
            $t(
              hasAssets
                ? imagesOnly
                  ? 'labels.assetField.addImages'
                  : 'labels.assetField.addFiles'
                : imagesOnly
                  ? 'labels.assetField.chooseImages'
                  : 'labels.assetField.chooseFiles'
            )
          "
          :title="$t('labels.assets.addAsset')"
          @click="openPickerAt(null)"
        >
          <Icon name="lucide:plus" />
        </button>
      </div>
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
      <DialogContent
        class="h-[90dvh] gap-4 !max-w-[90dvw] p-0"
        :scroll-body="false"
      >
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
          class="flex items-center justify-between gap-4 border-t border-border-strong px-6 py-3"
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
