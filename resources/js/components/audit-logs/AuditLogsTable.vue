<script setup lang="ts">
import type { AuditLogsQueryParams } from '~/api/resources/audit-logs'
import SearchFilter from '~/components/SearchFilter.vue'
import type { FilterableField } from '~/components/SearchFilter.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { DateRangePicker } from '~/components/ui/date-range-picker'
import type { DateRangeValue } from '~/components/ui/date-range-picker'
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

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { formatDateTime } = useFormat()

const dateRange = ref<DateRangeValue>({ start: null, end: null, preset: null })

const {
  sortBy,
  filters,
  paginationBindings,
  queryParams: tableParams,
} = useTableQueryState({
  defaultSort: { column: 'created_at', direction: 'desc' },
  pageSize: 20,
  // Reset to the first page whenever the active filters change
  resetOnFilters: true,
  resetOn: () => dateRange.value,
})

const createdAtFilter = computed<string | undefined>(() => {
  const { start, end } = dateRange.value
  if (!start && !end) {
    return undefined
  }
  return `${start ?? ''}...${end ?? ''}`
})

const operationKeys = [
  'created',
  'updated',
  'deleted',
  'published',
  'unpublished',
  'scheduled',
  'moved',
  'committed',
  'canceled',
  'restored',
  'version_selected',
  'assigned',
  'removed',
  'resolved',
  'unresolved',
  'reset',
] as const

const referencedTypeKeys = [
  'content',
  'content_version',
  'block',
  'block_version',
  'block_template',
  'block_folder',
  'block_tag',
  'asset',
  'asset_folder',
  'asset_tag',
  'data_source',
  'data_entry',
  'redirect',
  'release',
  'comment',
  'comment_reaction',
] as const

function buildTranslatedItems<T extends readonly string[]>(
  keys: T,
  baseKey: string
): { value: T[number]; label: string }[] {
  return keys.map((value) => ({
    value,
    label: $t(`${baseKey}.${value}`) as string,
  }))
}

const operationItems = computed(() =>
  buildTranslatedItems(operationKeys, 'labels.auditLog.operations')
)

const referencedTypeItems = computed(() =>
  buildTranslatedItems(referencedTypeKeys, 'labels.auditLog.types')
)

