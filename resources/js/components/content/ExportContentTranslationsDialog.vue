<script setup lang="ts">
import ExportDialog from '~/components/import-export/ExportDialog.vue'
import type { ContentTranslationExportFormat } from '~/types/content-translations'
import type { ExportDialogLabels } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
  filters?: Record<string, unknown>
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useExportContentTranslationsMutation } = useContent(props.spaceId)
const exportMutation = useExportContentTranslationsMutation()

const labels = computed<ExportDialogLabels>(() => ({
  title: t('labels.contents.translationExport.title'),
  description: t('labels.contents.translationExport.description'),
  formatLabel: t('labels.contents.translationExport.formatLabel'),
  submit: t('labels.contents.translationExport.submit'),
  fallbackError: t('labels.contents.translationExport.exportError'),
}))

const formats: { value: ContentTranslationExportFormat; label: string }[] = [
  { value: 'xliff', label: 'XLIFF' },
  { value: 'csv', label: 'CSV' },
  { value: 'excel', label: 'Excel' },
  { value: 'json', label: 'JSON' },
  { value: 'yaml', label: 'YAML' },
]

const submit = (format: ContentTranslationExportFormat): Promise<Blob> =>
  exportMutation.mutateAsync({ ...props.filters, as: format })
</script>

<template>
  <ExportDialog
    v-model:open="open"
    :labels="labels"
    :formats="formats"
    :submit="submit"
    filename-prefix="content-translations"
  />
</template>
