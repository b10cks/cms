<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { BackupsQueryParams } from '~/api/resources/backups'
import BackupIcon from '~/assets/images/backups.svg?component'
import SearchFilter from '~/components/SearchFilter.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
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
import BackupProgress from './BackupProgress.vue'
import BackupStatusBadge from './BackupStatusBadge.vue'

const { $t } = useI18n()
const { alert } = useAlertDialog()

const backupStates = computed(() => [
  { value: 'pending', label: $t('labels.backups.states.pending') },
  { value: 'active', label: $t('labels.backups.states.active') },
  { value: 'expired', label: $t('labels.backups.states.expired') },
  { value: 'failed', label: $t('labels.backups.states.failed') },
])

const backupFilters = computed(() => [
  {
    id: 'name',
    label: $t('labels.backups.columns.name'),
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'state',
    label: $t('labels.backups.columns.state'),
    operators: [{ value: 'eq', label: 'Equals' }],
    items: backupStates.value,
  },
])

const sortOptions = [
  { value: 'name', label: $t('labels.backups.columns.name') },
  { value: 'state', label: $t('labels.backups.columns.state') },
  { value: 'progress', label: $t('labels.backups.columns.progress') },
  { value: 'created_at', label: $t('labels.backups.columns.createdAt') },
  { value: 'expires_at', label: $t('labels.backups.columns.expiresAt') },
]

const filters = ref<Record<string, unknown>>({})
const currentPage = ref(1)
const perPage = ref(24)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})

const selectedBackups = ref<Map<string, BackupResource>>(new Map())

const queryParams = computed<BackupsQueryParams>(() => {
  return {
    ...filters.value,
    sort: sortBy.value.column,
    order: sortBy.value.direction,
    page: currentPage.value,
    per_page: perPage.value,
  }
})

const props = defineProps<{
  spaceId: string
}>()

const { useBackupsQuery, useDeleteBackupMutation } = useBackups(props.spaceId)
const { data: backups, isLoading } = useBackupsQuery(queryParams)
const { mutate: deleteBackup } = useDeleteBackupMutation()

const { formatDateTime, formatFileSize } = useFormat()

