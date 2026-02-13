<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useClipboard } from '@vueuse/core'
import { useRouter } from 'vue-router'
import type { DataSourcesQueryParams } from '~/api/resources/data-sources'
import DataSourcesIcon from '~/assets/images/datasources.svg?component'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import SortSelect from '~/components/ui/SortSelect.vue'
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
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { useDataSources } from '~/composables/useDataSources'
import type { DataSourceResource } from '~/types/data-sources'

const props = defineProps<{
  spaceId: string
}>()

const { useDataSourcesQuery, useDeleteDataSourceMutation } = useDataSources(props.spaceId)
const { mutate: deleteDataSource } = useDeleteDataSourceMutation()

const emit = defineEmits<{
  (e: 'edit' | 'delete', dataSource: DataSourceResource): void
}>()

const { $t } = useI18n()
const router = useRouter()
const route = useRoute()

const filters = ref<Record<string, unknown>>({})
const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(10)

const possibleFilters = [
  {
    id: 'name',
    label: $t('labels.datasets.fields.name'),
    type: 'text',
    placeholder: $t('labels.datasets.placeholders.name'),
  },
  {
    id: 'slug',
    label: $t('labels.datasets.fields.slug'),
    type: 'text',
    placeholder: $t('labels.datasets.placeholders.slug'),
  },
  {
    id: 'is_active',
    label: $t('labels.datasets.fields.status'),
    type: 'select',
    options: [
      { value: true, label: $t('labels.datasets.status.active') },
      { value: false, label: $t('labels.datasets.status.inactive') },
    ],
  },
]

const sortOptions = [
  { value: 'name', label: $t('labels.datasets.fields.name') },
  { value: 'slug', label: $t('labels.datasets.fields.slug') },
  { value: 'is_active', label: $t('labels.datasets.fields.status') },
  { value: 'created_at', label: $t('labels.datasets.fields.createdAt') },
  { value: 'updated_at', label: $t('labels.datasets.fields.updatedAt') },
]

const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'name',
  direction: 'asc',
})

const queryParams = computed<DataSourcesQueryParams>(() => {
  return {
    ...filters.value,
    sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
    page: currentPage.value,
    per_page: perPage.value,
  }
})

const { data: dataSources, isLoading, refetch } = useDataSourcesQuery(queryParams)

const deleteDialogOpen = ref(false)
const dataSourceToDelete = ref<DataSourceResource | null>(null)

// Navigate to data entries
const viewDataEntries = (dataSource: DataSourceResource) => {
  router.push({
    name: 'space-datasources-dataSourceId',
    params: {
      space: route.params.space,
      dataSourceId: dataSource.id,
    },
  })
}

const copySlug = (slug: string) => {
  const url = `${window.location.origin}/api/v1/datasources/${slug}/entries?token=[YOUR_PUBLIC_API_TOKEN]`
  useClipboard().copy(url)
}

const handleDeleteConfirm = (dataSource: DataSourceResource) => {
  dataSourceToDelete.value = dataSource
  deleteDialogOpen.value = true
}

const confirmDelete = async () => {
  if (dataSourceToDelete.value) {
    await deleteDataSource(dataSourceToDelete.value.id)
    deleteDialogOpen.value = false
    dataSourceToDelete.value = null
  }
}
</script>

<template>
  <div class="space-y-2">
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
        :label="String($t('labels.sortBy'))"
        :placeholder="String($t('labels.sortBy'))"
      />
    </div>
    <div class="overflow-hidden rounded-md border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableSortableHead
              v-model="sortBy"
              column="name"
            >
              {{ $t('labels.datasets.fields.name') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="slug"
            >
              {{ $t('labels.datasets.fields.slug') }}
            </TableSortableHead>
            <TableHead>{{ $t('labels.datasets.description') }}</TableHead>
            <TableSortableHead
              v-model="sortBy"
              column="is_active"
            >
              {{ $t('labels.datasets.status.label') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="entries_count"
              wrap-class="flex justify-end items-center gap-1"
            >
              {{ $t('labels.datasets.entries') }}
            </TableSortableHead>
            <TableHead class="w-16" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="8"
          />
          <template v-else-if="dataSources?.data?.length > 0">
            <TableRow
              v-for="(dataSource, index) in dataSources.data"
              :key="dataSource.id"
              :class="{ 'bg-background/50': index % 2 === 0 }"
            >
              <TableCell class="cursor-pointer">
                <RouterLink :href="`/${spaceId}/datasources/${dataSource.id}`">{{
                  dataSource.name
                }}</RouterLink>
              </TableCell>
              <TableCell>
                <SimpleTooltip
                  class="flex items-center gap-2"
                  :tooltip="$t('labels.datasets.copySlugTooltip')"
                >
                  <span class="font-mono">{{ dataSource.slug }}</span>
                  <button
                    class="cursor-pointer text-muted hover:text-primary"
                    @click.stop="copySlug(dataSource.slug)"
                  >
                    <Icon name="lucide:copy" />
                  </button>
                </SimpleTooltip>
              </TableCell>
              <TableCell class="max-w-[300px] truncate">{{
                dataSource.description || '-'
              }}</TableCell>
              <TableCell>
                <Badge
                  :variant="dataSource.is_active ? 'success' : 'destructive'"
                  size="sm"
                >
                  {{
                    dataSource.is_active
                      ? $t('labels.datasets.status.active')
                      : $t('labels.datasets.status.inactive')
                  }}
                </Badge>
              </TableCell>
              <TableCell class="text-right">{{ dataSource.entries_count }}</TableCell>
              <TableCell>
                <div class="flex gap-1">
                  <Button
                    variant="ghost"
                    size="icon"
                    @click="viewDataEntries(dataSource)"
                  >
                    <Icon name="lucide:file-pen-line" />
                    <span class="sr-only">{{ $t('actions.datasources.viewEntries') }}</span>
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    @click="emit('edit', dataSource)"
                  >
                    <Icon name="lucide:pencil" />
                    <span class="sr-only">{{ $t('actions.datasources.edit') }}</span>
                  </Button>
                  <Button
                    variant="destructive"
                    size="icon"
                    @click="emit('delete', dataSource)"
                  >
                    <Icon name="lucide:trash-2" />
                    <span class="sr-only">{{ $t('actions.datasources.delete') }}</span>
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          </template>
          <TableEmptyRow
            v-else
            :colspan="8"
            :icon="DataSourcesIcon"
            :label="
              searchQuery
                ? String($t('labels.datasets.noSearchResults'))
                : String($t('labels.datasets.noDataSources'))
            "
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="dataSources?.meta"
      :meta="dataSources.meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(val) => (currentPage = val)"
      @update:per-page="(val) => (perPage = val)"
    />
  </div>
</template>
