<script setup lang="ts">
import { toast } from 'vue-sonner'

import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetGrid from '~/components/assets/AssetGrid.vue'
import AssetRow from '~/components/editor/AssetRow.vue'
import Icon from '~/components/Icon.vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import Label from '~/components/ui/form/Label.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Spinner } from '~/components/ui/spinner'
import useSpaceSettings from '~/composables/useSpaceSettings'
import { toAssetValue } from '~/lib/assetFacts'

const props = defineProps<{
  item: AssetSchema & { key: string }
  modelValue?: AssetValue | null
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AssetValue | null]
}>()

const { settings } = useSpaceSettings(props.spaceId)
const { $t } = useI18n()
const { getFileType } = useFileUtils()
const { uploadAsset } = useAssets(props.spaceId)

const localValue = ref<AssetValue | null>(null)
const showAssetPicker = ref(false)
const showAssetDetails = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const dragging = ref(false)

watch(
  () => props.modelValue,
  (newValue) => {
    localValue.value = newValue ? { ...newValue } : null
  },
  { immediate: true, deep: true }
)

const currentAssetResource = computed(() => localValue.value as unknown as AssetResource | null)

// Only kinds the browser can name narrow the file dialog; documents and archives span too many types.
const MEDIA_ACCEPT: Partial<Record<FileTypes, string>> = {
  image: 'image/*',
  video: 'video/*',
  audio: 'audio/*',
}
const allowedTypes = computed(() => {
  const types = props.item.file_types ?? []
  return types.length && types.every((type) => type in MEDIA_ACCEPT) ? types : null
})
const accept = computed(() => allowedTypes.value?.map((type) => MEDIA_ACCEPT[type]).join(','))
const imagesOnly = computed(
  () => allowedTypes.value?.length === 1 && allowedTypes.value[0] === 'image'
)

const updateValue = () => {
  emit('update:modelValue', localValue.value)
}

const initialFolderId = computed(() => {
  const lastFolderId = settings.value.assets.lastDialogFolderId
  return lastFolderId !== undefined ? lastFolderId : (props.item.folder_id ?? null)
})

const handleFolderChange = (folderId: string | null) => {
  settings.value.assets.lastDialogFolderId = folderId
}

const handleAssetSelect = (asset: AssetResource) => {
  localValue.value = toAssetValue(asset)
  updateValue()
  showAssetPicker.value = false
}

// Clearing the field only stages a change; the entry still has to be saved,
// so a confirmation here would ask twice for the same decision.
const handleAssetRemove = () => {
  localValue.value = null
  updateValue()
}

const handleAssetDetailsUpdate = (asset: AssetResource) => {
  localValue.value = asset as unknown as AssetValue
  updateValue()
  showAssetDetails.value = false
}

/** Uploads into the field's folder. A file the library already holds is used as it is. */
const upload = async (file: File) => {
  if (allowedTypes.value && !allowedTypes.value.includes(getFileType(file.type) as FileTypes)) {
    toast.error($t('labels.assetField.wrongType') as string)
    return
  }

  uploading.value = true
  try {
    const outcome = await uploadAsset({ file, folder_id: props.item.folder_id ?? null })
    if (outcome?.status === 'success') handleAssetSelect(outcome.asset)
    if (outcome?.status === 'duplicate') handleAssetSelect(outcome.duplicate.existing_asset)
  } finally {
    uploading.value = false
  }
}

const onFileChosen = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file) upload(file)
}

const canDrop = computed(() => !props.readOnly && !uploading.value)

const onDragOver = (event: DragEvent) => {
  if (!canDrop.value || !event.dataTransfer?.types.includes('Files')) return
  event.preventDefault()
  dragging.value = true
}

const onDrop = (event: DragEvent) => {
  dragging.value = false
  const file = event.dataTransfer?.files[0]
  if (!canDrop.value || !file) return
  event.preventDefault()
  upload(file)
}
</script>

