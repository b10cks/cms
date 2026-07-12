<script setup lang="ts">
import AutomationActionTypeBadge from '~/components/automation-actions/AutomationActionTypeBadge.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter, { type FilterableField } from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Spinner } from '~/components/ui/spinner'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import type { LaravelMeta } from '~/types'

const props = withDefaults(
  defineProps<{
    executions: AutomationExecutionResource[]
    automations: AutomationResource[]
    isLoading: boolean
    isFetching?: boolean
    meta?: LaravelMeta
    currentPage: number
    perPage: number
    canManage?: boolean
    replayingId?: string | null
    sortBy?: {
      column: string
      direction: 'asc' | 'desc'
    }
  }>(),
  {
    canManage: false,
    meta: undefined,
    replayingId: null,
    sortBy: () => ({
      column: 'created_at',
      direction: 'desc' as const,
    }),
  }
)

const emit = defineEmits<{
  view: [execution: AutomationExecutionResource]
  replay: [execution: AutomationExecutionResource]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
  'update:filters': [filters: Record<string, unknown>]
}>()

const { t } = useI18n()
const { formatDateTime, formatRelativeTime, formatDuration } = useFormat()

const filters = ref<Record<string, unknown>>({})

const executionFilters = computed<FilterableField[]>(() => [
  {
    id: 'status',
    label: t('labels.automationExecutions.filters.status'),
    items: [
      { value: 'queued', label: t('labels.automationExecutions.status.queued') },
      { value: 'running', label: t('labels.automationExecutions.status.running') },
      { value: 'completed', label: t('labels.automationExecutions.status.completed') },
      { value: 'failed', label: t('labels.automationExecutions.status.failed') },
    ],
  },
  {
    id: 'automation_id',
    label: t('labels.automationExecutions.filters.automation'),
    items: props.automations.map((automation) => ({
      value: automation.id,
      label: automation.name,
    })),
  },
])

const sortOptions = computed(() => [
  { value: 'created_at', label: t('labels.automationExecutions.sort.createdAt') },
  { value: 'started_at', label: t('labels.automationExecutions.sort.startedAt') },
  { value: 'completed_at', label: t('labels.automationExecutions.sort.completedAt') },
  { value: 'duration', label: t('labels.automationExecutions.sort.duration') },
  { value: 'status', label: t('labels.automationExecutions.sort.status') },
])

const getStatusVariant = (status: AutomationExecutionStatus) => {
  switch (status) {
    case 'queued':
      return 'secondary'
    case 'running':
      return 'info'
    case 'completed':
      return 'success'
    case 'failed':
      return 'destructive'
  }
}

const getSource = (execution: AutomationExecutionResource) => {
  return String(execution.context?.source ?? 'unknown')
}

const getSourceLabel = (execution: AutomationExecutionResource) => {
  switch (getSource(execution)) {
    case 'manual':
    case 'replay':
    case 'trigger':
    case 'schedule':
      return t(`labels.automationExecutions.sources.${getSource(execution)}`)
    default:
      return t('labels.automationExecutions.sources.unknown')
  }
}

const formatExecutionDuration = (duration: number | null | undefined) => {
  if (duration === null || duration === undefined) {
    return '—'
  }

  return duration >= 1000 ? formatDuration(duration, 1, 's') : formatDuration(duration)
}

const getResultPreview = (execution: AutomationExecutionResource) => {
  if (execution.error) {
    return execution.error
  }

  const value = execution.result?.result ?? execution.result

  if (value === null || value === undefined) {
    return t('labels.automationExecutions.emptyResult')
  }

  if (typeof value === 'string') {
    return value
  }

  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

const handleFiltersUpdate = (value: Record<string, unknown>) => {
  filters.value = value
  emit('update:filters', value)
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-2 sm:flex-row">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="executionFilters"
          @update:model-value="handleFiltersUpdate"
        />
        <SortSelect
          :model-value="sortBy"
          :options="sortOptions"
          @update:model-value="(value) => emit('update:sortBy', value)"
        />
      </div>
    </div>

    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('labels.automationExecutions.columns.automation') }}</TableHead>
            <TableHead>{{ $t('labels.automationExecutions.columns.status') }}</TableHead>
            <TableHead>{{ $t('labels.automationExecutions.columns.source') }}</TableHead>
            <TableHead>{{ $t('labels.automationExecutions.columns.timing') }}</TableHead>
            <TableHead>{{ $t('labels.automationExecutions.columns.result') }}</TableHead>
            <TableHead class="w-28">{{
              $t('labels.automationExecutions.columns.actions')
            }}</TableHead>
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
            :colspan="6"
          />

          <template v-else-if="executions.length > 0">
            <TableRow
              v-for="execution in executions"
              :key="execution.id"
            >
              <TableCell class="align-top">
                <div class="space-y-1.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="font-medium text-sm">
                      {{ execution.automation?.name || execution.automation_id }}
                    </p>
                    <AutomationActionTypeBadge
                      v-if="execution.automation?.action"
                      :type="execution.automation.action.type"
                    />
                  </div>
                  <p class="text-muted-foreground text-sm">
                    {{
                      execution.automation?.description ||
                      execution.automation?.action?.name ||
                      execution.id
                    }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-2">
                  <Badge
                    :variant="getStatusVariant(execution.status)"
                    size="sm"
                  >
                    {{ $t(`labels.automationExecutions.status.${execution.status}`) }}
                  </Badge>
                  <p
                    v-if="execution.error"
                    class="max-w-56 truncate text-destructive text-xs"
                  >
                    {{ execution.error }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-1">
                  <Badge
                    variant="surface"
                    size="sm"
                  >
                    {{ getSourceLabel(execution) }}
                  </Badge>
                  <p class="text-muted-foreground text-xs">
                    {{ formatRelativeTime(execution.created_at) }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-1">
                  <div class="flex flex-col">
                    <span class="text-sm">{{ formatDateTime(execution.created_at) }}</span>
                    <span class="text-muted-foreground text-xs">
                      {{ $t('labels.automationExecutions.fields.queuedAt') }}
                    </span>
                  </div>
                  <div
                    v-if="execution.completed_at || execution.started_at"
                    class="text-muted-foreground text-xs"
                  >
                    {{ formatExecutionDuration(execution.duration) }}
                  </div>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <p class="max-w-xs text-sm">
                  {{ getResultPreview(execution) }}
                </p>
              </TableCell>

              <TableCell class="align-top">
                <div class="flex items-center justify-end gap-2">
                  <Button
                    variant="ghost"
                    size="icon"
                    @click="emit('view', execution)"
                  >
                    <Icon name="lucide:eye" />
                    <span class="sr-only">{{ $t('actions.automationExecutions.view') }}</span>
                  </Button>

                  <Button
                    v-if="canManage"
                    variant="outline"
                    size="icon"
                    :disabled="
                      ['queued', 'running'].includes(execution.status) ||
                      replayingId === execution.id
                    "
                    @click="emit('replay', execution)"
                  >
                    <Spinner v-if="replayingId === execution.id" />
                    <Icon
                      v-else
                      name="lucide:rotate-ccw"
                    />
                    <span class="sr-only">{{ $t('actions.automationExecutions.replay') }}</span>
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          </template>

          <TableEmptyRow
            v-else
            :colspan="6"
            :label="$t('labels.automationExecutions.emptyTable')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="meta"
      :meta="meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(value) => emit('update:currentPage', value)"
      @update:per-page="(value) => emit('update:perPage', value)"
    />
  </div>
</template>
