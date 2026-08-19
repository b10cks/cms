<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import ExportContentTranslationsDialog from '~/components/content/ExportContentTranslationsDialog.vue'
import ImportContentTranslationsDialog from '~/components/content/ImportContentTranslationsDialog.vue'
import type { FilterableField } from '~/components/SearchFilter.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import SplitButton from '~/components/ui/button/SplitButton.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import type { ComboboxOption } from '~/components/ui/form/ComboboxField.vue'
import { ComboboxField } from '~/components/ui/form'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { Textarea } from '~/components/ui/textarea'
import { useMassEdit } from '~/composables/useMassEdit'
import { stripAiCodeFences } from '~/lib/aiJson'
import type {
  MassEditDocument,
  MassEditRowsParams,
  MassEditSaveResult,
  MassEditUnit,
} from '~/types/mass-edit'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManageContent = computed(() => access.hasAbility('content.manage'))

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)

useSeoMeta({
  title: computed(() => t('labels.massEdit.title')),
})

const sourceLanguage = computed<string>(() => space.value?.settings?.default_language || 'en')
const targetLanguages = computed<string[]>(
  () => space.value?.settings?.languages?.map((language: { code: string }) => language.code) || []
)

// Selection state
const selectedFieldKeys = ref<string[]>([])
const selectedLanguages = ref<string[]>([])
const filters = ref<Record<string, unknown>>({})
const currentPage = ref(1)
const perPage = ref(25)
const pageSizeOptions = [25, 50, 100]

// Preselect every language once. Re-running this would undo a deliberate "none selected".
let languagesInitialized = false
watch(
  targetLanguages,
  (languages) => {
    if (!languagesInitialized && languages.length > 0) {
      languagesInitialized = true
      selectedLanguages.value = [...languages]
    }
  },
  { immediate: true }
)

watch([selectedFieldKeys, selectedLanguages, filters], () => {
  currentPage.value = 1
})

// Data
const {
  useMassEditFieldsQuery,
  useMassEditRowsQuery,
  useMassEditSaveMutation,
  fetchAllRows,
  saveProgress,
} = useMassEdit(spaceId)

const { data: availableFields, isLoading: isLoadingFields } = useMassEditFieldsQuery()

const rowsParams = computed<MassEditRowsParams>(() => ({
  ...filters.value,
  fields: selectedFieldKeys.value.join(','),
  languages: selectedLanguages.value.join(','),
  page: currentPage.value,
  per_page: perPage.value,
}))

const hasSelection = computed(() => selectedFieldKeys.value.length > 0)

const { data: rowsResponse, isFetching } = useMassEditRowsQuery(rowsParams, hasSelection)

const documents = computed<MassEditDocument[]>(() => rowsResponse.value?.data || [])

const fieldOptions = computed<ComboboxOption<string>[]>(() =>
  (availableFields.value || []).map((field) => ({
    value: field.key,
    label: field.label,
    key: field.key,
    translatable: field.translatable,
  }))
)

const fieldOptionFilter = (option: ComboboxOption<string>, search: string): boolean => {
  const needle = search.toLowerCase()
  return (
    option.label.toLowerCase().includes(needle) ||
    String(option.key).toLowerCase().includes(needle)
  )
}

const blockItems = computed(() => {
  const blocks = new Map<string, string>()
  for (const field of availableFields.value || []) {
    if (selectedFieldKeys.value.length && !selectedFieldKeys.value.includes(field.key)) continue
    for (const block of field.blocks) {
      blocks.set(block.id, block.name)
    }
  }
  return [...blocks.entries()].map(([value, label]) => ({ value, label }))
})

const textOperators = computed(() => [
  { value: 'like' as const, label: t('labels.massEdit.operators.contains') },
  { value: '^like' as const, label: t('labels.massEdit.operators.startsWith') },
  { value: 'like$' as const, label: t('labels.massEdit.operators.endsWith') },
  { value: 'eq' as const, label: t('labels.massEdit.operators.equals') },
])

const dateOperators = computed(() => [
  { value: 'gte' as const, label: t('labels.massEdit.operators.after') },
  { value: 'lte' as const, label: t('labels.massEdit.operators.before') },
])