<template>
  <div class="grid gap-2">
    <Label
      :label="item.name || item.key"
      :required="item.required"
    />

    <div
      v-if="localValue"
      class="flex min-w-0 items-center gap-3 rounded-lg border border-input-border bg-input p-1.5 pr-2 shadow-sm"
    >
      <AssetRow :asset="localValue" />
      <div class="flex shrink-0">
        <button
          v-if="!props.readOnly"
          type="button"
          class="row-action"
          :aria-label="$t('actions.assets.replace')"
          :title="$t('actions.assets.replace')"
          @click="showAssetPicker = true"
        >
          <Icon name="lucide:replace" />
        </button>
        <button
          type="button"
          class="row-action"
          :aria-label="$t('labels.assetField.details')"
          :title="$t('labels.assetField.details')"
          @click="showAssetDetails = true"
        >
          <Icon name="lucide:sliders-horizontal" />
        </button>
        <button
          v-if="!props.readOnly"
          type="button"
          class="row-action hover:text-destructive-ink"
          :aria-label="$t('actions.assets.remove')"
          :title="$t('actions.assets.remove')"
          @click="handleAssetRemove"
        >
          <Icon name="lucide:trash-2" />
        </button>
      </div>
    </div>

    <div
      v-else
      :class="[
        'flex h-9 min-w-0 items-center gap-1 rounded-lg border bg-input pr-1 shadow-sm has-[[data-choose]:focus-visible]:ring-2 has-[[data-choose]:focus-visible]:ring-ring',
        dragging ? 'border-ring ring-2 ring-ring' : 'border-input-border',
      ]"
      @dragover="onDragOver"
      @dragleave="dragging = false"
      @drop="onDrop"
    >
      <button
        type="button"
        data-choose
        class="flex h-full min-w-0 grow cursor-pointer items-center gap-2 rounded-l-lg pl-3 text-left text-sm text-input-muted outline-none disabled:cursor-default"
        :disabled="props.readOnly"
        @click="showAssetPicker = true"
      >
        <Icon
          :name="imagesOnly ? 'lucide:image' : 'lucide:paperclip'"
          class="shrink-0"
        />
        <span class="truncate">
          {{
            props.readOnly
              ? $t('labels.assetField.none')
              : $t(imagesOnly ? 'labels.assetField.chooseImage' : 'labels.assetField.chooseFile')
          }}
        </span>
      </button>
      <span
        v-if="uploading"
        class="flex size-7 items-center justify-center text-muted"
        role="status"
        :aria-label="$t('labels.assetField.uploading')"
      >
        <Spinner />
      </span>
      <button
        v-else-if="!props.readOnly"
        type="button"
        class="row-action"
        :aria-label="$t('actions.assets.upload')"
        :title="$t('actions.assets.upload')"
        @click="fileInput?.click()"
      >
        <Icon name="lucide:upload" />
      </button>
      <input
        v-if="!props.readOnly"
        ref="fileInput"
        type="file"
        class="hidden"
        :accept="accept"
        @change="onFileChosen"
      />
    </div>

    <Dialog
      v-if="!props.readOnly"
      v-model:open="showAssetPicker"
      :modal="true"
    >
      <DialogContent
        class="h-[90dvh] gap-4 !max-w-[90dvw] p-0"
        :scroll-body="false"
      >
        <DialogHeader>
          <DialogTitle>{{ $t('labels.assets.selectAsset') }}</DialogTitle>
        </DialogHeader>

        <ScrollArea class="flex-1">
          <AssetGrid
            :space-id="spaceId"
            :initial-folder-id="initialFolderId"
            mode="select"
            class="mt-2"
            @asset-select="handleAssetSelect"
            @folder-change="handleFolderChange"
          />
        </ScrollArea>
      </DialogContent>
    </Dialog>

    <AssetDetailsDialog
      v-if="localValue && showAssetDetails"
      :asset="currentAssetResource"
      :folder-id="null"
      :space-id="spaceId"
      :read-only="props.readOnly"
      mode="reduced"
      @update:asset="handleAssetDetailsUpdate"
      @close="showAssetDetails = false"
    />
  </div>
</template>