const handleDelete = async (backup: BackupResource) => {
  const confirmed = await alert.confirm(
    $t('labels.backups.deleteConfirmMessage', { name: backup.name }),
    {
      title: $t('labels.backups.deleteConfirmTitle'),
      confirmLabel: $t('actions.backups.delete'),
      cancelLabel: $t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    await deleteBackup(backup.id)
  }
}

const handleBulkDelete = async () => {
  const confirmed = await alert.confirm(
    $t('labels.backups.bulkDeleteConfirmMessage', { count: selectionCount.value }),
    {
      title: $t('labels.backups.bulkDeleteConfirmTitle'),
      confirmLabel: `${$t('actions.backups.delete')} (${selectionCount.value})`,
      cancelLabel: $t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedBackups.value.keys())
    for (const id of selectedIds) {
      await deleteBackup(id)
    }
    clearSelection()
  }
}

const sortedBackups = computed(() => {
  return backups.value?.data || []
})

const selectionCount = computed(() => selectedBackups.value.size)
const isAllSelected = computed(() => {
  return selectionCount.value > 0 && sortedBackups.value.length === selectionCount.value
})

const handleSelectAll = (checked: boolean) => {
  if (checked) {
    sortedBackups.value.forEach((backup) => {
      selectedBackups.value.set(backup.id, backup)
    })
  } else {
    clearSelection()
  }
}

const handleBackupSelect = (backup: BackupResource, selected: boolean) => {
  if (selected) {
    selectedBackups.value.set(backup.id, backup)
  } else {
    selectedBackups.value.delete(backup.id)
  }
}

const clearSelection = () => {
  selectedBackups.value.clear()
}

const isBackupSelected = (backup: BackupResource) => {
  return selectedBackups.value.has(backup.id)
}

watch(
  () => currentPage.value,
  () => {
    clearSelection()
  }
)
</script>

<template>
  <div class="space-y-2">
    <div class="space-y-4">
      <div class="ml-auto flex items-center gap-2">
        <SearchFilter
          v-model="filters"
          :filterable-fields="backupFilters"
          class="lg:min-w-xs 2xl:min-w-md"
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
      v-if="selectionCount > 0"
      class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4"
    >
      <div class="flex items-center gap-2">
        <Badge variant="secondary">{{
          $t('labels.selectionCount', { count: selectionCount })
        }}</Badge>
      </div>
      <div class="flex items-center gap-2">
        <Button
          variant="destructive"
          size="sm"
          @click="handleBulkDelete"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('actions.deleteSelected') }}
        </Button>
        <Button
          variant="outline"
          size="sm"
          @click="clearSelection"
        >
          <Icon name="lucide:x" />
          {{ $t('actions.clear') }}
        </Button>
      </div>
    </div>

    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-8">
              <Checkbox
                :model-value="isAllSelected"
                aria-label="Select all backups"
                @update:model-value="handleSelectAll"
              />
            </TableHead>
            <TableSortableHead
              v-model="sortBy"
              column="name"
            >
              {{ $t('labels.backups.columns.name') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="state"
            >
              {{ $t('labels.backups.columns.state') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="progress"
            >
              {{ $t('labels.backups.columns.progress') }}
            </TableSortableHead>
            <TableHead>{{ $t('labels.backups.columns.size') }}</TableHead>
            <TableHead>{{ $t('labels.backups.columns.recipients') }}</TableHead>
            <TableSortableHead
              v-model="sortBy"
              column="expires_at"
            >
              {{ $t('labels.backups.columns.expiresAt') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="created_at"
            >
              {{ $t('labels.backups.columns.createdAt') }}
            </TableSortableHead>
            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="9"
          />
          <template v-else-if="backups?.data?.length > 0">
            <TableRow
              v-for="backup in backups.data"
              :key="backup.id"
              :data-state="isBackupSelected(backup) ? 'selected' : undefined"
            >
              <TableCell>
                <Checkbox
                  :model-value="isBackupSelected(backup)"
                  :aria-label="`Select backup ${backup.name}`"
                  @update:model-value="(checked) => handleBackupSelect(backup, checked)"
                />
              </TableCell>
              <TableCell>
                <div class="flex flex-col">
                  <span class="font-medium">{{ backup.name }}</span>
                  <span
                    v-if="backup.description"
                    class="text-muted-foreground text-xs"
                    >{{ backup.description }}</span
                  >
                </div>
              </TableCell>
              <TableCell>
                <BackupStatusBadge :state="backup.state" />
              </TableCell>
              <TableCell>
                <BackupProgress
                  :progress="backup.progress"
                  :state="backup.state"
                />
              </TableCell>
              <TableCell>
                <span v-if="backup.file_size">{{ formatFileSize(backup.file_size) }}</span>
                <span
                  v-else
                  class="text-muted-foreground"
                  >—</span
                >
              </TableCell>
              <TableCell>
                <SimpleTooltip
                  class="flex items-center gap-1"
                  :tooltip="backup.recipients.join(', ')"
                >
                  <Icon
                    name="lucide:mail"
                    class="text-muted-foreground"
                  />
                  <span class="text-sm">{{ backup.recipients.length }}</span>
                  <Icon
                    v-if="backup.has_password"
                    name="lucide:lock"
                    class="text-muted-foreground"
                  />
                </SimpleTooltip>
              </TableCell>
              <TableCell>{{ formatDateTime(backup.expires_at) }}</TableCell>
              <TableCell>
                <div class="flex flex-col">
                  <span>{{ formatDateTime(backup.created_at) }}</span>
                  <span
                    v-if="backup.created_by"
                    class="flex items-center gap-1"
                  >
                    <Avatar
                      :name="backup.created_by.name"
                      :avatar="backup.created_by.avatar"
                      size="sm"
                    />{{ backup.created_by.name }}</span
                  >
                </div>
              </TableCell>
              <TableCell>
                <div class="flex justify-end space-x-1">
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{ $t('labels.backups.openMenu') }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem
                        v-if="backup.state === 'active'"
                        @click="$emit('download', backup)"
                      >
                        <Icon
                          name="lucide:download"
                          class="mr-1"
                        />
                        {{ $t('actions.backups.download') }}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator v-if="backup.state === 'active'" />
                      <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @click="handleDelete(backup)"
                      >
                        <Icon
                          name="lucide:trash-2"
                          class="mr-1"
                        />
                        {{ $t('actions.backups.delete') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </TableCell>
            </TableRow>
          </template>
          <TableEmptyRow
            v-else
            :colspan="9"
            :icon="BackupIcon"
            :label="$t('labels.backups.noBackups')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="backups?.meta"
      :meta="backups.meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(val) => (currentPage = val)"
      @update:per-page="(val) => (perPage = val)"
    />
  </div>
</template>
