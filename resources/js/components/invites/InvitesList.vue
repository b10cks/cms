<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import UsersIcon from '~/assets/images/users.svg?component'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
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
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { LaravelMeta } from '~/types'
import type { InviteResource } from '~/types/invites'
import { InviteStatus } from '~/types/invites.d'
import TableEmptyRow from '../ui/TableEmptyRow.vue'

const props = withDefaults(
  defineProps<{
    invites: InviteResource[]
    isLoading: boolean
    meta?: LaravelMeta
    currentPage: number
    perPage: number
    sortBy?: { column: string; direction: 'asc' | 'desc' }
  }>(),
  {
    sortBy: () => ({
      column: 'created_at',
      direction: 'desc' as const,
    }),
  }
)

const emit = defineEmits<{
  delete: [inviteId: string]
  resend: [inviteId: string]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
  'update:filters': [filters: Record<string, unknown>]
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime, formatRelativeTime } = useFormat()

const inviteStatusOptions = computed(() => [
  { value: InviteStatus.PENDING, label: t('labels.invites.status.pending') },
  { value: InviteStatus.ACCEPTED, label: t('labels.invites.status.accepted') },
  { value: InviteStatus.EXPIRED, label: t('labels.invites.status.expired') },
])

const inviteFilters = computed(() => [
  {
    id: 'email',
    label: t('labels.invites.filters.email'),
    operators: [
      { value: 'like' as const, label: t('labels.invites.operators.contains') },
      { value: '^like' as const, label: t('labels.invites.operators.startsWith') },
      { value: 'like$' as const, label: t('labels.invites.operators.endsWith') },
      { value: 'eq' as const, label: t('labels.invites.operators.equals') },
    ],
  },
  {
    id: 'role',
    label: t('labels.invites.filters.role'),
    operators: [{ value: 'eq' as const, label: t('labels.invites.operators.equals') }],
    items: [
      { value: 'owner', label: t('labels.invites.filters.roles.owner') },
      { value: 'admin', label: t('labels.invites.filters.roles.admin') },
      { value: 'editor', label: t('labels.invites.filters.roles.editor') },
      { value: 'member', label: t('labels.invites.filters.roles.member') },
      { value: 'viewer', label: t('labels.invites.filters.roles.viewer') },
    ],
  },
  {
    id: 'status',
    label: t('labels.invites.filters.status'),
    operators: [{ value: 'eq' as const, label: t('labels.invites.operators.equals') }],
    items: inviteStatusOptions.value,
  },
])

const sortOptions = computed(() => [
  { value: 'email', label: t('labels.invites.sort.email') },
  { value: 'role', label: t('labels.invites.sort.role') },
  { value: 'created_at', label: t('labels.invites.sort.created_at') },
  { value: 'expires_at', label: t('labels.invites.sort.expires_at') },
  { value: 'accepted_at', label: t('labels.invites.sort.accepted_at') },
])

const filters = ref<Record<string, unknown>>({})
const selectedInvites = ref<Map<string, InviteResource>>(new Map())

const getStatusVariant = (
  status: InviteStatus
): 'warning' | 'success' | 'destructive' | 'outline' => {
  switch (status) {
    case InviteStatus.PENDING:
      return 'warning'
    case InviteStatus.ACCEPTED:
      return 'success'
    case InviteStatus.EXPIRED:
      return 'destructive'
    default:
      return 'outline'
  }
}

