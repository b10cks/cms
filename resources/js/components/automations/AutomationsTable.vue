<script setup lang="ts">
import AutomationActionTypeBadge from '~/components/automation-actions/AutomationActionTypeBadge.vue'
import AutomationTriggerTypeBadge from '~/components/automations/AutomationTriggerTypeBadge.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter, { type FilterableField } from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Switch } from '~/components/ui/switch'
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
import { getTriggerTable, summarizeTrigger } from '~/utils/automations'

const props = withDefaults(
  defineProps<{
    automations: AutomationResource[]
    triggerCatalog?: AutomationTriggerCatalogResource | null
    isLoading: boolean
    meta?: LaravelMeta
    currentPage: number
    perPage: number
    canManage?: boolean
    togglePending?: Record<string, boolean>
    sortBy?: {
      column: string
      direction: 'asc' | 'desc'
    }
  }>(),
  {
    canManage: false,
    meta: undefined,
    togglePending: () => ({}),
    triggerCatalog: null,
    sortBy: () => ({
      column: 'updated_at',
      direction: 'desc' as const,
    }),
  }
)

const emit = defineEmits<{
  edit: [automation: AutomationResource]
  delete: [automation: AutomationResource]
  trigger: [automation: AutomationResource]
  toggle: [automation: AutomationResource, value: boolean]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
  'update:filters': [filters: Record<string, unknown>]
}>()

const { t } = useI18n()
const { formatDateTime, formatRelativeTime, formatNumber } = useFormat()

const filters = ref<Record<string, unknown>>({})

const automationFilters = computed<FilterableField[]>(() => [
  {
    id: 'trigger_type',
    label: t('labels.automations.filters.triggerType'),
    items: [
      { value: 'on_insert', label: t('labels.automations.triggerTypes.on_insert') },
      { value: 'on_update', label: t('labels.automations.triggerTypes.on_update') },
      { value: 'on_delete', label: t('labels.automations.triggerTypes.on_delete') },
      { value: 'time_based', label: t('labels.automations.triggerTypes.time_based') },
      { value: 'manual', label: t('labels.automations.triggerTypes.manual') },
    ],
  },
  {
    id: 'table',
    label: t('labels.automations.filters.table'),
    items: (props.triggerCatalog?.tables || []).map((table) => ({
      value: table.table,
      label: table.label,
    })),
  },
  {
    id: 'action_type',
    label: t('labels.automations.filters.actionType'),
    items: [
      { value: 'webhook', label: t('labels.automationActions.types.webhook') },
      { value: 'email', label: t('labels.automationActions.types.email') },
      { value: 'void', label: t('labels.automationActions.types.void') },
    ],
  },
  {
    id: 'is_active',
    label: t('labels.automations.filters.status'),
    items: [
      { value: '1', label: t('labels.automations.status.active') },
      { value: '0', label: t('labels.automations.status.inactive') },
    ],
  },
])

const sortOptions = computed(() => [
  { value: 'name', label: t('labels.automations.sort.name') },
  { value: 'trigger_type', label: t('labels.automations.sort.triggerType') },
  { value: 'execution_count', label: t('labels.automations.sort.executionCount') },
  { value: 'last_triggered_at', label: t('labels.automations.sort.lastTriggered') },
  { value: 'updated_at', label: t('labels.automations.sort.updatedAt') },
  { value: 'created_at', label: t('labels.automations.sort.createdAt') },
])

const getActivityVariant = (isActive: boolean) => {
  return isActive ? 'success' : 'secondary'
}

const formatExecutionSummary = (automation: AutomationResource) => {
  if (automation.execution_limit === null || automation.execution_limit === undefined) {
    return t('labels.automations.unlimitedExecutions')
  }

  return t('labels.automations.executionSummary', {
    used: formatNumber(automation.execution_count),
    limit: formatNumber(automation.execution_limit),
    remaining: formatNumber(
      automation.remaining_executions ??
        Math.max(automation.execution_limit - automation.execution_count, 0)
    ),
  })
}

const formatWatchedColumns = (automation: AutomationResource) => {
  const watchedColumns = automation.trigger.config?.watch_columns || []

  if (automation.trigger_type !== 'on_update' || watchedColumns.length === 0) {
    return null
  }

  return t('labels.automations.watchedColumnsSummary', {
    count: watchedColumns.length,
  })
}

const handleFiltersUpdate = (value: Record<string, unknown>) => {
  filters.value = value
  emit('update:filters', value)
}

const handleToggle = (automation: AutomationResource, value: boolean) => {
  emit('toggle', automation, value)
}

const getCheckedState = (automation: AutomationResource) => {
  return props.togglePending?.[automation.id] ?? automation.is_active
}