const filterableFields = computed<FilterableField[]>(() => [
  {
    id: 'name',
    label: t('labels.massEdit.filters.name'),
    operators: textOperators.value,
  },
  {
    id: 'slug',
    label: t('labels.massEdit.filters.slug'),
    operators: textOperators.value,
  },
  {
    id: 'full_slug',
    label: t('labels.massEdit.filters.fullSlug'),
    operators: textOperators.value,
  },
  {
    id: 'block_id',
    label: t('labels.massEdit.filters.block'),
    operators: [{ value: 'eq' as const, label: t('labels.massEdit.operators.equals') }],
    items: blockItems.value,
  },
  {
    id: 'published',
    label: t('labels.massEdit.filters.status'),
    operators: [{ value: 'eq' as const, label: t('labels.massEdit.operators.equals') }],
    items: [
      { value: '1', label: t('labels.massEdit.filters.published') },
      { value: '0', label: t('labels.massEdit.filters.draft') },
    ],
  },
  {
    id: 'created_at',
    label: t('labels.massEdit.filters.createdAt'),
    operators: dateOperators.value,
    datepicker: {},
  },
  {
    id: 'updated_at',
    label: t('labels.massEdit.filters.updatedAt'),
    operators: dateOperators.value,
    datepicker: {},
  },
  ...selectedFieldKeys.value.map((key) => {
    const field = availableFields.value?.find((candidate) => candidate.key === key)
    return {
      id: `field_${key}`,
      label: t('labels.massEdit.filters.fieldValue', { field: field?.label ?? key }),
      operators: [
        ...textOperators.value,
        { value: 'empty' as const, label: t('labels.massEdit.operators.empty') },
        { value: '!empty' as const, label: t('labels.massEdit.operators.notEmpty') },
      ],
    }
  }),
])

const gridLanguages = computed(() => [
  sourceLanguage.value,
  ...selectedLanguages.value.filter((language) => language !== sourceLanguage.value),
])

interface GridRow {
  document: MassEditDocument
  unit: MassEditUnit
  firstOfDocument: boolean
}

const gridRows = computed<GridRow[]>(() =>
  documents.value.flatMap((document) =>
    document.units.map((unit, index) => ({
      document,
      unit,
      firstOfDocument: index === 0,
    }))
  )
)

// Delta edit tracking: only cells that differ from the loaded value are kept.
const edits = ref(new Map<string, string>())

const cellKey = (contentId: string, language: string, unitId: string) =>
  `${contentId}:${language}:${unitId}`

const originalValue = (row: GridRow, language: string): string =>
  language === row.document.source_language
    ? row.unit.source
    : (row.unit.targets[language] ?? '')

const cellValue = (row: GridRow, language: string): string => {
  const key = cellKey(row.document.content_id, language, row.unit.id)
  return edits.value.has(key) ? (edits.value.get(key) as string) : originalValue(row, language)
}

const isCellDirty = (row: GridRow, language: string): boolean =>
  edits.value.has(cellKey(row.document.content_id, language, row.unit.id))

const handleCellInput = (row: GridRow, language: string, value: string) => {
  const key = cellKey(row.document.content_id, language, row.unit.id)
  if (value === originalValue(row, language)) {
    edits.value.delete(key)
  } else {
    edits.value.set(key, value)
  }
}

const dirtyCount = computed(() => edits.value.size)

const discardEdits = () => {
  edits.value.clear()
}

// Edits survive paging on purpose — a save covers every page that was touched. Dropping
// a field is different: its cells disappear for good while still being saved, so make
// that explicit and offer a way back out.
const { alert } = useAlertDialog()
let revertingFieldChange = false

watch(selectedFieldKeys, async (next, previous) => {
  if (revertingFieldChange) {
    revertingFieldChange = false
    return
  }

  const removed = previous.filter((key) => !next.includes(key))
  if (removed.length === 0 || edits.value.size === 0) return

  const confirmed = await alert.confirm(
    t('labels.massEdit.fieldRemovalWarning', { count: edits.value.size }) as string,
    {
      title: t('labels.massEdit.fieldRemovalTitle') as string,
      confirmLabel: t('labels.massEdit.fieldRemovalConfirm') as string,
    }
  )

  if (!confirmed) {
    revertingFieldChange = true
    selectedFieldKeys.value = [...previous]
  }
})

