<script setup lang="ts">
import ImportDialog from '~/components/import-export/ImportDialog.vue'
import type { ImportDialogLabels } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportAssetsMutation } = useAssets(props.spaceId)

const importMutation = useImportAssetsMutation()

const labels = computed<ImportDialogLabels>(() => ({
  title: t('labels.assets.importDialog.title'),
  description: t('labels.assets.importDialog.description'),
  formats: t('labels.assets.importDialog.supportedFormats'),
  selectFileError: t('labels.assets.importDialog.selectFileError'),
  fallbackError: t('labels.assets.importDialog.importError'),
  submit: t('labels.assets.import'),
  summaryTitle: t('labels.assets.importDialog.summaryTitle'),
  summaryDescription: t('labels.assets.importDialog.summaryDescription'),
  summarySuccess: t('labels.assets.importDialog.summary.success'),
  summaryChanges: t('labels.assets.importDialog.summary.changes'),
  summaryErrors: t('labels.assets.importDialog.summary.errors'),
  changesTitle: t('labels.assets.importDialog.modifiedAssets'),
  ignoredFieldsTitle: t('labels.assets.importDialog.ignoredFields'),
  errorsTitle: t('labels.assets.importDialog.errorsTitle'),
}))

const emptyValue = computed(() => t('labels.assets.importDialog.emptyValue'))

const submit = (file: File): Promise<AssetDataImportResult> => importMutation.mutateAsync(file)
</script>

<template>
  <ImportDialog
    v-model:open="open"
    accept=".json,.csv,.xlsx,.xls,.xlf,.xliff,.yaml,.yml"
    content-class=""
    :labels="labels"
    :submit="submit"
    :pending="importMutation.isPending.value"
    :item-key="(asset) => asset.id"
    :change-count="
      (asset) => t('labels.assets.importDialog.changeCount', { count: asset.changes.length })
    "
    :error-title="
      (error, index) =>
        error.id || t('labels.assets.importDialog.rowLabel', { row: error.row ?? index + 1 })
    "
  >
    <template #label="{ item }">{{ item.filename }}</template>
    <template #details="{ item }">
      <div class="space-y-2 text-sm">
        <div
          v-for="(change, index) in item.changes"
          :key="`${item.id}-${index}`"
          class="rounded-md bg-muted/50 p-2"
        >
          <div class="font-medium text-foreground">
            {{ change.field }}
            <span
              v-if="change.language"
              class="font-normal text-muted-foreground"
              >({{ change.language }})</span
            >
          </div>
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
