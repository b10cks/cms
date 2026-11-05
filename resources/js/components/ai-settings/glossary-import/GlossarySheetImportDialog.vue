<script setup lang="ts">
import { useMutation, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AiGlossaryExportFormat, AiGlossarySheetImportResult, AiOwner } from '~/api/resources/ai-knowledge'
import ImportDialog from '~/components/import-export/ImportDialog.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { downloadBlob } from '~/lib/import-export'
import type { ImportDialogLabels, ImportDialogSubmit, ImportError, ImportProgress } from '~/types/import-export'

const TEMPLATE_FORMATS: AiGlossaryExportFormat[] = ['csv', 'xlsx']

/** The list stops here. Copy and download always hold every row. */
const MAX_LISTED_ERRORS = 200

const CELL_FORMULA = /^[=+\-@\t\r]/

/** A spreadsheet must not run a row's text as a formula. */
const csvCell = (cell: string | number) => {
  const text = String(cell)
  const safe = CELL_FORMULA.test(text) ? `'${text}` : text
  return /[",\n\r]/.test(safe) ? `"${safe.replaceAll('"', '""')}"` : safe
}

const rowErrorsCsv = (errors: readonly ImportError[]) =>
  [
    'row,column,message',
    ...errors.map(({ row, column, message }) => [row ?? '', column ?? '', message].map(csvCell).join(',')),
  ].join('\n')

/**
 * Uploads a glossary sheet through the shared import dialog with live progress, and
 * says what changed and which rows to fix. The format is in docs/guides/glossary-sheet.md.
 */
const props = defineProps<{
  owner: AiOwner
  glossaryId: string
}>()

const emit = defineEmits<{
  imported: [result: AiGlossarySheetImportResult]
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const queryClient = useQueryClient()

const importMutation = useMutation({
  mutationFn: ({ file, onProgress }: { file: File; onProgress: (progress: ImportProgress) => void }) =>
    api.aiKnowledge(props.owner).importTerms(props.glossaryId, file, onProgress),
  onSuccess: (result) => {
    // Everything of a glossary sits under this key: the list, concept rows and terms.
    void queryClient.invalidateQueries({ queryKey: queryKeys.aiKnowledge(props.owner).glossaries() })
    emit('imported', result)
  },
})

const result = ref<AiGlossarySheetImportResult | null>(null)

const submit: ImportDialogSubmit<AiGlossarySheetImportResult> = async (file, _mode, onProgress) => {
  result.value = await importMutation.mutateAsync({ file, onProgress })
  return result.value
}

const labels = computed<ImportDialogLabels>(() => ({
  title: t('glossaryImport.sheet.title'),
  description: t('glossaryImport.sheet.description'),
  formats: t('glossaryImport.sheet.formats'),
  selectFileError: t('glossaryImport.sheet.selectFile'),
  fallbackError: t('glossaryImport.sheet.failed'),
  submit: t('glossaryImport.sheet.submit'),
  summaryTitle: t('glossaryImport.sheet.summaryTitle'),
  summaryDescription: t('glossaryImport.sheet.summaryDescription'),
  summarySuccess: t('glossaryImport.sheet.imported'),
  summaryChanges: t('glossaryImport.sheet.updated'),
  summaryErrors: t('glossaryImport.sheet.errors'),
  changesTitle: t('glossaryImport.sheet.updated'),
  errorsTitle: t('glossaryImport.sheet.errorsTitle'),
}))

const templateUrl = (format: AiGlossaryExportFormat) =>
  api.aiKnowledge(props.owner).exportUrl(props.glossaryId, format, { template: true })

const figures = computed(() => {
  if (!result.value) return []

  const { added, updated, unchanged, total_success: imported, total_errors: errors } = result.value.summary
  const counts =
    added === undefined
      ? [{ key: 'imported', value: imported }]
      : [
          { key: 'added', value: added },
          { key: 'updated', value: updated ?? 0 },
          { key: 'unchanged', value: unchanged ?? 0 },
        ]

  return [...counts, { key: 'errors', value: errors }]
})

const outcome = computed(() => {
  const summary = result.value?.summary

  if (!summary) return null
  if (summary.total_errors > 0 && summary.total_success === 0) return 'allFailed'

  return summary.added !== undefined && summary.total_errors === 0 && summary.total_changes === 0 ? 'nothingChanged' : null
})

const copied = ref(false)

const copyErrors = async (errors: readonly ImportError[]) => {
  await navigator.clipboard.writeText(rowErrorsCsv(errors))
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

const downloadErrors = (errors: readonly ImportError[]) =>
  downloadBlob(new Blob([`﻿${rowErrorsCsv(errors)}`], { type: 'text/csv' }), 'glossary-import-errors.csv')
</script>

<template>
  <ImportDialog
    v-model:open="open"
    accept=".csv,.xlsx,.tbx,.xml"
    :labels="labels"
    :submit="submit"
    :pending="importMutation.isPending.value"
    :item-key="() => ''"
    :change-count="() => ''"
  >
    <template #options>
      <p class="flex flex-wrap items-center gap-x-3 text-sm text-muted">
        {{ $t('glossaryImport.sheet.template') }}
        <Button
          v-for="format in TEMPLATE_FORMATS"
          :key="format"
          as-child
          variant="link"
          size="sm"
          class="underline underline-offset-2"
        >
          <a
            :href="templateUrl(format)"
            download
          >
            {{ format.toUpperCase() }}
          </a>
        </Button>
      </p>
    </template>

    <template #summary>
      <div
        v-if="result"
        class="grid gap-4 p-1"
      >
        <Alert
          v-if="outcome === 'allFailed'"
          color="destructive"
          icon="lucide:circle-alert"
        >
          <span class="text-sm">{{ $t('glossaryImport.sheet.allFailed') }}</span>
        </Alert>
        <p
          v-else-if="outcome === 'nothingChanged'"
          class="text-sm text-muted"
        >
          {{ $t('glossaryImport.sheet.nothingChanged') }}
        </p>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div
            v-for="figure in figures"
            :key="figure.key"
            class="rounded-lg border border-border p-3"
          >
            <dt class="text-sm text-muted">{{ $t(`glossaryImport.sheet.${figure.key}`) }}</dt>
            <dd
              class="text-figure font-semibold tabular-nums"
              :class="figure.key === 'errors' && figure.value > 0 ? 'text-destructive' : 'text-primary'"
            >
              {{ figure.value }}
            </dd>
          </div>
        </dl>

        <Alert
          v-for="warning in result.warnings ?? []"
          :key="warning.code"
          icon="lucide:info"
        >
          <span class="text-sm">{{ $t('glossaryImport.sheet.unusedLocales', { locales: warning.locales.join(', ') }) }}</span>
        </Alert>
        <Alert
          v-if="result.ignored_fields?.length"
          icon="lucide:info"
        >
          <span class="text-sm">{{ $t('glossaryImport.sheet.unknownColumns', { columns: result.ignored_fields.join(', ') }) }}</span>
        </Alert>

        <section
          v-if="result.errors.length"
          class="grid gap-2"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-medium text-primary">{{ $t('glossaryImport.sheet.errorsTitle') }}</h3>
            <div class="flex flex-wrap gap-2">
              <Button
                variant="outline"
                size="sm"
                @click="copyErrors(result.errors)"
              >
                {{ copied ? $t('glossaryImport.sheet.copied') : $t('glossaryImport.sheet.copy') }}
              </Button>
              <Button
                variant="outline"
                size="sm"
                @click="downloadErrors(result.errors)"
              >
                {{ $t('glossaryImport.sheet.download') }}
              </Button>
            </div>
          </div>
          <ul class="divide-y divide-border rounded-lg border border-border">
            <li
              v-for="(error, index) in result.errors.slice(0, MAX_LISTED_ERRORS)"
              :key="index"
              class="grid gap-0.5 p-2.5 text-sm"
            >
              <span class="font-medium text-primary tabular-nums">
                {{ error.row !== undefined ? $t('glossaryImport.sheet.rowLabel', { row: error.row }) : '' }}<template v-if="error.column">, {{ error.column }}</template>
              </span>
              <span class="break-words text-muted">{{ error.message }}</span>
            </li>
          </ul>
          <p
            v-if="result.errors.length > MAX_LISTED_ERRORS"
            class="text-sm text-muted"
          >
            {{ $t('glossaryImport.sheet.moreErrors', { count: result.errors.length - MAX_LISTED_ERRORS }) }}
          </p>
        </section>
      </div>
    </template>
  </ImportDialog>
</template>
