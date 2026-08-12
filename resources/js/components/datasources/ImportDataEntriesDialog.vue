<script setup lang="ts">
import ImportDialog from '~/components/import-export/ImportDialog.vue'
import type { DataEntryImportMode, DataEntryImportResult } from '~/types/data-sources'
import type { ImportDialogLabels, ImportDialogMode } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
  dataSourceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportDataEntriesMutation } = useDataEntries(props.spaceId, props.dataSourceId)

const importMutation = useImportDataEntriesMutation()

const labels = computed<ImportDialogLabels>(() => ({
  title: t('labels.dataEntries.importDialog.title'),
  description: t('labels.dataEntries.importDialog.description'),
  formats: t('labels.dataEntries.importDialog.supportedFormats'),
  selectFileError: t('labels.dataEntries.importDialog.selectFileError'),
  fallbackError: t('composables.dataEntries.importError', { error: '' }),
  submit: t('labels.assets.import'),
  modeLabel: t('labels.dataEntries.importDialog.importMode.label'),
  summaryTitle: t('labels.dataEntries.importDialog.summaryTitle'),
  summaryDescription: t('labels.dataEntries.importDialog.summaryDescription'),
  summarySuccess: t('labels.dataEntries.importDialog.summary.success'),
  summaryChanges: t('labels.dataEntries.importDialog.summary.changes'),
  summaryDeleted: t('labels.dataEntries.importDialog.summary.deleted'),
  summaryErrors: t('labels.dataEntries.importDialog.summary.errors'),
  changesTitle: t('labels.dataEntries.importDialog.modifiedEntries'),
  deletedTitle: t('labels.dataEntries.importDialog.deletedEntries'),
  ignoredFieldsTitle: t('labels.dataEntries.importDialog.ignoredFields'),
  errorsTitle: t('labels.dataEntries.importDialog.errorsTitle'),
}))

const modes = computed<ImportDialogMode<DataEntryImportMode>[]>(() => [
  {
    value: 'addition',
    icon: 'lucide:plus-circle',
    label: t('labels.dataEntries.importDialog.importMode.addition'),
    description: t('labels.dataEntries.importDialog.importMode.additionDescription'),
  },
  {
    value: 'replacement',
    icon: 'lucide:replace',
    label: t('labels.dataEntries.importDialog.importMode.replacement'),
    description: t('labels.dataEntries.importDialog.importMode.replacementDescription'),
    warning: t('labels.dataEntries.importDialog.importMode.replacementWarning'),
  },
])

const emptyValue = computed(() => t('labels.dataEntries.importDialog.emptyValue'))

const submit = (file: File, mode: DataEntryImportMode): Promise<DataEntryImportResult> =>
  importMutation.mutateAsync({ file, mode })
</script>

<template>
  <ImportDialog
    v-model:open="open"
    accept=".json,.csv,.xlsx,.xls,.yaml,.yml"
    :labels="labels"
    :modes="modes"
    :submit="submit"
    :pending="importMutation.isPending.value"
    :item-key="(entry) => entry.id"
    :change-count="
      (entry) => t('labels.dataEntries.importDialog.changeCount', { count: entry.changes.length })
    "
    :deleted-label="(entry) => entry.key"
    :error-title="
      (error, index) =>
        error.id || t('labels.dataEntries.importDialog.rowLabel', { row: error.row ?? index + 1 })
    "
  >
    <template #label="{ item }">{{ item.key }}</template>
    <template #details="{ item }">
      <div class="space-y-2 text-sm">
        <div
          v-for="(change, index) in item.changes"
          :key="`${item.id}-${index}`"
          class="rounded-md bg-muted/50 p-2"
        >
          <div class="font-medium text-foreground">{{ change.field }}</div>
          <div class="text-muted-foreground">
            {{ change.old ?? emptyValue }}
            ->
            {{ change.new ?? emptyValue }}
          </div>
        </div>
      </div>
    </template>
  </ImportDialog>
</template>
