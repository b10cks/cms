<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { useRouteQuery } from '@vueuse/router'
import { computed, nextTick, ref } from 'vue'
import { toast } from 'vue-sonner'

import DataEntriesIcon from '~/assets/images/data-entries.svg?component'
import ExportDataEntriesDialog from '~/components/datasources/ExportDataEntriesDialog.vue'
import ShapeValueFields from '~/components/datasources/ShapeValueFields.vue'
import ImportDataEntriesDialog from '~/components/datasources/ImportDataEntriesDialog.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Input } from '~/components/ui/input'
import { Progress } from '~/components/ui/progress'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Spinner } from '~/components/ui/spinner'
import { Switch } from '~/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableSortableHead,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { Textarea } from '~/components/ui/textarea'
import { useDataEntries } from '~/composables/useDataEntries'
import { useDataEntryTranslation } from '~/composables/useDataEntryTranslation'
import { useDataSources } from '~/composables/useDataSources'
import { queryKeys } from '~/composables/useQueryClient'
import type {
  CreateDataEntryPayload,
  DataEntryResource,
  DataEntryValue,
  UpdateDataEntryPayload,
} from '~/types/data-sources'

const route = useRoute()
const { alert } = useAlertDialog()
const { $t, t } = useI18n()
const { showAiError } = useAiErrorToast()
const queryClient = useQueryClient()
const spaceId = computed(() => route.params.space as string)
const dataSourceId = computed(() => route.params.dataSourceId as string)

const { useDataSourceQuery } = useDataSources(spaceId)
const { data: dataSource, isLoading: isLoadingDataSource } = useDataSourceQuery(dataSourceId)

useSeoMeta({
  title: computed(() => dataSource.value?.name || $t('labels.datasets.title')),
})

const showExportDialog = ref(false)
const showImportDialog = ref(false)

const {
  useDataEntriesQuery,
  useCreateDataEntryMutation,
  useUpdateDataEntryMutation,
  useDeleteDataEntryMutation,
} = useDataEntries(spaceId, dataSourceId)

const { settings } = useSpaceSettings(spaceId.value)

const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(25)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const filters = ref<Record<string, unknown>>({})

const queryParams = computed(() => ({
  ...filters.value,
  page: currentPage.value,
  per_page: perPage.value,
  q: searchQuery.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
}))

const possibleFilters = computed(() => {
  const result = [
    { id: 'key', label: 'Key' },
    { id: 'value', label: 'Value' },
  ]

  if (selectedDimension.value !== 'default') {
    const dimensionLabel =
      dimensionTabs.value.find((tab) => tab.key === selectedDimension.value)?.label ||
      selectedDimension.value

    result.push({
      // PHP converts `dimension.en` → `dimension_en` in query strings, so use underscore
      id: `dimension_${selectedDimension.value}`,
      label: `${dimensionLabel} — ${$t('labels.dataEntries.fields.missingTranslation')}`,
      operators: [
        { value: 'empty', label: $t('labels.common.is') },
        { value: '!empty', label: $t('labels.common.isNot') },
      ],
    })
  }

  return result
})

const { data: dataEntriesResponse, isLoading: isLoadingEntries } = useDataEntriesQuery(queryParams)

const { mutateAsync: createEntry, isPending: isCreatingEntry } = useCreateDataEntryMutation()
const { mutateAsync: updateEntry, isPending: isUpdatingEntry } = useUpdateDataEntryMutation()
const { mutateAsync: deleteEntry } = useDeleteDataEntryMutation()
const {
  streamMissingDimensionsTranslation,
  cancelStream,
  isStreaming: isTranslatingDimensions,
} = useDataEntryTranslation(spaceId, dataSourceId)

const selectedDimension = useRouteQuery('dimension', 'default', {
  transform: (value: string) => value || 'default',
})

const editingEntries = ref<Map<string, DataEntryResource>>(new Map())
const pendingChanges = ref<Set<string>>(new Set())

