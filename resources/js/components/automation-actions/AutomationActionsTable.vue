<script setup lang="ts">
import AutomationActionTypeBadge from '~/components/automation-actions/AutomationActionTypeBadge.vue'
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
import { summarizeAction } from '~/utils/automations'

const props = withDefaults(
  defineProps<{
    actions: AutomationActionResource[]
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
    sortBy: () => ({
      column: 'updated_at',
      direction: 'desc' as const,
    }),
  }
)

const emit = defineEmits<{
  edit: [action: AutomationActionResource]
  delete: [action: AutomationActionResource]
  toggle: [action: AutomationActionResource, value: boolean]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
  'update:filters': [filters: Record<string, unknown>]
}>()

const { t } = useI18n()
const { formatDateTime, formatRelativeTime } = useFormat()

const filters = ref<Record<string, unknown>>({})

const actionFilters = computed<FilterableField[]>(() => [
  {
    id: 'type',
    label: t('labels.automationActions.filters.type'),
    items: [
      { value: 'webhook', label: t('labels.automationActions.types.webhook') },
      { value: 'email', label: t('labels.automationActions.types.email') },
      { value: 'void', label: t('labels.automationActions.types.void') },
    ],
  },
  {
    id: 'is_active',
    label: t('labels.automationActions.filters.status'),
    items: [
      { value: '1', label: t('labels.automationActions.status.active') },
      { value: '0', label: t('labels.automationActions.status.inactive') },
    ],
  },
])

const sortOptions = computed(() => [
  { value: 'name', label: t('labels.automationActions.sort.name') },
  { value: 'type', label: t('labels.automationActions.sort.type') },
  { value: 'last_executed_at', label: t('labels.automationActions.sort.lastExecuted') },
  { value: 'updated_at', label: t('labels.automationActions.sort.updatedAt') },
  { value: 'created_at', label: t('labels.automationActions.sort.createdAt') },
])

const getActivityVariant = (isActive: boolean) => {
  return isActive ? 'success' : 'secondary'
}

const getExecutionVariant = (status: AutomationExecutionStatus | null | undefined) => {
  switch (status) {
    case 'completed':
      return 'success'
    case 'failed':
      return 'destructive'
    case 'running':
      return 'info'
    default:
      return 'surface'
  }
}

const getExecutionLabel = (status: AutomationExecutionStatus | null | undefined) => {
  if (!status) {
    return t('labels.automationActions.status.idle')
  }

  return t(`labels.automationActions.status.${status}`)
}

const handleFiltersUpdate = (value: Record<string, unknown>) => {
  filters.value = value
  emit('update:filters', value)
}

const handleToggle = (action: AutomationActionResource, value: boolean) => {
  emit('toggle', action, value)
}

const getCheckedState = (action: AutomationActionResource) => {
  return props.togglePending?.[action.id] ?? action.is_active
}

const isTogglePending = (action: AutomationActionResource) => {
  return Object.prototype.hasOwnProperty.call(props.togglePending || {}, action.id)
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-2 sm:flex-row">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="actionFilters"
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
            <TableHead>{{ $t('labels.automationActions.columns.name') }}</TableHead>
            <TableHead>{{ $t('labels.automationActions.columns.type') }}</TableHead>
            <TableHead>{{ $t('labels.automationActions.columns.linked') }}</TableHead>
            <TableHead>{{ $t('labels.automationActions.columns.status') }}</TableHead>
            <TableHead>{{ $t('labels.automationActions.columns.lastExecuted') }}</TableHead>
            <TableHead>{{ $t('labels.automationActions.columns.updated') }}</TableHead>
            <TableHead class="w-28" />
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="7"
          />

          <template v-else-if="actions.length > 0">
            <TableRow
              v-for="action in actions"
              :key="action.id"
            >
              <TableCell class="align-top">
                <div class="space-y-1.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="font-medium text-sm">{{ action.name }}</p>
                    <Badge
                      :variant="getActivityVariant(action.is_active)"
                      size="sm"
                    >
                      {{
                        $t(
                          action.is_active
                            ? 'labels.automationActions.status.active'
                            : 'labels.automationActions.status.inactive'
                        )
                      }}
                    </Badge>
                  </div>
                  <p class="text-muted-foreground text-sm">
                    {{ action.description || summarizeAction(action, t) }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-2">
                  <AutomationActionTypeBadge :type="action.type" />
                  <p class="text-muted-foreground text-xs">
                    {{ summarizeAction(action, t) }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-1">
                  <Badge
                    variant="surface"
                    size="sm"
                  >
                    {{
                      $t('labels.automationActions.linkedAutomationsCount', {
                        count: action.automations_count ?? 0,
                      })
                    }}
                  </Badge>
                  <p
                    v-if="(action.automations_count ?? 0) > 0"
                    class="text-muted-foreground text-xs"
                  >
                    {{ $t('labels.automationActions.actionsDisabledHint') }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="space-y-2">
                  <Badge
                    :variant="getExecutionVariant(action.last_execution_status)"
                    size="sm"
                  >
                    {{ getExecutionLabel(action.last_execution_status) }}
                  </Badge>
                  <p
                    v-if="action.last_execution_error"
                    class="max-w-56 truncate text-destructive text-xs"
                  >
                    {{ action.last_execution_error }}
                  </p>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div
                  v-if="action.last_executed_at"
                  class="flex flex-col"
                >
                  <span class="text-sm">{{ formatDateTime(action.last_executed_at) }}</span>
                  <span class="text-muted-foreground text-xs">
                    {{ formatRelativeTime(action.last_executed_at) }}
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
                  <span class="text-sm">{{ formatDateTime(action.updated_at) }}</span>
                  <span class="text-muted-foreground text-xs">
                    {{ formatRelativeTime(action.updated_at) }}
                  </span>
                </div>
              </TableCell>

              <TableCell class="align-top">
                <div class="flex items-center justify-end gap-2">
                  <Switch
                    v-if="canManage"
                    :checked="getCheckedState(action)"
                    :disabled="isTogglePending(action)"
                    @update:checked="handleToggle(action, $event)"
                  />

                  <DropdownMenu v-if="canManage">
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{
                          $t('labels.automationActions.columns.actions')
                        }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end">
                      <DropdownMenuItem @click="emit('edit', action)">
                        <Icon name="lucide:pencil" />
                        {{ $t('actions.edit') }}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        :disabled="(action.automations_count ?? 0) > 0"
                        class="text-destructive focus:text-destructive"
                        @click="emit('delete', action)"
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
            :label="$t('labels.automationActions.emptyTable')"
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