const auditLogFilters = computed<FilterableField[]>(() => [
  {
    id: 'owner_type',
    label: $t('labels.auditLog.filters.ownerType') as string,
    operators: [{ value: 'eq', label: 'Equals' }],
    items: [
      { value: 'user', label: 'User' },
      { value: 'system', label: 'System' },
    ],
  },
  {
    id: 'owner',
    label: $t('labels.auditLog.filters.owner') as string,
    operators: [
      { value: 'like', label: 'Contains' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'operation',
    label: $t('labels.auditLog.filters.operation') as string,
    operators: [
      { value: 'eq', label: 'Equals' },
      { value: 'neq', label: 'Not equals' },
    ],
    items: operationItems.value,
  },
  {
    id: 'referenced_type',
    label: $t('labels.auditLog.filters.referencedType') as string,
    operators: [
      { value: 'eq', label: 'Equals' },
      { value: 'neq', label: 'Not equals' },
    ],
    items: referencedTypeItems.value,
  },
  {
    id: 'name',
    label: $t('labels.auditLog.filters.name') as string,
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
])

const queryParams = computed<AuditLogsQueryParams>(() => ({
  ...tableParams.value,
  ...(createdAtFilter.value ? { created_at: createdAtFilter.value } : {}),
}))

const { useAuditLogsQuery } = useAuditLogs(props.spaceId)
const { data: logs, isLoading, isFetching } = useAuditLogsQuery(queryParams)

const sortOptions = [
  { value: 'created_at', label: $t('labels.auditLog.columns.time') },
  { value: 'owner_name', label: $t('labels.auditLog.columns.owner') },
  { value: 'operation', label: $t('labels.auditLog.columns.action') },
  { value: 'referenced_type', label: $t('labels.auditLog.columns.type') },
  { value: 'name', label: $t('labels.auditLog.columns.item') },
]

const rows = computed(() => logs.value?.data ?? [])

function getActionLabel(row: AuditLogResource): string {
  const keyLabel = $t(`labels.auditLog.actions.${row.key}`) as string
  if (keyLabel && !keyLabel.startsWith('labels.auditLog.actions.')) {
    return keyLabel
  }
  const opLabel = $t(`labels.auditLog.operations.${row.operation}`) as string
  if (opLabel && !opLabel.startsWith('labels.auditLog.operations.')) {
    return opLabel
  }
  return row.operation
}

function getTypeLabel(type: string): string {
  const label = $t(`labels.auditLog.types.${type}`) as string
  return label.startsWith('labels.auditLog.types.') ? type : label
}

function getActionBadgeVariant(
  row: AuditLogResource
): 'success' | 'warning' | 'destructive' | 'secondary' | 'surface' {
  const action = row.key || row.operation

  if (action.includes('publish')) {
    return 'success'
  }

  if (action.includes('unpublish')) {
    return 'warning'
  }

  switch (row.operation) {
    case 'created':
      return 'success'
    case 'updated':
      return 'warning'
    case 'deleted':
      return 'destructive'
    default:
      return 'secondary'
  }
}

function buildItemRoute(row: AuditLogResource): object | null {
  if (
    !row.item.exists ||
    !row.item.route_name ||
    !row.item.route_params ||
    row.operation === 'deleted'
  ) {
    return null
  }
  return {
    name: row.item.route_name,
    params: { space: props.spaceId, ...row.item.route_params },
    ...(row.item.route_query ? { query: row.item.route_query } : {}),
  }
}
</script>

<template>
  <div class="space-y-2">
    <div class="ml-auto flex items-center gap-2">
      <SearchFilter
        v-model="filters"
        :filterable-fields="auditLogFilters"
        class="lg:min-w-xs 2xl:min-w-md"
      />
      <DateRangePicker v-model="dateRange" />
      <SortSelect
        v-model="sortBy"
        :options="sortOptions"
        :label="$t('labels.sortBy')"
        :placeholder="$t('labels.sortBy')"
      />
    </div>

    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableSortableHead
              v-model="sortBy"
              column="created_at"
            >
              {{ $t('labels.auditLog.columns.time') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="owner_name"
            >
              {{ $t('labels.auditLog.columns.owner') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="operation"
            >
              {{ $t('labels.auditLog.columns.action') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="referenced_type"
            >
              {{ $t('labels.auditLog.columns.type') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="name"
            >
              {{ $t('labels.auditLog.columns.item') }}
            </TableSortableHead>
          </TableRow>
        </TableHeader>

        <TableBody
          :class="
            isFetching && !isLoading
              ? 'opacity-50 transition-opacity duration-200'
              : 'transition-opacity duration-200'
          "
        >
          <TableLoadingRow
            v-if="isLoading"
            :colspan="5"
          />
          <template v-else-if="rows.length > 0">
            <TableRow
              v-for="row in rows"
              :key="row.id"
              class="hover:bg-muted/50"
            >
              <TableCell class="text-sm text-muted-foreground whitespace-nowrap">
                {{ row.created_at ? formatDateTime(row.created_at) : '' }}
              </TableCell>

              <TableCell>
                <div class="flex items-center gap-2">
                  <Avatar
                    :name="row.owner_name ?? (row.owner_type === 'system' ? 'System' : '?')"
                    :avatar="row.owner?.avatar ?? null"
                  />
                  <span class="text-sm">{{ row.owner_name }}</span>
                </div>
              </TableCell>

              <TableCell>
                <Badge :variant="getActionBadgeVariant(row)">
                  {{ getActionLabel(row) }}
                </Badge>
              </TableCell>

              <TableCell>
                <Badge>
                  {{ getTypeLabel(row.referenced_type) }}
                </Badge>
              </TableCell>

              <TableCell>
                <div class="flex items-center gap-2">
                  <component
                    :is="buildItemRoute(row) ? 'RouterLink' : 'span'"
                    :to="buildItemRoute(row) ?? undefined"
                    class="text-sm"
                    :class="buildItemRoute(row) ? 'text-primary hover:underline' : ''"
                  >
                    {{ row.name }}
                  </component>
                </div>
              </TableCell>
            </TableRow>
          </template>
          <TableEmptyRow
            v-else
            :colspan="5"
            :label="$t('labels.auditLog.emptyState')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="logs?.meta"
      :meta="logs.meta"
      v-bind="paginationBindings"
    />
  </div>
</template>
