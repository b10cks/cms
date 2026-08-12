<script setup lang="ts">
import ImportDialog from '~/components/import-export/ImportDialog.vue'
import type { ImportDialogLabels, ImportDialogMode } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportRedirectsMutation } = useRedirects(props.spaceId)

const importMutation = useImportRedirectsMutation()

const labels = computed<ImportDialogLabels>(() => ({
  title: t('labels.redirects.importDialog.title'),
  description: t('labels.redirects.importDialog.description'),
  formats: t('labels.redirects.importDialog.supportedFormats'),
  selectFileError: t('labels.redirects.importDialog.selectFileError'),
  fallbackError: t('composables.redirects.importError', { error: '' }),
  submit: t('labels.assets.import'),
  modeLabel: t('labels.redirects.importDialog.importMode.label'),
  summaryTitle: t('labels.redirects.importDialog.summaryTitle'),
  summaryDescription: t('labels.redirects.importDialog.summaryDescription'),
  summarySuccess: t('labels.redirects.importDialog.summary.success'),
  summaryChanges: t('labels.redirects.importDialog.summary.changes'),
  summaryDeleted: t('labels.redirects.importDialog.summary.deleted'),
  summaryErrors: t('labels.redirects.importDialog.summary.errors'),
  changesTitle: t('labels.redirects.importDialog.modifiedRedirects'),
  deletedTitle: t('labels.redirects.importDialog.deletedRedirects'),
  ignoredFieldsTitle: t('labels.redirects.importDialog.ignoredFields'),
  errorsTitle: t('labels.redirects.importDialog.errorsTitle'),
}))

const modes = computed<ImportDialogMode<RedirectImportMode>[]>(() => [
  {
    value: 'addition',
    icon: 'lucide:plus-circle',
    label: t('labels.redirects.importDialog.importMode.addition'),
    description: t('labels.redirects.importDialog.importMode.additionDescription'),
  },
  {
    value: 'replacement',
    icon: 'lucide:replace',
    label: t('labels.redirects.importDialog.importMode.replacement'),
    description: t('labels.redirects.importDialog.importMode.replacementDescription'),
    warning: t('labels.redirects.importDialog.importMode.replacementWarning'),
  },
])

const emptyValue = computed(() => t('labels.redirects.importDialog.emptyValue'))

const submit = (file: File, mode: RedirectImportMode): Promise<RedirectDataImportResult> =>
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
    :item-key="(redirect) => redirect.id"
    :change-count="
      (redirect) =>
        t('labels.redirects.importDialog.changeCount', { count: redirect.changes.length })
    "
    :deleted-label="(redirect) => redirect.source"
    :error-title="
      (error, index) =>
        error.id || t('labels.redirects.importDialog.rowLabel', { row: error.row ?? index + 1 })
    "
  >
    <template #label="{ item }">{{ item.source }}</template>
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