const pageSizeOptions = [25, 50, 100, 500, 1000]
const sortOptions = [
  { value: 'key', label: $t('labels.dataEntries.fields.key') },
  { value: 'value', label: $t('labels.dataEntries.fields.value') },
  { value: 'created_at', label: $t('labels.dataEntries.fields.createdAt') },
  { value: 'updated_at', label: $t('labels.dataEntries.fields.updatedAt') },
]

const shape = computed(() => dataSource.value?.shape ?? [])
const isShaped = computed(() => shape.value.length > 0)

const formatValue = (value: DataEntryValue | undefined) => {
  if (value && typeof value === 'object') {
    return Object.entries(value)
      .map(([key, fieldValue]) => `${key}: ${Array.isArray(fieldValue) ? fieldValue.join(', ') : fieldValue}`)
      .join(' · ')
  }
  return value || '-'
}

const dimensionTabs = computed(() => {
  const tabs = [{ key: 'default', label: $t('labels.datasets.dimensions.default') }]

  if (dataSource.value?.dimensions?.length > 0) {
    tabs.push(
      ...dataSource.value.dimensions.map((dim) => ({
        key: dim.key,
        label: dim.label,
      }))
    )
  }

  return tabs
})

const showDimensionTabs = computed(() => dataSource.value?.dimensions?.length > 0)

const defaultDimensionLocale = computed(() => {
  return dataSource.value?.settings?.default_dimension_locale || 'default'
})

const canTranslateCurrentDimension = computed(() => {
  return Boolean(
    !isShaped.value &&
    dataSource.value?.settings?.dimensions_translatable &&
    selectedDimension.value !== 'default' &&
    selectedDimension.value !== defaultDimensionLocale.value
  )
})

const newEntryData = ref<CreateDataEntryPayload & { dimensions: Record<string, DataEntryValue> }>({
  key: '',
  value: '',
  dimensions: {},
  is_active: true,
})

const handleDimensionChange = (dimension: string) => {
  selectedDimension.value = dimension
  clearEditingState()
  // Clear dimension filters that no longer apply to the newly selected dimension
  const newFilters: Record<string, unknown> = {}
  for (const [key, val] of Object.entries(filters.value)) {
    if (!key.startsWith('dimension_')) {
      newFilters[key] = val
    }
  }
  filters.value = newFilters
}

const handleEditModeChange = (mode: 'grid' | 'single') => {
  settings.value.dataEntries.mode = mode
  clearEditingState()
}

const clearEditingState = () => {
  editingEntries.value.clear()
  pendingChanges.value.clear()
}

const emptyValue = (): DataEntryValue => (isShaped.value ? {} : '')

const handleSaveNewEntry = async () => {
  try {
    const payload = {
      ...newEntryData.value,
      dimensions:
        selectedDimension.value === 'default'
          ? {}
          : {
              [selectedDimension.value]:
                newEntryData.value.dimensions[selectedDimension.value] || emptyValue(),
            },
    }

    await createEntry(payload)

    newEntryData.value = {
      key: '',
      value: emptyValue(),
      dimensions: {},
      is_active: true,
    }
  } catch (error) {
    /* empty */
  }
}

const handleEditEntry = (entry: DataEntryResource) => {
  if (settings.value.dataEntries.mode === 'single') {
    editingEntries.value.set(entry.id, { ...entry })
    nextTick(() => {
      const firstInput = document.querySelector(
        `[data-entry-id="${entry.id}"] input`
      ) as HTMLInputElement
      firstInput?.focus()
    })
  }
}

const handleSaveEntry = async (entryId: string) => {
  const editedEntry = editingEntries.value.get(entryId)
  if (!editedEntry) return

  try {
    if (entryId.startsWith('new-')) {
      const payload = {
        key: editedEntry.key,
        value: editedEntry.value,
        dimensions: editedEntry.dimensions,
        is_active: editedEntry.is_active,
      }
      await createEntry(payload)
    } else {
      const payload: UpdateDataEntryPayload = {
        key: editedEntry.key,
        value: editedEntry.value,
        dimensions: editedEntry.dimensions,
        is_active: editedEntry.is_active,
      }
      await updateEntry({ id: entryId, payload })
    }

    editingEntries.value.delete(entryId)
    pendingChanges.value.delete(entryId)
  } catch (error) {
    /* empty */
  }
}