// Keyboard: move between rows within the same language column; Escape reverts a cell.
const handleCellKeydown = (event: KeyboardEvent, row: GridRow, language: string) => {
  if (event.key === 'Escape') {
    edits.value.delete(cellKey(row.document.content_id, language, row.unit.id))
    return
  }

  if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return

  const target = event.target as HTMLTextAreaElement
  const atStart = target.selectionStart === 0
  const atEnd = target.selectionStart === target.value.length
  if ((event.key === 'ArrowUp' && !atStart) || (event.key === 'ArrowDown' && !atEnd)) return

  const cell = target.closest('td')
  const tableRow = target.closest('tr')
  if (!cell || !tableRow) return

  const sibling =
    event.key === 'ArrowUp'
      ? (tableRow.previousElementSibling as HTMLTableRowElement | null)
      : (tableRow.nextElementSibling as HTMLTableRowElement | null)
  const nextInput = sibling?.cells[cell.cellIndex]?.querySelector('textarea')
  if (nextInput) {
    event.preventDefault()
    nextInput.focus()
  }
}

// Save
const saveMutation = useMassEditSaveMutation()

const buildDocumentsPayload = () => {
  const byContent = new Map<string, Record<string, Record<string, string>>>()

  for (const [key, value] of edits.value.entries()) {
    const [contentId, language, ...unitParts] = key.split(':')
    const unitId = unitParts.join(':')
    const targets = byContent.get(contentId) ?? {}
    targets[language] = { ...targets[language], [unitId]: value }
    byContent.set(contentId, targets)
  }

  return [...byContent.entries()].map(([content_id, targets]) => ({ content_id, targets }))
}

/**
 * Drop the edits that landed and keep the ones that did not, so a partial failure
 * stays visible and fixable instead of silently vanishing from the grid.
 */
const clearSavedEdits = (result: MassEditSaveResult) => {
  const failedContents = new Set<string>()
  const failedCells = new Set<string>()

  for (const error of result.errors) {
    const contentId = error.content_id ?? error.id
    if (!contentId) continue

    if (error.language) {
      failedCells.add(`${contentId}:${error.language}`)
    } else {
      failedContents.add(contentId)
    }
  }

  const unsaved = new Map<string, string>()
  for (const [key, value] of edits.value) {
    const [contentId, language] = key.split(':')
    if (failedContents.has(contentId) || failedCells.has(`${contentId}:${language}`)) {
      unsaved.set(key, value)
    }
  }

  edits.value = unsaved
}

const handleSave = async (mode: 'draft' | 'publish' = 'draft') => {
  if (dirtyCount.value === 0) return

  try {
    const result = await saveMutation.mutateAsync({
      documents: buildDocumentsPayload(),
      mode,
      create_missing: true,
    })
    clearSavedEdits(result)
  } catch {
    // The mutation already reported it; edits stay so the save can be retried.
  }
}

// AI translation of changed/missing cells with streaming
const { useAiConfigsQuery } = useAiConfigs(spaceId)
const { data: aiConfigs } = useAiConfigsQuery()
const defaultAiConfig = computed(() => aiConfigs.value?.find((config) => config.is_default) ?? null)
const { streamTranslation, isStreaming: isTranslating } = useAiTranslation(spaceId)
const translationProgress = ref<{ language: string; applied: number; total: number } | null>(null)

const AI_KEY_SEPARATOR = '|'

