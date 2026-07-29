<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'

const { formatFileSize } = useFormat()
const { getFileType, getFileIcon } = useFileUtils()

const props = defineProps<{
  open: boolean
  filename: string
  duplicate: AssetUploadDuplicate
}>()

const emit = defineEmits<{
  'update:open': [open: boolean]
  useExisting: []
  uploadAnyway: []
}>()

const existingIsImage = computed(
  () => getFileType(props.duplicate.existing_asset.mime_type) === 'image'
)

const onOpenChange = (open: boolean) => {
  if (!open) {
    emit('update:open', false)
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="onOpenChange"
  >
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.assets.duplicate.title')"
        :description="$t('labels.assets.duplicate.description', { filename })"
      />

      <div class="flex items-center gap-4 rounded-lg bg-surface p-3">
        <div class="checkerboard flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg">
          <img
            v-if="existingIsImage"
            :src="duplicate.existing_asset.url"
            :alt="duplicate.existing_asset.filename"
            class="h-full w-full object-cover"
          />
          <Icon
            v-else
            :name="getFileIcon(getFileType(duplicate.existing_asset.mime_type))"
            size="1.5rem"
          />
        </div>
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold">
            {{ duplicate.existing_asset.filename }}.{{ duplicate.existing_asset.extension }}
          </p>
          <p class="text-sm text-muted">
            {{ formatFileSize(duplicate.existing_asset.size) }}
          </p>
        </div>
      </div>

      <p class="text-sm text-muted">
        {{ $t('labels.assets.duplicate.hint') }}
      </p>

      <DialogFooter class="flex items-center justify-between sm:justify-between">
        <Button
          variant="outline"
          @click="emit('uploadAnyway')"
        >
          {{ $t('labels.assets.duplicate.uploadAnyway') }}
        </Button>
        <Button
          variant="primary"
          @click="emit('useExisting')"
        >
          {{ $t('labels.assets.duplicate.useExisting') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
