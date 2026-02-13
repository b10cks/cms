<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useRouteQuery } from '@vueuse/router'
import { computed, nextTick, ref } from 'vue'
import DataEntriesIcon from '~/assets/images/data-entries.svg?component'
import SearchFilter from '~/components/SearchFilter.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Input } from '~/components/ui/input'
import SortSelect from '~/components/ui/SortSelect.vue'
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
import { useDataSources } from '~/composables/useDataSources'
import type {
  CreateDataEntryPayload,
  DataEntryResource,
  UpdateDataEntryPayload,
} from '~/types/data-sources'

const route = useRoute()
const { alert } = useAlertDialog()
const { $t, t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const dataSourceId = computed(() => route.params.dataSourceId as string)

const { useDataSourceQuery } = useDataSources(spaceId)
const { data: dataSource, isLoading: isLoadingDataSource } = useDataSourceQuery(dataSourceId)

useSeoMeta({
  title: computed(() => dataSource.value?.name || $t('labels.datasets.title')),
})

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
    {
      id: 'active',
      label: 'Active',
      items: [
        { value: 'true', label: $t('labels.common.yes') },
        { value: 'false', label: $t('labels.common.no') },
      ],
    },
    { id: 'value', label: 'Value' },
  ]

  // if (selectedDimension.value !== 'default') {
  //   result.push({
  //     id: `dimension.${selectedDimension.value}`,
  //     label: 'translated',
  //     operators: [
  //       { value: 'empty', label: $t('labels.common.is') },
  //       { value: '!empty', label: $t('labels.common.isNot') },
  //     ]
  //   })
  // }

  return result
})

const { data: dataEntriesResponse, isLoading: isLoadingEntries } = useDataEntriesQuery(queryParams)

const { mutate: createEntry, isPending: isCreatingEntry } = useCreateDataEntryMutation()
const { mutate: updateEntry, isPending: isUpdatingEntry } = useUpdateDataEntryMutation()
const { mutate: deleteEntry } = useDeleteDataEntryMutation()

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

const newEntryData = ref<CreateDataEntryPayload>({
  key: '',
  value: '',
  dimensions: {},
  is_active: true,
})

const handleDimensionChange = (dimension: string) => {
  selectedDimension.value = dimension
  clearEditingState()
}

const handleEditModeChange = (mode: 'grid' | 'single') => {
  settings.value.dataEntries.mode = mode
  clearEditingState()
}

const clearEditingState = () => {
  editingEntries.value.clear()
  pendingChanges.value.clear()
}

const handleSaveNewEntry = async () => {
  try {
    const payload = {
      ...newEntryData.value,
      dimensions:
        selectedDimension.value === 'default'
          ? {}
          : {
              [selectedDimension.value]:
                newEntryData.value.dimensions[selectedDimension.value] || '',
            },
    }

    await createEntry(payload)

    newEntryData.value = {
      key: '',
      value: '',
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

const handleKeyDown = (event: KeyboardEvent, entryId: string) => {
  const target = event.target as HTMLInputElement
  const currentRow = target.closest('tr')
  const currentCell = target.closest('td')

  switch (event.key) {
    case 'ArrowUp': {
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
    case 'Esc': {
      event.preventDefault()
      handleDiscardEntry(entryId)

      editingEntries.value.delete(entryId)
      break
    }
    case 'Enter': {
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

const getDimensionValue = (entry: DataEntryResource, dimensionKey: string) => {
  return entry.dimensions?.[dimensionKey] || ''
}

const isEntryEditing = (entryId: string) => {
  return settings.value.dataEntries.mode === 'grid' || editingEntries.value.has(entryId)
}

const hasEntryPendingChanges = (entryId: string) => {
  return pendingChanges.value.has(entryId)
}

const isDefaultSelected = computed(() => selectedDimension.value === 'default')
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="dataSource?.name || $t('labels.datasets.dataEntries')"
        :description="dataSource?.description"
      >
        <template #before-header>
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
        <Icon
          name="lucide:loader"
          class="animate-spin"
        />
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

          <div class="overflow-hidden rounded-md border border-input">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableSortableHead
                    v-model="sortBy"
                    class="w-1/3"
                    column="key"
                    >{{ $t('labels.dataEntries.fields.key') }}
                  </TableSortableHead>
                  <TableSortableHead
                    v-model="sortBy"
                    class="w-1/2"
                    column="value"
                    >{{ $t('labels.dataEntries.fields.value') }}
                  </TableSortableHead>
                  <TableHead
                    v-if="!isDefaultSelected"
                    class="w-1/2"
                  >
                    {{ dimensionTabs.find((tab) => tab.key === selectedDimension)?.label }}
                  </TableHead>
                  <TableHead
                    v-if="isDefaultSelected"
                    class="w-16"
                    >{{ $t('labels.dataEntries.fields.active') }}
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
                    <Input
                      v-model="newEntryData.value"
                      :placeholder="$t('labels.dataEntries.fields.value')"
                      :disabled="!isDefaultSelected"
                      @keydown.enter="handleSaveNewEntry"
                    />
                  </TableCell>
                  <TableCell v-if="!isDefaultSelected">
                    <Input
                      v-model="newEntryData.dimensions[selectedDimension]"
                      :placeholder="
                        dimensionTabs.find((tab) => tab.key === selectedDimension)?.label
                      "
                      @keydown.enter="handleSaveNewEntry"
                    />
                  </TableCell>
                  <TableCell>
                    <Switch
                      v-model:checked="newEntryData.is_active"
                      :default-value="true"
                    />
                  </TableCell>
                  <TableCell>
                    <div class="flex gap-1">
                      <Button
                        size="icon"
                        variant="outline"
                        class="h-8 w-8"
                        :disabled="!newEntryData.key || isCreatingEntry"
                        @click="handleSaveNewEntry"
                      >
                        <Icon
                          v-if="isCreatingEntry"
                          name="lucide:loader"
                          class="h-3 w-3 animate-spin"
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
                      <Icon
                        name="lucide:loader"
                        class="mr-2 h-6 w-6 animate-spin"
                      />
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
                      <Textarea
                        v-if="isEntryEditing(entry.id)"
                        :model-value="entry.value"
                        :disabled="!isDefaultSelected"
                        @update:model-value="(value) => handleInputChange(entry, 'value', value)"
                        @keydown="(e) => handleKeyDown(e, entry.id, 'value')"
                      />
                      <span
                        v-else
                        class="block max-w-[200px] truncate"
                        >{{ entry.value || '-' }}</span
                      >
                    </TableCell>

                    <TableCell v-if="!isDefaultSelected">
                      <Textarea
                        v-if="isEntryEditing(entry.id)"
                        :model-value="getDimensionValue(entry, selectedDimension)"
                        @update:model-value="
                          (value) =>
                            handleInputChange(entry, `dimension.${selectedDimension}`, value)
                        "
                        @keydown="
                          (e) => handleKeyDown(e, entry.id, `dimension.${selectedDimension}`)
                        "
                      />
                      <span v-else>{{ getDimensionValue(entry, selectedDimension) || '-' }}</span>
                    </TableCell>

                    <TableCell v-if="isDefaultSelected">
                      <Switch
                        :disabled="!isEntryEditing(entry.id)"
                        :model-value="entry.is_active"
                        @update:model-value="
                          (value) => handleInputChange(entry, 'is_active', value)
                        "
                      />
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
                            <Icon
                              v-if="isUpdatingEntry"
                              name="lucide:loader"
                              class="animate-spin"
                            />
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
</template>
