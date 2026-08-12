<script setup lang="ts">
import type { AssetsQueryParams } from '~/api/resources/assets'
import ExportDialog from '~/components/import-export/ExportDialog.vue'
import type { ExportDialogLabels } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
  filters: Record<string, unknown>
  folderId?: string | null
  tagId?: string | null
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useExportAssetsMutation } = useAssets(props.spaceId)
const exportMutation = useExportAssetsMutation()

const labels = computed<ExportDialogLabels>(() => ({
  title: t('labels.assets.exportDialog.title'),
  description: t('labels.assets.exportDialog.description'),
  formatLabel: t('labels.assets.exportDialog.formatLabel'),
  submit: t('labels.assets.export'),
  fallbackError: t('labels.assets.exportDialog.exportError'),
}))

const formats: { value: ExportTypes; label: string }[] = [
  { value: 'csv', label: 'CSV' },
  { value: 'excel', label: 'Excel' },
  { value: 'json', label: 'JSON' },
  { value: 'xliff', label: 'XLIFF' },
  { value: 'yaml', label: 'YAML' },
]

const submit = (format: ExportTypes): Promise<Blob> => {
  const params: AssetsQueryParams & { as: ExportTypes } = {
    ...props.filters,
    folder: props.folderId ?? undefined,
    tags: props.tagId ?? undefined,
    as: format,
  }

  return exportMutation.mutateAsync(params)
}
</script>

<template>
  <ExportDialog
    v-model:open="open"
    :labels="labels"
    :formats="formats"
    :submit="submit"
    filename-prefix="assets-export"
  />
</template>
