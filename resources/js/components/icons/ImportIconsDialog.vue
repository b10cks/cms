<script setup lang="ts">
import ImportDialog from '~/components/import-export/ImportDialog.vue'
import type { ImportDialogLabels, ImportDialogMode } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportIconsMutation } = useIcons(props.spaceId)

const importMutation = useImportIconsMutation()

// No `ignoredFieldsTitle`: the icon importer reports ignored fields but the
// dialog has never surfaced them.
const labels = computed<ImportDialogLabels>(() => ({
  title: t('labels.icons.importDialog.title'),
  description: t('labels.icons.importDialog.description'),
  formats: t('labels.icons.importDialog.supportedFormats'),
  selectFileError: t('labels.icons.importDialog.selectFileError'),
  fallbackError: t('composables.icons.importError', { error: '' }),
  submit: t('labels.assets.import'),
  modeLabel: t('labels.icons.importDialog.importMode.label'),
  summaryTitle: t('labels.icons.importDialog.summaryTitle'),
  summaryDescription: t('labels.icons.importDialog.summaryDescription'),
  summarySuccess: t('labels.icons.importDialog.summary.success'),
  summaryChanges: t('labels.icons.importDialog.summary.changes'),
  summaryDeleted: t('labels.icons.importDialog.summary.deleted'),
  summaryErrors: t('labels.icons.importDialog.summary.errors'),
  changesTitle: t('labels.icons.importDialog.modifiedIcons'),
  deletedTitle: t('labels.icons.importDialog.deletedIcons'),
  errorsTitle: t('labels.icons.importDialog.errorsTitle'),
}))

const modes = computed<ImportDialogMode<IconImportMode>[]>(() => [
  {
    value: 'addition',
    icon: 'lucide:plus-circle',
    label: t('labels.icons.importDialog.importMode.addition'),
    description: t('labels.icons.importDialog.importMode.additionDescription'),
  },
  {
    value: 'replacement',
    icon: 'lucide:replace',
    label: t('labels.icons.importDialog.importMode.replacement'),
    description: t('labels.icons.importDialog.importMode.replacementDescription'),
    warning: t('labels.icons.importDialog.importMode.replacementWarning'),
  },
])

const submit = (file: File, mode: IconImportMode): Promise<IconDataImportResult> =>
  importMutation.mutateAsync({ file, mode })
</script>

<template>
  <ImportDialog
    v-model:open="open"
    accept=".json"
    :labels="labels"
    :modes="modes"
    :submit="submit"
    :pending="importMutation.isPending.value"
    :item-key="(icon) => icon.id"
    :change-count="
      (icon) => t('labels.icons.importDialog.changeCount', { count: icon.changes.length })
    "
    :deleted-label="(icon) => icon.key"
    deleted-variant="tags"
    :error-title="(error, index) => error.id || String(index + 1)"
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
        </div>
      </div>
    </template>
  </ImportDialog>
</template>
