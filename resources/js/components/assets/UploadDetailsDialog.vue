<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogHeaderCombined,
} from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'

const { formatFileSize, formatDateTime } = useFormat()
const { getFileIcon } = useFileUtils()

const props = defineProps<{
  file: UploadFile
  folderId: string | null
  spaceId: string
  open: boolean
  onReplace: () => void
}>()

const localFile = ref<UploadFile>(props.file)

// Ensure data object exists and properties are strings
if (!localFile.value.data) {
  localFile.value.data = {}
}
if (typeof localFile.value.data.altText !== 'string') {
  localFile.value.data.altText = ''
}
if (typeof localFile.value.data.description !== 'string') {
  localFile.value.data.description = ''
}
if (typeof localFile.value.data.copyright !== 'string') {
  localFile.value.data.copyright = ''
}
const emit = defineEmits(['update:open', 'update:file'])

const handleFinish = () => {
  emit('update:file', localFile.value)
  emit('update:open', false)
}

const onOpenChange = (open: boolean) => {
  emit('update:open', open)
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="onOpenChange"
  >
    <DialogContent class="sm:max-w-[900px]">
      <DialogHeaderCombined :title="$t('labels.assets.uploadDetails')" />
      <DialogHeader />
      <div class="grid gap-6 py-4 md:grid-cols-2">
        <div class="checkerboard flex flex-col items-center justify-center rounded-xl p-4">
          <div
            v-if="file.type === 'image' && file.preview"
            class="relative h-[300px] w-full"
          >
            <img
              :src="file.preview"
              :alt="file.file.name"
              class="h-full w-full object-contain"
            />
          </div>
          <div
            v-else
            class="flex h-[300px] w-full flex-col items-center justify-center gap-4"
          >
            <Icon
              :name="getFileIcon(localFile.type)"
              class="h-16 w-16"
            />
            <div class="text-center">
              <p class="font-semibold">{{ localFile.file.name }}</p>
              <p class="text-sm text-muted">{{ formatFileSize(localFile.file.size) }}</p>
            </div>
          </div>
        </div>
        <div class="space-y-4">
          <dl class="grid grid-cols-2 gap-2 rounded-lg bg-surface p-3 text-sm">
            <dt class="font-semibold">{{ $t('labels.content.name') }}:</dt>
            <dd>{{ localFile.file.name }}</dd>
            <dt class="font-semibold">{{ $t('labels.content.type') }}:</dt>
            <dd>{{ localFile.file.type || $t('labels.assets.unknown') }}</dd>
            <dt class="font-semibold">{{ $t('labels.assets.size') }}:</dt>
            <dd>{{ formatFileSize(localFile.file.size) }}</dd>
            <dt class="font-semibold">{{ $t('labels.assets.lastModified') }}:</dt>
            <dd>{{ formatDateTime(localFile.file.lastModified) }}</dd>
          </dl>

          <div class="grid gap-4">
            <InputField
              v-model="localFile.data.altText as string"
              :label="String($t('labels.assets.fields.altText'))"
              :placeholder="String($t('labels.assets.fields.altTextPlaceholder'))"
              name="altText"
            />
            <InputField
              v-model="localFile.data.description as string"
              :label="String($t('labels.assets.fields.description'))"
              :placeholder="String($t('labels.assets.fields.descriptionPlaceholder'))"
              name="description"
            />
            <InputField
              v-model="localFile.data.copyright as string"
              :label="String($t('labels.assets.fields.copyright'))"
              :placeholder="String($t('labels.assets.fields.copyrightPlaceholder'))"
              name="copyright"
            />
          </div>
        </div>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          @click="onOpenChange(false)"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button
          variant="outline"
          @click="onReplace"
        >
          {{ $t('labels.assets.replaceMedia') }}
        </Button>
        <Button @click="handleFinish">{{ $t('labels.assets.finish') }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