const getExpiresInDays = (expiresAt: string) => {
  const now = new Date()
  const expires = new Date(expiresAt)
  const days = Math.ceil((expires.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
  return days
}

const handleDelete = async (invite: InviteResource, force: boolean = false) => {
  const confirmed =
    force ||
    (await alert.confirm(t('labels.invites.deleteConfirm.message', { email: invite.email }), {
      title: t('labels.invites.deleteConfirm.title'),
      confirmLabel: t('labels.invites.deleteConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }))
  if (confirmed) {
    emit('delete', invite.id)
  }
}

const handleResend = (invite: InviteResource) => {
  emit('resend', invite.id)
}

const selectionCount = computed(() => selectedInvites.value.size)
const isAllSelected = computed(() => {
  return selectionCount.value > 0 && props.invites.length === selectionCount.value
})

const handleSelectAll = (checked: boolean) => {
  if (checked) {
    props.invites.forEach((invite) => {
      selectedInvites.value.set(invite.id, invite)
    })
  } else {
    clearSelection()
  }
}

const handleInviteSelect = (invite: InviteResource, selected: boolean) => {
  if (selected) {
    selectedInvites.value.set(invite.id, invite)
  } else {
    selectedInvites.value.delete(invite.id)
  }
}

const clearSelection = () => {
  selectedInvites.value.clear()
}

const isInviteSelected = (invite: InviteResource) => {
  return selectedInvites.value.has(invite.id)
}

const handleBulkDelete = async () => {
  const confirmed = await alert.confirm(
    t('labels.invites.actions.bulkDelete', { count: selectionCount.value }),
    {
      title: t('labels.invites.deleteConfirm.bulkTitle'),
      confirmLabel: t('labels.invites.deleteConfirm.bulkConfirmLabel', {
        count: selectionCount.value,
      }),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedInvites.value.keys())
    for (const id of selectedIds) {
      emit('delete', id)
    }
    clearSelection()
  }
}

const handleBulkResend = async () => {
  const confirmed = await alert.confirm(
    t('labels.invites.actions.bulkResend', { count: selectionCount.value }),
    {
      title: t('labels.invites.resendConfirm.title'),
      confirmLabel: t('labels.invites.resendConfirm.confirmLabel', {
        count: selectionCount.value,
      }),
      cancelLabel: t('actions.cancel'),
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedInvites.value.keys())
    for (const id of selectedIds) {
      emit('resend', id)
    }
    clearSelection()
  }
}
</script>

<template>
  <div class="space-y-2">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-2 sm:flex-row">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="inviteFilters"
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
          $t('labels.invites.selection.selected', { count: selectionCount })
        }}</Badge>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row">
        <Button
          size="sm"
          variant="outline"
          @click="handleBulkResend"
        >
          <Icon name="lucide:send" />
          {{ $t('labels.invites.actions.resend', { count: selectionCount }) }}
        </Button>
        <Button
          size="sm"
          variant="destructive"
          @click="handleBulkDelete"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('labels.invites.actions.delete', { count: selectionCount }) }}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          @click="clearSelection"
        >
          {{ $t('labels.invites.selection.clear') }}
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
              column="email"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.invites.columns.email') }}
            </TableSortableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="role"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.invites.columns.role') }}
            </TableSortableHead>
            <TableHead column="status"> {{ $t('labels.invites.columns.status') }} </TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="created_at"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.invites.columns.created') }}
            </TableSortableHead>
            <TableHead class="hidden sm:table-cell">{{
              $t('labels.invites.columns.details')
            }}</TableHead>
            <TableHead class="w-24"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="7"
          />
          <TableEmptyRow
            v-else-if="!invites || invites.length === 0"
            :colspan="7"
            :icon="UsersIcon"
            :label="$t('labels.invites.empty')"
          />

          <template v-else>
            <TableRow
              v-for="invite in invites"
              :key="invite.id"
              :class="{ 'bg-muted/30': isInviteSelected(invite) }"
            >
              <TableCell class="w-12">
                <Checkbox
                  :checked="isInviteSelected(invite)"
                  @update:checked="(checked) => handleInviteSelect(invite, checked)"
                />
              </TableCell>

              <TableCell>
                {{ invite.email }}
              </TableCell>

              <TableCell>
                {{ invite.role }}
              </TableCell>

              <TableCell>
                <Badge
                  :variant="getStatusVariant(invite.status)"
                  size="sm"
                >
                  {{ invite.status }}
                </Badge>
              </TableCell>

              <TableCell class="text-muted-foreground text-sm">
                <SimpleTooltip :tooltip="formatDateTime(invite.created_at)">
                  {{ formatRelativeTime(invite.created_at) }}
                </SimpleTooltip>
              </TableCell>

              <TableCell class="hidden sm:table-cell">
                <SimpleTooltip
                  v-if="invite.status === InviteStatus.ACCEPTED"
                  :tooltip="formatDateTime(invite.accepted_at)"
                >
                  {{ $t('labels.invites.tooltip.acceptedOn') }}
                  {{ new Date(invite.accepted_at).toLocaleDateString() }}
                </SimpleTooltip>
                <SimpleTooltip
                  v-else
                  :tooltip="formatDateTime(invite.expires_at)"
                >
                  {{ $t('labels.invites.tooltip.expiresIn') }}
                  {{ getExpiresInDays(invite.expires_at) }} days
                </SimpleTooltip>
              </TableCell>

              <TableCell class="text-right">
                <div class="flex justify-end gap-1">
                  <Button
                    v-if="invite.status === InviteStatus.PENDING"
                    size="icon"
                    variant="ghost"
                    :title="$t('labels.invites.tooltip.resend')"
                    @click="handleResend(invite)"
                  >
                    <Icon name="lucide:send" />
                  </Button>
                  <Button
                    v-if="invite.status !== InviteStatus.ACCEPTED"
                    size="icon"
                    variant="ghost"
                    :title="$t('labels.invites.tooltip.delete')"
                    @click="handleDelete(invite)"
                  >
                    <Icon
                      name="lucide:trash-2"
                      class="text-destructive"
                    />
                  </Button>
                  <Button
                    v-if="invite.status === InviteStatus.ACCEPTED"
                    size="icon"
                    variant="ghost"
                    @click="handleDelete(invite, true)"
                  >
                    <Icon name="lucide:archive" />
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