const handleDiscardEntry = (entryId: string) => {
  editingEntries.value.delete(entryId)
  pendingChanges.value.delete(entryId)
}

const handleDeleteEntry = async (entry: DataEntryResource) => {
  const confirmed = await alert.confirm(
    $t('messages.dataEntries.deleteConfirmation', { name: entry.name }),
    {
      title: $t('labels.dataEntries.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      cancelLabel: $t('actions.cancel'),
    }
  )

  if (confirmed) {
    await deleteEntry(entry.id)
  }
}

const handleInputChange = (entry: DataEntryResource, field: string, value: any) => {
  if (!editingEntries.value.has(entry.id)) {
    editingEntries.value.set(entry.id, { ...entry })
  }
  const editedEntry = editingEntries.value.get(entry.id)

  if (field.startsWith('dimension.')) {
    const dimensionKey = field.replace('dimension.', '')
    editedEntry.dimensions = { ...editedEntry.dimensions, [dimensionKey]: value }
  } else {
    ;(editedEntry as any)[field] = value
  }

  pendingChanges.value.add(entry.id)

  if (settings.value.dataEntries.autoSave && settings.value.dataEntries.mode === 'grid') {
    clearTimeout((editedEntry as any)._autoSaveTimeout)
    ;(editedEntry as any)._autoSaveTimeout = setTimeout(() => {
      handleSaveEntry(entry.id)
    }, 1000)
  }
}

const handleKeyDown = (event: KeyboardEvent, entryId: string, field: string) => {
  const target = event.target as HTMLInputElement
  const currentRow = target.closest('tr')
  const currentCell = target.closest('td')

  // The value / dimension cells are multiline textareas: plain arrow keys and
  // Enter must behave natively (move the caret / insert a newline). Only the
  // single-line key input keeps the grid-style row navigation and Enter-to-save.
  const isMultiline = field === 'value' || field.startsWith('dimension.')

  switch (event.key) {
    case 'ArrowUp': {
      if (isMultiline) break
      event.preventDefault()
      const prevRow = currentRow?.previousElementSibling as HTMLTableRowElement
      if (prevRow) {
        const cellIndex = Array.from(currentRow.children).indexOf(currentCell!)
        const prevInput = prevRow.children[cellIndex]?.querySelector('input') as HTMLInputElement
        prevInput?.focus()
      }
      break
    }
    case 'ArrowDown': {
      if (isMultiline) break
      event.preventDefault()
      const nextRow = currentRow?.nextElementSibling as HTMLTableRowElement
      if (nextRow) {
        const cellIndex = Array.from(currentRow.children).indexOf(currentCell!)
        const nextInput = nextRow.children[cellIndex]?.querySelector('input') as HTMLInputElement
        nextInput?.focus()
      }
      break
    }
    case 'Tab': {
      break
    }
    case 'Escape': {
      event.preventDefault()
      handleDiscardEntry(entryId)

      editingEntries.value.delete(entryId)
      break
    }
    case 'Enter': {
      // In a multiline field a plain Enter inserts a newline; saving requires
      // the Cmd/Ctrl modifier so the value can genuinely span multiple lines.
      if (isMultiline && !event.metaKey && !event.ctrlKey) break

      event.preventDefault()

      handleSaveEntry(entryId)

      const nextRow = currentRow?.nextElementSibling as HTMLTableRowElement
      if (nextRow) {
        const cellIndex = Array.from(currentRow.children).indexOf(currentCell!)
        const nextInput = nextRow.children[cellIndex]?.querySelector('input') as HTMLInputElement
        nextInput?.focus()
      }
      break
    }
  }
}

const getDimensionValue = (entry: DataEntryResource, dimensionKey: string): string => {
  const value = entry.dimensions?.[dimensionKey]
  return typeof value === 'string' ? value : ''
}

const isEntryEditing = (entryId: string) => {
  return settings.value.dataEntries.mode === 'grid' || editingEntries.value.has(entryId)
}

const hasEntryPendingChanges = (entryId: string) => {
  return pendingChanges.value.has(entryId)
}

const isDefaultSelected = computed(() => selectedDimension.value === 'default')

const translationProgress = ref<{
  processed: number
  translated: number
  skipped: number
  total: number
  stage: string
} | null>(null)

const translationProgressPercent = computed(() => {
  if (!translationProgress.value?.total) return 0
  return Math.round((translationProgress.value.processed / translationProgress.value.total) * 100)
})

const currentDimensionLabel = computed(() => {
  return (
    dimensionTabs.value.find((tab) => tab.key === selectedDimension.value)?.label ||
    selectedDimension.value
  )
})

const parseStatusPayload = (message: string) => {
  try {
    return JSON.parse(message) as {
      stage: string
      processed: number
      translated: number
      skipped: number
      total: number
      target_dimension: string
    }
  } catch {
    return null
  }
}

const handleTranslateMissingDimensions = async () => {
  if (!dataSource.value || !canTranslateCurrentDimension.value) {
    return
  }

  const loadingToast = toast.loading(
    t('labels.dataEntries.translation.inProgress', {
      dimension: currentDimensionLabel.value,
    }) as string,
    { duration: Number.POSITIVE_INFINITY }
  )

  translationProgress.value = {
    processed: 0,
    translated: 0,
    skipped: 0,
    total: 0,
    stage: 'preparing',
  }

  await streamMissingDimensionsTranslation(selectedDimension.value, {
    onStatus: (message) => {
      const payload = parseStatusPayload(message)
      if (!payload) return

      translationProgress.value = {
        processed: payload.processed,
        translated: payload.translated,
        skipped: payload.skipped,
        total: payload.total,
        stage: payload.stage,
      }

      toast.loading(
        t('labels.dataEntries.translation.progress', {
          processed: payload.processed,
          total: payload.total,
          translated: payload.translated,
          dimension: currentDimensionLabel.value,
        }) as string,
        {
          id: loadingToast,
          duration: Number.POSITIVE_INFINITY,
        }
      )
    },
    onDone: async (_, data) => {
      toast.dismiss(loadingToast)

      await queryClient.invalidateQueries({
        queryKey: queryKeys.dataEntries(spaceId, dataSourceId).lists(),
      })

      const result = data as {
        translated_count: number
        skipped_count: number
        processed_count: number
        total_candidates: number
        target_dimension: string
      }

      translationProgress.value = result
        ? {
            processed: result.processed_count,
            translated: result.translated_count,
            skipped: result.skipped_count,
            total: result.total_candidates,
            stage: 'done',
          }
        : null

      if (result?.translated_count > 0) {
        toast.success(
          t('composables.dataEntries.translationSuccess', {
            translated: result.translated_count,
            total: result.total_candidates,
            dimension: currentDimensionLabel.value,
          }) as string
        )
      } else {
        toast.info(
          t('composables.dataEntries.translationNoop', {
            dimension: currentDimensionLabel.value,
          }) as string
        )
      }
    },
    onError: (message, reason) => {
      toast.dismiss(loadingToast)
      translationProgress.value = null
      showAiError(reason, message)
    },
  })
}
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="dataSource?.name || $t('labels.datasets.dataEntries')"
        :description="dataSource?.description"
      >
        <template #start>
          <RouterLink
            :to="{ name: 'space-datasources', params: { space: spaceId } }"
            class="flex cursor-pointer items-center gap-2"
          >
            <Icon name="lucide:chevron-left" />
            {{ $t('labels.datasets.backToDataSources') }}
          </RouterLink>
        </template>
        <template #actions>
          <div class="flex items-center gap-4">
            <div
              v-if="settings.dataEntries.mode === 'grid'"
              class="flex items-center gap-2"
            >
              <Switch
                id="autosave"
                v-model="settings.dataEntries.autoSave"
              />
              <label
                for="autosave"
                class="text-sm text-muted"
              >
                {{ $t('labels.datasets.autoSave') }}
              </label>
            </div>
            <Tabs
              :model-value="settings.dataEntries.mode"
              @update:model-value="handleEditModeChange"
            >
              <TabsList class="h-8">
                <TabsTrigger
                  value="single"
                  class="px-2 py-1 text-xs"
                >
                  {{ $t('labels.datasets.singleEdit') }}
                </TabsTrigger>
                <TabsTrigger
                  value="grid"
                  class="px-2 py-1 text-xs"
                >
                  {{ $t('labels.datasets.gridEdit') }}
                </TabsTrigger>
              </TabsList>
            </Tabs>
          </div>
        </template>
      </ContentHeader>

      <div
        v-if="isLoadingDataSource"
        class="flex items-center justify-center gap-2 py-12"
      >
        <Spinner />
        {{ $t('labels.datasets.loading') }}
      </div>

      <div
        v-else-if="dataSource"
        class="space-y-6"
      >
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <Tabs
              v-if="showDimensionTabs"
              :model-value="selectedDimension"
              @update:model-value="handleDimensionChange"
            >
              <TabsList>
                <TabsTrigger
                  v-for="tab in dimensionTabs"
                  :key="tab.key"
                  :value="tab.key"
                >
                  {{ tab.label }}
                </TabsTrigger>
              </TabsList>
            </Tabs>
            <div class="ml-auto flex items-center gap-2">
              <Button
                v-if="canTranslateCurrentDimension && !isTranslatingDimensions"
                variant="outline"
                size="sm"
                @click="handleTranslateMissingDimensions"
              >
                <Icon
                  name="lucide:languages"
                  class="h-4 w-4"
                />
                {{ $t('labels.dataEntries.translation.action') }}
              </Button>
              <Button
                v-else-if="isTranslatingDimensions"
                variant="outline"
                size="sm"
                @click="cancelStream"
              >
                <Icon
                  name="lucide:square"
                  class="h-4 w-4"
                />
                {{ $t('actions.cancel') }}
              </Button>
              <SearchFilter
                v-model="filters"
                :filterable-fields="possibleFilters"
                class="lg:min-w-xs 2xl:min-w-md"
                @search="searchQuery = $event"
                @reset="searchQuery = ''"
              />
              <SortSelect
                v-model="sortBy"
                :options="sortOptions"
                :label="$t('labels.sortBy')"
                :placeholder="$t('labels.sortBy')"
              />
            </div>
          </div>

          <div
            v-if="translationProgress"
            class="rounded-md border border-input bg-elevated p-4"
          >
            <div class="mb-2 flex items-center justify-between gap-4">
              <div>
                <div class="text-sm font-medium">
                  {{
                    $t('labels.dataEntries.translation.progressTitle', {
                      dimension: currentDimensionLabel,
                    })
                  }}
                </div>
                <div class="text-xs text-muted">
                  {{
                    $t('labels.dataEntries.translation.progress', {
                      processed: translationProgress.processed,
                      total: translationProgress.total,
                      translated: translationProgress.translated,
                      dimension: currentDimensionLabel,
                    })
                  }}
                </div>
              </div>
              <div class="text-xs font-medium text-muted">{{ translationProgressPercent }}%</div>
            </div>
            <Progress :model-value="translationProgressPercent" />
          </div>

          <div class="overflow-hidden rounded-md border border-input">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableSortableHead
                    v-model="sortBy"
                    :class="isDefaultSelected ? 'w-1/2' : 'w-1/4'"
                    column="key"
                    >{{ $t('labels.dataEntries.fields.key') }}
                  </TableSortableHead>
                  <TableSortableHead
                    v-model="sortBy"
                    :class="isDefaultSelected ? 'w-1/2' : 'w-1/3'"
                    column="value"
                    >{{ $t('labels.dataEntries.fields.value') }}
                  </TableSortableHead>
                  <TableHead
                    v-if="!isDefaultSelected"
                    class="w-1/2"
                  >
                    {{ dimensionTabs.find((tab) => tab.key === selectedDimension)?.label }}
                  </TableHead>
                  <TableHead class="w-24">{{ $t('labels.dataEntries.fields.actions') }}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow
                  v-if="isDefaultSelected"
                  class="bg-elevated"
                >
                  <TableCell>
                    <Input
                      v-model="newEntryData.key"
                      :placeholder="$t('labels.dataEntries.fields.key')"
                      pattern="^[a-zA-Z0-9._\-]+$"
                      required
                      @keydown.enter="handleSaveNewEntry"
                    />
                  </TableCell>
                  <TableCell>
                    <ShapeValueFields
                      v-if="isShaped"
                      v-model="newEntryData.value"
                      :shape="shape"
                      :disabled="!isDefaultSelected"
                    />
                    <Textarea
                      v-else
                      :model-value="(newEntryData.value as string | null) ?? ''"
                      :placeholder="$t('labels.dataEntries.fields.value')"
                      :disabled="!isDefaultSelected"
                      @update:model-value="(v) => (newEntryData.value = String(v))"
                      @keydown.enter.meta.prevent="handleSaveNewEntry"
                      @keydown.enter.ctrl.prevent="handleSaveNewEntry"
                    />
                  </TableCell>
                  <TableCell v-if="!isDefaultSelected">
                    <ShapeValueFields
                      v-if="isShaped"
                      v-model="newEntryData.dimensions[selectedDimension]"
                      :shape="shape"
                    />
                    <Textarea
                      v-else
                      :model-value="(newEntryData.dimensions[selectedDimension] as string | null) ?? ''"
                      :placeholder="
                        dimensionTabs.find((tab) => tab.key === selectedDimension)?.label
                      "
                      @update:model-value="(v) => (newEntryData.dimensions[selectedDimension] = String(v))"
                      @keydown.enter.meta.prevent="handleSaveNewEntry"
                      @keydown.enter.ctrl.prevent="handleSaveNewEntry"
                    />
                  </TableCell>
                  <TableCell>
                    <div class="flex gap-1">
                      <Button
                        size="icon"
                        variant="outline"
                        class="h-8 w-8"
                        :disabled="!newEntryData.key || isCreatingEntry"
                        :aria-label="$t('actions.create')"
                        @click="handleSaveNewEntry"
                      >
                        <Spinner
                          v-if="isCreatingEntry"
                          class="size-3"
                        />
                        <Icon
                          v-else
                          name="lucide:plus"
                          class="h-3 w-3"
                        />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
                <TableRow v-if="isLoadingEntries">
                  <TableCell
                    :colspan="4"
                    class="h-24 text-center"
                  >
                    <div class="flex items-center justify-center">
                      <Spinner class="mr-2 size-6" />
                      {{ $t('labels.datasets.loadingEntries') }}
                    </div>
                  </TableCell>
                </TableRow>
                <template v-else-if="dataEntriesResponse?.data.length">
                  <TableRow
                    v-for="entry in dataEntriesResponse?.data"
                    :key="entry.id"
                    :data-entry-id="entry.id"
                    @dblclick="handleEditEntry(entry)"
                  >
                    <TableCell>
                      <Input
                        v-if="isEntryEditing(entry.id)"
                        :model-value="entry.key"
                        :disabled="!isDefaultSelected"
                        @update:model-value="(value) => handleInputChange(entry, 'key', value)"
                        @keydown="(e) => handleKeyDown(e, entry.id, 'key')"
                      />
                      <span
                        v-else
                        class="font-medium"
                        >{{ entry.key }}</span
                      >
                    </TableCell>

                    <TableCell>
                      <template v-if="isEntryEditing(entry.id)">
                        <ShapeValueFields
                          v-if="isShaped"
                          :shape="shape"
                          :model-value="editingEntries.get(entry.id)?.value ?? entry.value"
                          :disabled="!isDefaultSelected"
                          @update:model-value="(value) => handleInputChange(entry, 'value', value)"
                        />
                        <Textarea
                          v-else
                          :model-value="(entry.value as string | null) ?? ''"
                          :disabled="!isDefaultSelected"
                          @update:model-value="(value) => handleInputChange(entry, 'value', value)"
                          @keydown="(e) => handleKeyDown(e, entry.id, 'value')"
                        />
                      </template>
                      <span
                        v-else
                        class="block max-w-[200px] truncate"
                        >{{ formatValue(entry.value) }}</span
                      >
                    </TableCell>

                    <TableCell v-if="!isDefaultSelected">
                      <template v-if="isEntryEditing(entry.id)">
                        <ShapeValueFields
                          v-if="isShaped"
                          :shape="shape"
                          :model-value="
                            editingEntries.get(entry.id)?.dimensions?.[selectedDimension] ??
                            entry.dimensions?.[selectedDimension]
                          "
                          @update:model-value="
                            (value) =>
                              handleInputChange(entry, `dimension.${selectedDimension}`, value)
                          "
                        />
                        <Textarea
                          v-else
                          :model-value="getDimensionValue(entry, selectedDimension)"
                          @update:model-value="
                            (value) =>
                              handleInputChange(entry, `dimension.${selectedDimension}`, value)
                          "
                          @keydown="
                            (e) => handleKeyDown(e, entry.id, `dimension.${selectedDimension}`)
                          "
                        />
                      </template>
                      <span v-else>{{
                        formatValue(entry.dimensions?.[selectedDimension])
                      }}</span>
                    </TableCell>

                    <TableCell>
                      <div class="flex gap-1">
                        <template v-if="isEntryEditing(entry.id)">
                          <Button
                            size="icon"
                            variant="ghost"
                            :disabled="isUpdatingEntry"
                            @click="handleSaveEntry(entry.id)"
                          >
                            <Spinner v-if="isUpdatingEntry" />
                            <Icon
                              v-else
                              name="lucide:check"
                              class="text-green-500"
                            />
                            <span class="sr-only">Save</span>
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            @click="handleDiscardEntry(entry.id)"
                          >
                            <Icon
                              name="lucide:x"
                              class="text-red-500"
                            />
                            <span class="sr-only">Cancel</span>
                          </Button>
                        </template>
                        <template v-else>
                          <Button
                            v-if="settings.dataEntries.mode === 'single'"
                            size="icon"
                            variant="ghost"
                            :aria-label="$t('labels.dataEntries.actions.edit')"
                            @click="handleEditEntry(entry)"
                          >
                            <Icon name="lucide:pencil" />
                          </Button>
                          <Button
                            v-if="
                              settings.dataEntries.mode === 'grid' &&
                              !settings.dataEntries.autoSave &&
                              hasEntryPendingChanges(entry.id)
                            "
                            size="icon"
                            variant="outline"
                            class="h-8 w-8"
                            :aria-label="$t('actions.save')"
                            @click="handleSaveEntry(entry.id)"
                          >
                            <Icon
                              name="lucide:check"
                              class="text-green-500"
                            />
                          </Button>
                          <Button
                            v-if="isDefaultSelected"
                            size="icon"
                            variant="destructive"
                            class="h-8 w-8"
                            :aria-label="$t('labels.dataEntries.actions.delete')"
                            @click="handleDeleteEntry(entry)"
                          >
                            <Icon
                              name="lucide:trash-2"
                              class="h-3 w-3"
                            />
                          </Button>
                        </template>
                      </div>
                    </TableCell>
                  </TableRow>
                </template>
                <TableEmptyRow
                  v-else
                  :colspan="4"
                  :icon="DataEntriesIcon"
                  :label="$t('labels.datasets.noEntries')"
                />
              </TableBody>
            </Table>
          </div>

          <TablePaginationFooter
            v-if="dataEntriesResponse?.meta"
            :meta="dataEntriesResponse.meta"
            :current-page="currentPage"
            :per-page="perPage"
            :page-size-options="pageSizeOptions"
            @update:current-page="(val) => (currentPage = val)"
            @update:per-page="(val) => (perPage = val)"
          />
        </div>
      </div>
    </div>
  </div>

  <ExportDataEntriesDialog
    v-model:open="showExportDialog"
    :space-id="spaceId"
    :data-source-id="dataSourceId"
  />

  <ImportDataEntriesDialog
    v-model:open="showImportDialog"
    :space-id="spaceId"
    :data-source-id="dataSourceId"
  />

  <Teleport
    defer
    to="#appHeaderActions"
  >
    <div class="flex gap-2">
      <Button @click="showImportDialog = true">
        <Icon name="lucide:upload" />
        {{ $t('labels.assets.import') }}
      </Button>
      <Button @click="showExportDialog = true">
        <Icon name="lucide:download" />
        {{ $t('labels.assets.export') }}
      </Button>
    </div>
  </Teleport>
</template>
