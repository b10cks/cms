<script setup lang="ts">
import AssetComplianceIndicator from '~/components/assets/AssetComplianceIndicator.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Spinner } from '~/components/ui/spinner'
import type { AssetRequirementIssue } from '~/composables/useAssetRequirements'
import type { StagedUploadFile } from '~/composables/useAssetUploadBatch'

const props = defineProps<{
  file: StagedUploadFile
  /** Nesting depth of the folder this file sits in; 0 at the drop target. */
  level: number
  /** Object-URL previews only exist below the compact threshold. */
  showPreview: boolean
  allowDetails: boolean
  canRetry: boolean
  missingFields: (file: StagedUploadFile) => AssetRequirementIssue[]
}>()

const emit = defineEmits<{
  remove: [id: string]
  details: [file: StagedUploadFile]
  retry: [id: string]
}>()

const { t } = useI18n()
const { formatFileSize } = useFormat()
const { getFileIcon } = useFileUtils()

const statusLabel = computed(() => {
  if (props.file.errorMessage) {
    return props.file.errorMessage
  }

  return props.file.cancelled
    ? String(t('labels.assets.batch.cancelled'))
    : String(t('labels.assets.unknown'))
})

const statusIcon = computed(() => {
  switch (props.file.status) {
    case 'complete':
      return 'lucide:check'
    case 'error':
      return 'lucide:alert-triangle'
    default:
      return 'lucide:circle-dashed'
  }
})

const canRemove = computed(() => props.file.status !== 'uploading' && !props.file.enqueued)

const hasThumbnail = computed(
  () => props.showPreview && props.file.type === 'image' && Boolean(props.file.preview)
)

const openDetails = () => {
  if (props.allowDetails) {
    emit('details', props.file)
  }
}
</script>

<template>
  <div
    :style="{ 'padding-left': `${level + 0.5}rem` }"
    :role="allowDetails ? 'button' : undefined"
    :tabindex="allowDetails ? 0 : undefined"
    :class="[
      'group flex items-center gap-2 rounded-md py-1 pr-2 text-sm outline-none',
      allowDetails ? 'cursor-pointer transition-colors duration-200 hover:bg-input' : '',
    ]"
    @click="openDetails"
    @keydown.enter.prevent="openDetails"
    @keydown.space.prevent="openDetails"
  >
    <span class="flex w-5 shrink-0 justify-center">
      <Spinner
        v-if="file.status === 'uploading'"
        class="size-4"
      />
      <Icon
        v-else
        :name="statusIcon"
        class="size-4"
        :class="{
          'text-green-500': file.status === 'complete',
          'text-destructive': file.status === 'error',
          'text-muted': file.status === 'pending',
        }"
      />
    </span>

    <span
      :class="[
        'flex size-6 shrink-0 items-center justify-center overflow-hidden rounded',
        hasThumbnail ? 'checkerboard' : '',
      ]"
    >
      <img
        v-if="hasThumbnail"
        :src="file.preview"
        :alt="file.file.name"
        class="h-full w-full object-cover"
      />
      <Icon
        v-else
        :name="getFileIcon(file.type)"
        class="size-4 text-muted"
      />
    </span>

    <span class="min-w-0 flex-1 truncate">{{ file.file.name }}</span>

    <AssetComplianceIndicator
      :issues="missingFields(file)"
      severity="warning"
    />

    <span class="shrink-0 text-xs text-muted">{{ formatFileSize(file.file.size) }}</span>

    <span
      v-if="file.status === 'uploading'"
      class="w-9 shrink-0 text-right text-xs text-muted"
    >
      {{ file.progress }}%
    </span>
    <span
      v-else-if="file.status === 'error'"
      class="max-w-40 shrink-0 truncate text-xs text-destructive"
      :title="statusLabel"
    >
      {{ statusLabel }}
    </span>

    <Button
      v-if="canRetry"
      size="sm"
      class="shrink-0"
      @click.stop="emit('retry', file.id)"
    >
      {{ $t('actions.retry') }}
    </Button>

    <button
      v-if="canRemove"
      class="flex size-5 shrink-0 cursor-pointer items-center justify-center text-muted opacity-0 transition-opacity duration-200 group-hover:opacity-100 hover:text-destructive focus:opacity-100"
      :aria-label="$t('actions.remove')"
      @click.stop="emit('remove', file.id)"
    >
      <Icon name="lucide:trash-2" />
    </button>
  </div>
</template>
