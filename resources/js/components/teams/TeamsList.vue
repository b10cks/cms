<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import UsersIcon from '~/assets/images/users.svg?component'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import IconName from '~/components/ui/IconName.vue'
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
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import type { LaravelMeta } from '~/types'
import type { TeamHierarchyItem, TeamResource } from '~/types/teams'
import TableEmptyRow from '../ui/TableEmptyRow.vue'

const props = withDefaults(
  defineProps<{
    teams: TeamResource[]
    hierarchy: TeamHierarchyItem[]
    isLoading: boolean
    meta?: LaravelMeta
    currentPage: number
    perPage: number
    sortBy?: { column: string; direction: 'asc' | 'desc' }
  }>(),
  {
    sortBy: () => ({
      column: 'name',
      direction: 'asc' as const,
    }),
  }
)

const emit = defineEmits<{
  view: [teamId: string]
  edit: [team: TeamResource]
  delete: [teamId: string]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
  'update:filters': [filters: Record<string, unknown>]
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime, formatRelativeTime } = useFormat()

const teamTypeOptions = computed(() => [
  { value: 'partner', label: t('labels.teams.types.partner') },
  { value: 'reseller', label: t('labels.teams.types.reseller') },
  { value: 'affiliate', label: t('labels.teams.types.affiliate') },
])

const teamFilters = computed(() => [
  {
    id: 'name',
    label: t('labels.teams.filters.name'),
    operators: [
      { value: 'like' as const, label: t('labels.teams.operators.contains') },
      { value: '^like' as const, label: t('labels.teams.operators.startsWith') },
      { value: 'like$' as const, label: t('labels.teams.operators.endsWith') },
      { value: 'eq' as const, label: t('labels.teams.operators.equals') },
    ],
  },
  {
    id: 'type',
    label: t('labels.teams.filters.type'),
    operators: [{ value: 'eq' as const, label: t('labels.teams.operators.equals') }],
    items: teamTypeOptions.value,
  },
])

const sortOptions = computed(() => [
  { value: 'name', label: t('labels.teams.sort.name') },
  { value: 'created_at', label: t('labels.teams.sort.created_at') },
  { value: 'updated_at', label: t('labels.teams.sort.updated_at') },
])

const filters = ref<Record<string, unknown>>({})
const selectedTeams = ref<Map<string, TeamResource>>(new Map())

const selectionCount = computed(() => selectedTeams.value.size)
const isAllSelected = computed(() => {
  return selectionCount.value > 0 && props.teams.length === selectionCount.value
})

const handleSelectAll = (checked: boolean) => {
  if (checked) {
    props.teams.forEach((team) => {
      selectedTeams.value.set(team.id, team)
    })
  } else {
    clearSelection()
  }
}

const handleTeamSelect = (team: TeamResource, selected: boolean) => {
  if (selected) {
    selectedTeams.value.set(team.id, team)
  } else {
    selectedTeams.value.delete(team.id)
  }
}

const clearSelection = () => {
  selectedTeams.value.clear()
}

const isTeamSelected = (team: TeamResource) => {
  return selectedTeams.value.has(team.id)
}

const handleView = (team: TeamResource) => {
  emit('view', team.id)
}

const handleEdit = (team: TeamResource) => {
  emit('edit', team)
}

