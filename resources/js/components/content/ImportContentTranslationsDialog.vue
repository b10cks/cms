<script setup lang="ts">
import ImportDialog from '~/components/import-export/ImportDialog.vue'
import { Switch } from '~/components/ui/switch'
import type {
  ContentTranslationImportMode,
  ContentTranslationImportResult,
} from '~/types/content-translations'
import type { ImportDialogLabels, ImportDialogMode } from '~/types/import-export'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportContentTranslationsMutation } = useContent(props.spaceId)
const importMutation = useImportContentTranslationsMutation()

const createMissing = ref(false)

const labels = computed<ImportDialogLabels>(() => ({
  title: t('labels.contents.translationImport.title'),
  description: t('labels.contents.translationImport.description'),
  formats: t('labels.contents.translationImport.supportedFormats'),
  selectFileError: t('labels.contents.translationImport.selectFileError'),
  fallbackError: t('labels.contents.translationImport.importError'),
  submit: t('labels.contents.translationImport.submit'),
  modeLabel: t('labels.contents.translationImport.mode.label'),
  summaryTitle: t('labels.contents.translationImport.summaryTitle'),
  summaryDescription: t('labels.contents.translationImport.summaryDescription'),
  summarySuccess: t('labels.contents.translationImport.summary.success'),
  summaryChanges: t('labels.contents.translationImport.summary.changes'),
  summaryErrors: t('labels.contents.translationImport.summary.errors'),
  changesTitle: t('labels.contents.translationImport.modifiedContent'),
  ignoredFieldsTitle: t('labels.contents.translationImport.ignoredFields'),
  errorsTitle: t('labels.contents.translationImport.errorsTitle'),
}))

const modes = computed<ImportDialogMode<ContentTranslationImportMode>[]>(() => [
  {
    value: 'draft',
    icon: 'lucide:file-pen-line',
    label: t('labels.contents.translationImport.mode.draft'),
    description: t('labels.contents.translationImport.mode.draftDescription'),
  },
  {
    value: 'publish',
    icon: 'lucide:globe',
    label: t('labels.contents.translationImport.mode.publish'),
    description: t('labels.contents.translationImport.mode.publishDescription'),
  },
])

const submit = (
  file: File,
  mode: ContentTranslationImportMode
): Promise<ContentTranslationImportResult> =>
  importMutation.mutateAsync({ file, mode, createMissing: createMissing.value })
</script>

<template>
  <ImportDialog
    v-model:open="open"
    accept=".xlf,.xliff,.xml,.csv,.xlsx,.xls,.json,.yaml,.yml"
    :labels="labels"
    :modes="modes"
    :submit="submit"
    :pending="importMutation.isPending.value"
    :item-key="(entry) => `${entry.content_id}:${entry.language}`"
    :change-count="
      (entry) =>
        t('labels.contents.translationImport.changeCount', { count: entry.changes.length })
    "
    :error-title="(error) => error.id || error.message"
    :error-body="(error) => (error.id ? error.message : '')"
    @reset="createMissing = false"
  >
    <template #options>
      <label class="flex items-start gap-3 rounded-lg border p-3">
        <Switch v-model="createMissing" />
        <span class="space-y-1">
          <span class="block text-sm font-medium text-foreground">
            {{ t('labels.contents.translationImport.createMissing.label') }}
          </span>
          <span class="block text-xs text-muted-foreground">
            {{ t('labels.contents.translationImport.createMissing.description') }}
          </span>
        </span>
      </label>
    </template>

    <template #label="{ item }">
      {{ item.name || item.content_id }}
      <span class="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs uppercase text-muted-foreground">
        {{ item.language }}
      </span>
    </template>

    <template #details="{ item }">
      <div class="flex flex-wrap gap-2">
        <span
          v-for="(change, index) in item.changes"
          :key="`${item.content_id}-${item.language}-${index}`"
          class="rounded-md bg-muted/50 px-2 py-1 text-xs text-foreground"
        >
          {{ change.label }}
        </span>
      </div>
    </template>
  </ImportDialog>
</template>
