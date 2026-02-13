<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'

import AssetsIcon from '~/assets/images/assets.svg?component'
import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetGrid from '~/components/assets/AssetGrid.vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import Label from '~/components/ui/form/Label.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import { useAlertDialog } from '~/composables/useAlertDialog'

const props = defineProps<{
  item: AssetSchema & { key: string }
  modelValue?: AssetValue | null
  spaceId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: AssetValue | null]
}>()

const { alert } = useAlertDialog()
const { $t } = useI18n()
const { getFileIcon, getFileType } = useFileUtils()

const localValue = ref<AssetValue | null>(null)
const showAssetPicker = ref(false)
const showAssetDetails = ref(false)

watch(
  () => props.modelValue,
  (newValue) => {
    localValue.value = newValue ? { ...newValue } : null
  },
  { immediate: true, deep: true }
)

const hasAsset = computed(() => !!localValue.value)
const isImage = computed(() => {
  if (!localValue.value) return false

  return getFileType(localValue.value.mime_type) === 'image'
})

const updateValue = () => {
  emit('update:modelValue', localValue.value)
}

const handleAssetSelect = (asset: AssetResource) => {
  localValue.value = {
    id: asset.id,
    type: 'asset',
    full_path: asset.full_path,
    extension: asset.extension,
    mime_type: asset.mime_type,
    size: asset.size,
    filename: asset.filename,
    data: {},
  }
  updateValue()
  showAssetPicker.value = false
}

const handleAssetReplace = () => {
  showAssetPicker.value = true
}

const handleAssetEdit = () => {
  showAssetDetails.value = true
}

const handleAssetDelete = async () => {
  const confirmed = await alert.confirm($t('messages.assets.confirmDelete'), {
    title: $t('labels.assets.deleteAsset'),
    confirmLabel: $t('actions.delete'),
    cancelLabel: $t('actions.cancel'),
  })

  if (confirmed) {
    localValue.value = null
    updateValue()
  }
}
</script>

<template>
  <div class="grid gap-2">
    <Label :label="item.name || item.key" />
    <div
      v-if="!hasAsset"
      class="cursor-pointer rounded-lg border-2 border-dashed border-input bg-surface/50 p-8 text-center transition-colors hover:bg-surface"
      @click="showAssetPicker = true"
    >
      <AssetsIcon class="mx-auto mb-3 size-16 text-muted" />
      <p class="mb-1 text-sm font-medium text-primary">
        {{ $t('labels.assets.addAsset') }}
      </p>
      <p class="text-xs text-muted">
        {{ $t('labels.assets.addAssetDescription') }}
      </p>
    </div>

    <div
      v-else
      class="group relative max-w-sm overflow-hidden rounded-lg border border-input bg-surface"
    >
      <div class="flex items-center gap-3 p-2">
        <div class="flex-shrink-0">
          <div
            v-if="isImage"
            class="h-14 w-14 overflow-hidden rounded border border-input bg-background"
          >
            <NuxtImg
              :src="localValue.full_path"
              :alt="localValue.data?.altText || localValue.filename"
              width="56"
              height="56"
              :modifiers="{ crop: 'fill' }"
              class="h-full w-full object-cover"
            />
          </div>
          <div
            v-else
            class="flex h-12 w-12 items-center justify-center rounded border border-input bg-background"
          >
            <Icon
              :name="getFileIcon(getFileType(localValue.mime_type))"
              class="text-muted"
            />
          </div>
        </div>
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold text-primary">
            {{ localValue.filename }}
          </p>
          <p class="text-sm text-muted">
            {{ $t('labels.assets.asset') }}
          </p>
        </div>

        <div class="ml-auto flex items-center gap-2 opacity-0 group-hover:opacity-100">
          <button
            class="flex transform cursor-pointer items-center hover:text-primary"
            :title="$t('actions.assets.replace')"
            @click.stop="handleAssetReplace"
          >
            <Icon name="lucide:replace" />
          </button>
          <button
            class="flex transform cursor-pointer items-center hover:text-primary"
            :title="$t('actions.assets.edit')"
            @click.stop="handleAssetEdit"
          >
            <Icon name="lucide:pencil" />
          </button>
          <button
            class="flex transform cursor-pointer items-center hover:text-red-500"
            :title="$t('actions.assets.delete')"
            @click.stop="handleAssetDelete"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </div>
    </div>

    <Dialog
      v-model:open="showAssetPicker"
      :modal="true"
    >
      <DialogContent class="h-[90dvh] !max-w-[90dvw] p-0">
        <DialogHeader>
          <DialogTitle>{{ $t('labels.assets.selectAsset') }}</DialogTitle>
        </DialogHeader>

        <ScrollArea class="flex-1">
          <AssetGrid
            :space-id="spaceId"
            :folder-id="item.folder_id || null"
            mode="select"
            class="mt-2"
            @asset-select="handleAssetSelect"
          />
        </ScrollArea>
      </DialogContent>
    </Dialog>

    <AssetDetailsDialog
      v-if="localValue && showAssetDetails"
      v-model:asset="localValue"
      :folder-id="null"
      :space-id="spaceId"
      mode="reduced"
      @close="showAssetDetails = false"
    />
  </div>
</template>
