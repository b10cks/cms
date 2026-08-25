<script setup lang="ts">
import { toast } from 'vue-sonner'

import AssetGrid from '~/components/assets/AssetGrid.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import Label from '~/components/ui/form/Label.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import useSpaceSettings from '~/composables/useSpaceSettings'

const props = defineProps<{
  spaceId: string
  label?: string
  description?: string
  readOnly?: boolean
}>()

const modelValue = defineModel<string | null>()

const { $t } = useI18n()
const { settings } = useSpaceSettings(props.spaceId)

const showAssetPicker = ref(false)

const initialFolderId = computed(() => settings.value.assets.lastDialogFolderId ?? null)

const handleFolderChange = (folderId: string | null) => {
  settings.value.assets.lastDialogFolderId = folderId
}

const handleAssetSelect = (asset: AssetResource) => {
  if (!asset.mime_type?.startsWith('image/')) {
    toast.error($t('labels.imageField.imagesOnly'))
    return
  }

  modelValue.value = asset.full_path
  showAssetPicker.value = false
}

const handleRemove = () => {
  modelValue.value = null
}
</script>

<template>
  <div class="grid gap-2">
    <Label
      v-if="label"
      :label="label"
    />

    <button
      v-if="!modelValue"
      type="button"
      :disabled="readOnly"
      :class="[
        'flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-input bg-surface/50 p-6 text-sm text-muted transition-colors',
        readOnly ? 'cursor-default' : 'cursor-pointer hover:bg-surface hover:text-primary',
      ]"
      @click="showAssetPicker = true"
    >
      <Icon name="lucide:image-plus" />
      {{ $t('labels.imageField.select') }}
    </button>

    <div
      v-else
      class="group relative max-w-sm overflow-hidden rounded-lg border border-input bg-surface"
    >
      <NuxtImg
        :src="modelValue"
        :alt="label || ''"
        :width="384"
        :height="216"
        :modifiers="{ crop: 'fit' }"
        class="h-40 w-full bg-surface/50 object-contain"
      />
      <div
        v-if="!readOnly"
        class="absolute inset-0 flex items-center justify-center gap-2 bg-background/70 opacity-0 transition-opacity group-hover:opacity-100"
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          @click="showAssetPicker = true"
        >
          <Icon name="lucide:replace" />
          {{ $t('actions.assets.replace') }}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          @click="handleRemove"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('actions.assets.remove') }}
        </Button>
      </div>
    </div>

    <p
      v-if="description"
      class="text-xs text-muted"
    >
      {{ description }}
    </p>

    <Dialog
      v-if="!readOnly"
      v-model:open="showAssetPicker"
      :modal="true"
    >
      <DialogContent
        class="h-[90dvh] gap-4 !max-w-[90dvw] p-0"
        :scroll-body="false"
      >
        <DialogHeader>
          <DialogTitle>{{ $t('labels.imageField.dialogTitle') }}</DialogTitle>
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
  </div>
</template>