const isTogglePending = (automation: AutomationResource) => {
  return Object.prototype.hasOwnProperty.call(props.togglePending || {}, automation.id)
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-2 sm:flex-row">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="automationFilters"
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
            <TableHead>{{ $t('labels.automations.columns.name') }}</TableHead>
            <TableHead>{{ $t('labels.automations.columns.trigger') }}</TableHead>
            <TableHead>{{ $t('labels.automations.columns.action') }}</TableHead>
            <TableHead>{{ $t('labels.automations.columns.executions') }}</TableHead>
            <TableHead>{{ $t('labels.automations.columns.lastTriggered') }}</TableHead>
            <TableHead>{{ $t('labels.automations.columns.updated') }}</TableHead>
            <TableHead class="w-32" />
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="7"
          />

          <template v-else-if="automations.length > 0">
            <TableRow
              v-for="automation in automations"
              :key="automation.id"
            >
              <TableCell class="align-top">
                <div class="space-y-1.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="font-medium text-sm">{{ automation.name }}</p>
                    <Badge
                      :variant="getActivityVariant(automation.is_active)"
                      size="sm"
                    >
                      {{
                        $t(
                          automation.is_active
                            ? 'labels.automations.status.active'
                            : 'labels.automations.status.inactive'
                        )
                      }}
                    </Badge>
                  </div>
                  <p class="text-muted-foreground text-sm">
                    {{ automation.description || summarizeTrigger(automation, t, triggerCatalog) }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-2">
                  <AutomationTriggerTypeBadge :type="automation.trigger_type" />
                  <p class="text-muted-foreground text-xs">
                    {{ summarizeTrigger(automation, t, triggerCatalog) }}
                  </p>
                  <p
                    v-if="getTriggerTable(automation.trigger.config)"
                    class="text-muted-foreground text-xs"
                  >
                    {{ getTriggerTable(automation.trigger.config) }}
                  </p>
                  <p
                    v-if="formatWatchedColumns(automation)"
                    class="text-muted-foreground text-xs"
                  >
                    {{ formatWatchedColumns(automation) }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div
                  v-if="automation.action"
                  class="space-y-2"
                >
                  <p class="font-medium text-sm">{{ automation.action.name }}</p>
                  <div class="flex flex-wrap items-center gap-2">
                    <AutomationActionTypeBadge :type="automation.action.type" />
                    <span class="text-muted-foreground text-xs">
                      {{ automation.action.description || automation.action.name }}
                    </span>
                  </div>
                </div>
                <span
                  v-else
                  class="text-muted-foreground text-sm"
                >
                  {{ $t('labels.automations.missingAction') }}
                </span>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-1">
                  <p class="font-medium text-sm">
                    {{ formatNumber(automation.execution_count) }}
                  </p>
                  <p class="text-muted-foreground text-xs">
                    {{ formatExecutionSummary(automation) }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div
                  v-if="automation.last_triggered_at"
                  class="flex flex-col"
                >
                  <span class="text-sm">{{ formatDateTime(automation.last_triggered_at) }}</span>
                  <span class="text-muted-foreground text-xs">
                    {{ formatRelativeTime(automation.last_triggered_at) }}
                  </span>
                </div>
                <span
                  v-else
                  class="text-muted-foreground text-sm"
                >
                  {{ $t('labels.never') }}
                </span>
              </TableCell>

              <TableCell class="align-top">
                <div class="flex flex-col">
                  <span class="text-sm">{{ formatDateTime(automation.updated_at) }}</span>
                  <span class="text-muted-foreground text-xs">
                    {{ formatRelativeTime(automation.updated_at) }}
                  </span>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="flex items-center justify-end gap-2">
                  <Button
                    v-if="canManage && automation.trigger_type === 'manual'"
                    variant="outline"
                    size="icon"
                    @click="emit('trigger', automation)"
                  >
                    <Icon name="lucide:play" />
                    <span class="sr-only">{{ $t('actions.automations.runNow') }}</span>
                  </Button>

                  <Switch
                    v-if="canManage"
                    :checked="getCheckedState(automation)"
                    :disabled="isTogglePending(automation)"
                    @update:checked="handleToggle(automation, $event)"
                  />

                  <DropdownMenu v-if="canManage">
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{ $t('labels.automations.columns.actions') }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end">
                      <DropdownMenuItem
                        v-if="automation.trigger_type === 'manual'"
                        @click="emit('trigger', automation)"
                      >
                        <Icon name="lucide:play" />
                        {{ $t('actions.automations.runNow') }}
                      </DropdownMenuItem>
                      <DropdownMenuItem @click="emit('edit', automation)">
                        <Icon name="lucide:pencil" />
                        {{ $t('actions.edit') }}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @click="emit('delete', automation)"
                      >
                        <Icon name="lucide:trash-2" />
                        {{ $t('actions.delete') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </TableCell>
            </TableRow>
          </template>

          <TableEmptyRow
            v-else
            :colspan="7"
            :label="$t('labels.automations.emptyTable')"
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