const handleDelete = async (team: TeamResource) => {
  const confirmed = await alert.confirm(
    t('labels.teams.deleteConfirm.message', { name: team.name }),
    {
      title: t('labels.teams.deleteConfirm.title'),
      confirmLabel: t('labels.teams.deleteConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    emit('delete', team.id)
  }
}

const handleBulkDelete = async () => {
  const confirmed = await alert.confirm(
    t('labels.teams.deleteConfirm.bulkMessage', { count: selectionCount.value }),
    {
      title: t('labels.teams.deleteConfirm.bulkTitle'),
      confirmLabel: t('labels.teams.deleteConfirm.bulkConfirmLabel', {
        count: selectionCount.value,
      }),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedTeams.value.keys())
    for (const id of selectedIds) {
      emit('delete', id)
    }
    clearSelection()
  }
}

const getParentName = (parentId: string | null | undefined): string => {
  if (!parentId) return ''

  const findInHierarchy = (items: TeamHierarchyItem[]): string => {
    for (const item of items) {
      if (item.id === parentId) return item.name
      if (item.children?.length) {
        const found = findInHierarchy(item.children)
        if (found) return found
      }
    }
    return ''
  }

  return findInHierarchy(props.hierarchy)
}

const getTeamColorStyle = (color: string | null | undefined) => {
  if (!color) return {}
  return { backgroundColor: color }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-2 sm:flex-row">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="teamFilters"
          @update:model-value="(value) => emit('update:filters', value)"
        />
        <SortSelect
          :model-value="sortBy"
          :options="sortOptions"
          @update:model-value="(value) => emit('update:sortBy', value)"
        />
      </div>
    </div>

    <div
      v-if="selectionCount > 0"
      class="flex flex-col gap-3 rounded-lg border border-primary/20 bg-primary/5 p-4 sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="flex items-center gap-2">
        <Badge variant="primary">{{
          $t('labels.teams.selection.selected', { count: selectionCount })
        }}</Badge>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row">
        <Button
          size="sm"
          variant="destructive"
          @click="handleBulkDelete"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('labels.teams.actions.delete', { count: selectionCount }) }}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          @click="clearSelection"
        >
          {{ $t('labels.teams.selection.clear') }}
        </Button>
      </div>
    </div>

    <div class="rounded-lg border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-12">
              <Checkbox
                :checked="isAllSelected"
                :indeterminate="selectionCount > 0 && !isAllSelected"
                @update:checked="handleSelectAll"
              />
            </TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="name"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.teams.columns.name') }}
            </TableSortableHead>
            <TableHead>{{ $t('labels.teams.columns.type') }}</TableHead>
            <TableHead>{{ $t('labels.teams.columns.parent') }}</TableHead>
            <TableHead class="text-center">{{ $t('labels.teams.columns.members') }}</TableHead>
            <TableHead class="text-center">{{ $t('labels.teams.columns.spaces') }}</TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="updated_at"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.teams.columns.updated') }}
            </TableSortableHead>
            <TableHead class="w-24"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="8"
          />
          <TableEmptyRow
            v-else-if="!teams || teams.length === 0"
            :colspan="8"
            :icon="UsersIcon"
            :label="$t('labels.teams.empty')"
          />

          <template v-else>
            <TableRow
              v-for="team in teams"
              :key="team.id"
              :class="{ 'bg-muted/30': isTeamSelected(team) }"
              class="cursor-pointer hover:bg-muted/50"
              @click="handleView(team)"
            >
              <TableCell
                class="w-12"
                @click.stop
              >
                <Checkbox
                  :checked="isTeamSelected(team)"
                  @update:checked="(checked) => handleTeamSelect(team, checked)"
                />
              </TableCell>

              <TableCell>
                <div class="flex items-center gap-3">
                  <IconName
                    :icon="team.icon || 'users'"
                    :color="team.color || undefined"
                    :name="team.name"
                  />
                  <div
                    v-if="team.description"
                    class="text-muted-foreground text-sm"
                  >
                    {{ team.description }}
                  </div>
                </div>
              </TableCell>

              <TableCell>
                <Badge
                  v-if="team.type"
                  variant="outline"
                  size="sm"
                >
                  {{ team.type }}
                </Badge>
                <span
                  v-else
                  class="text-muted-foreground text-sm"
                >
                  {{ $t('labels.teams.noType') }}
                </span>
              </TableCell>

              <TableCell>
                <span
                  v-if="team.parent_id"
                  class="text-sm"
                >
                  {{ getParentName(team.parent_id) }}
                </span>
                <span
                  v-else
                  class="text-muted-foreground text-sm"
                >
                  {{ $t('labels.teams.noParent') }}
                </span>
              </TableCell>

              <TableCell class="text-center">
                <Badge
                  variant="secondary"
                  size="sm"
                >
                  {{ team.user_count || 0 }}
                </Badge>
              </TableCell>

              <TableCell class="text-center">
                <Badge
                  variant="secondary"
                  size="sm"
                >
                  {{ team.spaces_count || 0 }}
                </Badge>
              </TableCell>

              <TableCell class="text-muted-foreground text-sm">
                {{ formatRelativeTime(team.updated_at) }}
              </TableCell>

              <TableCell
                class="text-right"
                @click.stop
              >
                <div class="flex justify-end gap-1">
                  <Button
                    size="icon"
                    variant="ghost"
                    :title="$t('labels.teams.tooltip.edit')"
                    @click="handleEdit(team)"
                  >
                    <Icon name="lucide:pencil" />
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    :title="$t('labels.teams.tooltip.delete')"
                    @click="handleDelete(team)"
                  >
                    <Icon
                      name="lucide:trash-2"
                      class="text-destructive"
                    />
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          </template>
        </TableBody>
      </Table>
    </div>
    <TablePaginationFooter
      v-if="meta"
      :meta="meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(val) => emit('update:currentPage', val)"
      @update:per-page="(val) => emit('update:perPage', val)"
    />
  </div>
</template>