function extractCompletedTranslations(partial: string): Record<string, string> {
  const result: Record<string, string> = {}
  const regex = /"((?:[^"\\]|\\.)+)"\s*:\s*"((?:[^"\\]|\\.)*)"/g
  let match
  while ((match = regex.exec(partial)) !== null) {
    const key = match[1].replace(/\\"/g, '"').replace(/\\\\/g, '\\')
    result[key] = match[2]
      .replace(/\\"/g, '"')
      .replace(/\\n/g, '\n')
      .replace(/\\t/g, '\t')
      .replace(/\\\\/g, '\\')
  }
  return result
}

/** Cells sent per streaming request — one request for thousands of cells never lands. */
const AI_BATCH_SIZE = 40

/**
 * Cells worth translating for a language: the source has text and the target is
 * either still empty or its source was edited in this session (stale translation).
 */
const translatableCells = (language: string, rows: GridRow[]) => {
  const cells: Array<{ row: GridRow; source: string }> = []
  for (const row of rows) {
    if (!row.unit.translatable) continue

    const source = cellValue(row, row.document.source_language)
    if (!source.trim()) continue

    const sourceEdited = isCellDirty(row, row.document.source_language)
    const targetValue = cellValue(row, language)
    if (targetValue.trim() && !sourceEdited) continue

    cells.push({ row, source })
  }
  return cells
}

const toGridRows = (docs: MassEditDocument[]): GridRow[] =>
  docs.flatMap((document) =>
    document.units.map((unit, index) => ({ document, unit, firstOfDocument: index === 0 }))
  )

const isPreparingTranslation = ref(false)

const translateWithAI = async (configId: string | null = defaultAiConfig.value?.id ?? null) => {
  if (!configId) {
    toast.error(t('labels.massEdit.noAiConfig'))
    return
  }

  const languages = selectedLanguages.value.filter(
    (language) => language !== sourceLanguage.value
  )

  let translatedTotal = 0

  try {
    // Translate the whole selection, not just the visible page — edits are kept across
    // pages, so the save afterwards covers everything this fills in.
    isPreparingTranslation.value = true

    let allRows: GridRow[]
    try {
      const { documents: allDocuments, truncated } = await fetchAllRows(rowsParams.value)
      if (truncated) {
        toast.warning(t('labels.massEdit.translateTruncated'))
      }
      allRows = toGridRows(allDocuments)
    } catch (error) {
      toast.error(
        t('labels.massEdit.translateError', {
          error: error instanceof Error ? error.message : String(error),
        })
      )
      return
    } finally {
      isPreparingTranslation.value = false
    }

    for (const language of languages) {
      const cells = translatableCells(language, allRows)
      if (cells.length === 0) continue

      const applied = new Set<string>()
      translationProgress.value = { language, applied: 0, total: cells.length }

      for (let offset = 0; offset < cells.length; offset += AI_BATCH_SIZE) {
        const batch = cells.slice(offset, offset + AI_BATCH_SIZE)

        const fields: Record<string, string> = {}
        const cellsByKey = new Map<string, GridRow>()
        for (const { row, source } of batch) {
          const key = `${row.document.content_id}${AI_KEY_SEPARATOR}${row.unit.id}`
          fields[key] = source
          cellsByKey.set(key, row)
        }

        let accumulated = ''

        const applyEntries = (entries: Record<string, string>) => {
          for (const [key, value] of Object.entries(entries)) {
            if (applied.has(key) || !value) continue
            const row = cellsByKey.get(key)
            if (!row) continue
            applied.add(key)
            handleCellInput(row, language, value)
            translationProgress.value = { language, applied: applied.size, total: cells.length }
          }
        }

        await streamTranslation(
          {
            source: sourceLanguage.value,
            target: language,
            fields,
            config_id: configId,
          },
          {
            onDelta: (chunk) => {
              accumulated += chunk
              applyEntries(extractCompletedTranslations(accumulated))
            },
            onDone: (content) => {
              try {
                const parsed = JSON.parse(stripAiCodeFences(content || accumulated))
                if (parsed && typeof parsed === 'object') {
                  applyEntries(parsed as Record<string, string>)
                }
              } catch {
                // partial regex extraction already applied everything it could
              }
            },
            onError: (error) => {
              toast.error(t('labels.massEdit.translateError', { error }))
            },
          }
        )
      }

      translatedTotal += applied.size
    }
  } finally {
    translationProgress.value = null
  }

  if (translatedTotal > 0) {
    toast.success(t('labels.massEdit.translateSuccess', translatedTotal))
  } else {
    toast.info(t('labels.massEdit.nothingToTranslate'))
  }
}

const toggleLanguage = (code: string) => {
  selectedLanguages.value = selectedLanguages.value.includes(code)
    ? selectedLanguages.value.filter((language) => language !== code)
    : [...selectedLanguages.value, code]
}

// Export / import
const showExportDialog = ref(false)
const showImportDialog = ref(false)

const exportFilters = computed(() => ({
  grid: 1,
  ...filters.value,
  ...(selectedFieldKeys.value.length ? { fields: selectedFieldKeys.value.join(',') } : {}),
  ...(selectedLanguages.value.length ? { languages: selectedLanguages.value.join(',') } : {}),
}))
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.massEdit.title')"
        :description="$t('labels.massEdit.description')"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <span
              v-if="translationProgress"
              class="ai-animate-text text-xs text-muted"
            >
              {{ $t('labels.massEdit.translating', translationProgress) }}
            </span>
            <SplitButton
              v-if="canManageContent"
              variant="outline"
              :title="$t('labels.massEdit.translateAiHint')"
              :primary-action="() => translateWithAI()"
              :disabled="
                !hasSelection || isTranslating || isPreparingTranslation || !defaultAiConfig
              "
              :has-menu="(aiConfigs?.length ?? 0) > 1"
              :loading="isTranslating || isPreparingTranslation"
            >
              <Icon
                v-if="!isTranslating && !isPreparingTranslation"
                name="lucide:sparkles"
                class="text-ai"
              />
              {{ $t('labels.massEdit.translateAi') }}
              <template #menu>
                <DropdownMenuItem
                  v-for="config in aiConfigs"
                  :key="config.id"
                  :disabled="isTranslating || isPreparingTranslation"
                  @select="translateWithAI(config.id)"
                >
                  <div class="flex items-center gap-2">
                    <span class="font-medium">{{ config.name }}</span>
                    <Badge
                      v-if="config.is_default"
                      size="sm"
                      >Default</Badge
                    >
                  </div>
                </DropdownMenuItem>
              </template>
            </SplitButton>

            <Button
              v-if="dirtyCount > 0"
              variant="ghost"
              @click="discardEdits"
            >
              {{ $t('labels.massEdit.discard') }}
            </Button>
            <SplitButton
              v-if="canManageContent"
              variant="primary"
              :primary-action="() => handleSave('draft')"
              :disabled="dirtyCount === 0"
              :has-menu="true"
              :loading="saveMutation.isPending.value"
            >
              <Icon name="lucide:save" />
              {{
                saveProgress
                  ? $t('labels.massEdit.saving', saveProgress)
                  : $t('labels.massEdit.save', dirtyCount)
              }}
              <template #menu>
                <DropdownMenuItem @select="handleSave('publish')">
                  <Icon name="lucide:rocket" />
                  {{ $t('labels.massEdit.saveAndPublish') }}
                </DropdownMenuItem>
              </template>
            </SplitButton>
          </div>
        </template>
      </ContentHeader>

      <div class="space-y-2 pb-8">
        <div class="flex flex-wrap items-center gap-2">
          <ComboboxField
            v-model="selectedFieldKeys"
            name="fields"
            class="min-w-72"
            :options="fieldOptions"
            :filter-fn="fieldOptionFilter"
            placeholder="labels.massEdit.fieldsPlaceholder"
            :loading="isLoadingFields"
            multiple
            searchable
            empty-text="labels.massEdit.noFieldsAvailable"
          >
            <template #option="{ option }">
              <span class="flex w-full items-center gap-2">
                <span class="font-medium">{{ option.label }}</span>
                <span class="font-mono text-2xs text-muted">{{ option.key }}</span>
                <Badge
                  v-if="!option.translatable"
                  size="sm"
                  variant="secondary"
                  class="ml-auto"
                  >{{ $t('labels.massEdit.sourceOnly') }}</Badge
                >
              </span>
            </template>
          </ComboboxField>

          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button variant="outline">
                <Icon name="lucide:languages" />
                {{ $t('labels.massEdit.languages') }}
                <Badge
                  v-if="selectedLanguages.length"
                  size="sm"
                  >{{ selectedLanguages.length }}</Badge
                >
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              <DropdownMenuCheckboxItem
                v-for="code in targetLanguages"
                :key="code"
                :model-value="selectedLanguages.includes(code)"
                @select.prevent="toggleLanguage(code)"
              >
                {{ code }}
              </DropdownMenuCheckboxItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <SearchFilter
            v-model="filters"
            :filterable-fields="filterableFields"
            class="ml-auto lg:min-w-xs 2xl:min-w-md"
          />
        </div>

        <div
          v-if="!hasSelection"
          class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border p-16 text-center"
        >
          <Icon
            name="lucide:table-properties"
            class="size-8 text-muted"
          />
          <p class="font-medium">{{ $t('labels.massEdit.emptyTitle') }}</p>
          <p class="max-w-md text-sm text-muted">{{ $t('labels.massEdit.emptyDescription') }}</p>
        </div>

        <template v-else>
          <div
            class="overflow-x-auto rounded-lg border border-border"
            :class="{ 'opacity-60': isFetching }"
          >
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead class="w-64">{{ $t('labels.massEdit.contentColumn') }}</TableHead>
                  <TableHead class="w-48">{{ $t('labels.massEdit.fieldColumn') }}</TableHead>
                  <TableHead
                    v-for="language in gridLanguages"
                    :key="language"
                    class="min-w-64"
                  >
                    <span class="uppercase">{{ language }}</span>
                    <Badge
                      v-if="language === sourceLanguage"
                      size="sm"
                      class="ml-2"
                      >{{ $t('labels.massEdit.sourceBadge') }}</Badge
                    >
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow
                  v-for="row in gridRows"
                  :key="`${row.document.content_id}-${row.unit.id}`"
                >
                  <TableCell class="align-top">
                    <template v-if="row.firstOfDocument">
                      <div class="font-medium">{{ row.document.name }}</div>
                      <div class="font-mono text-2xs text-muted">{{ row.document.full_slug }}</div>
                    </template>
                  </TableCell>
                  <TableCell class="align-top">
                    <div class="text-sm">{{ row.unit.label }}</div>
                    <div class="font-mono text-2xs text-muted">{{ row.unit.id }}</div>
                  </TableCell>
                  <TableCell
                    v-for="language in gridLanguages"
                    :key="language"
                    class="align-top"
                  >
                    <div
                      v-if="language !== row.document.source_language && !row.unit.translatable"
                      class="flex min-h-9 items-center gap-1.5 px-2 text-2xs text-muted italic"
                      :title="$t('labels.massEdit.notTranslatableHint')"
                    >
                      <Icon
                        name="lucide:lock"
                        class="size-3"
                      />
                      {{ $t('labels.massEdit.sourceOnly') }}
                    </div>
                    <Textarea
                      v-else
                      :model-value="cellValue(row, language)"
                      rows="1"
                      class="min-h-9 w-full resize-y text-sm"
                      :class="{ 'ring-1 ring-amber-500': isCellDirty(row, language) }"
                      :disabled="isTranslating || isPreparingTranslation || !canManageContent"
                      @update:model-value="
                        (value: string | number) => handleCellInput(row, language, String(value))
                      "
                      @keydown="(event: KeyboardEvent) => handleCellKeydown(event, row, language)"
                    />
                  </TableCell>
                </TableRow>
                <TableEmptyRow
                  v-if="gridRows.length === 0 && !isFetching"
                  :colspan="2 + gridLanguages.length"
                  :label="$t('labels.massEdit.noRows')"
                />
              </TableBody>
            </Table>
          </div>

          <TablePaginationFooter
            v-if="rowsResponse?.meta"
            :meta="rowsResponse.meta"
            :current-page="currentPage"
            :per-page="perPage"
            :page-size-options="pageSizeOptions"
            @update:current-page="(value) => (currentPage = value)"
            @update:per-page="(value) => (perPage = value)"
          />
        </template>
      </div>
    </div>
  </div>

  <Teleport
    defer
    to="#appHeaderActions"
  >
    <div class="flex gap-2">
      <Button
        v-if="canManageContent"
        @click="showImportDialog = true"
      >
        <Icon name="lucide:upload" />
        {{ $t('labels.massEdit.import') }}
      </Button>
      <Button
        :disabled="!hasSelection"
        @click="showExportDialog = true"
      >
        <Icon name="lucide:download" />
        {{ $t('labels.massEdit.export') }}
      </Button>
    </div>
  </Teleport>

  <ExportContentTranslationsDialog
    v-model:open="showExportDialog"
    :space-id="spaceId"
    :filters="exportFilters"
  />

  <ImportContentTranslationsDialog
    v-model:open="showImportDialog"
    :space-id="spaceId"
    grid
  />
</template>
