<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import UsersIcon from '~/assets/images/users.svg?component'
import SearchFilter from '~/components/SearchFilter.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
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
import type { RoleCatalogEntry } from '~/types/authorization'
import type { SpaceMemberResource } from '~/types/spaces'
import TableEmptyRow from '../ui/TableEmptyRow.vue'

const props = withDefaults(
  defineProps<{
    members: SpaceMemberResource[]
    isLoading: boolean
    meta?: LaravelMeta
    currentPage: number
    perPage: number
    sortBy?: { column: string; direction: 'asc' | 'desc' }
    availableRoles?: RoleCatalogEntry[]
  }>(),
  {
    sortBy: () => ({
      column: 'firstname',
      direction: 'asc' as const,
    }),
    availableRoles: () => [],
  }
)

const emit = defineEmits<{
  updateRole: [userId: string, role: string]
  remove: [userId: string]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
  'update:filters': [filters: Record<string, unknown>]
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatRelativeTime } = useFormat()

const memberFilters = computed(() => [
  {
    id: 'name',
    label: t('labels.spaceMembers.filters.name'),
    operators: [
      { value: 'like' as const, label: t('labels.spaceMembers.operators.contains') },
      { value: '^like' as const, label: t('labels.spaceMembers.operators.startsWith') },
      { value: 'like$' as const, label: t('labels.spaceMembers.operators.endsWith') },
      { value: 'eq' as const, label: t('labels.spaceMembers.operators.equals') },
    ],
  },
  {
    id: 'email',
    label: t('labels.spaceMembers.filters.email'),
    operators: [
      { value: 'like' as const, label: t('labels.spaceMembers.operators.contains') },
      { value: '^like' as const, label: t('labels.spaceMembers.operators.startsWith') },
      { value: 'like$' as const, label: t('labels.spaceMembers.operators.endsWith') },
      { value: 'eq' as const, label: t('labels.spaceMembers.operators.equals') },
    ],
  },
  {
    id: 'role',
    label: t('labels.spaceMembers.filters.role'),
    operators: [{ value: 'eq' as const, label: t('labels.spaceMembers.operators.equals') }],
    items: props.availableRoles.map((role) => ({
      value: role.key,
      label: role.name,
    })),
  },
])

const sortOptions = computed(() => [
  { value: 'firstname', label: t('labels.spaceMembers.sort.firstname') },
  { value: 'lastname', label: t('labels.spaceMembers.sort.lastname') },
  { value: 'email', label: t('labels.spaceMembers.sort.email') },
  { value: 'created_at', label: t('labels.spaceMembers.sort.created_at') },
  { value: 'last_login_at', label: t('labels.spaceMembers.sort.last_login_at') },
])

const filters = ref<Record<string, unknown>>({})
const selectedMembers = ref<Map<string, SpaceMemberResource>>(new Map())
const editingRole = ref<string | null>(null)

const selectionCount = computed(() => selectedMembers.value.size)
const removableMembers = computed(() => props.members.filter((member) => member.can_remove))
const isAllSelected = computed(() => {
  return selectionCount.value > 0 && removableMembers.value.length === selectionCount.value
})

const handleSelectAll = (checked: boolean) => {
  if (checked) {
    removableMembers.value.forEach((member) => {
      selectedMembers.value.set(member.id, member)
    })
  } else {
    clearSelection()
  }
}

const handleMemberSelect = (member: SpaceMemberResource, selected: boolean) => {
  if (!member.can_remove) {
    return
  }

  if (selected) {
    selectedMembers.value.set(member.id, member)
  } else {
    selectedMembers.value.delete(member.id)
  }
}

const clearSelection = () => {
  selectedMembers.value.clear()
}

const isMemberSelected = (member: SpaceMemberResource) => {
  return selectedMembers.value.has(member.id)
}

const handleRoleChange = (member: SpaceMemberResource, newRole: string) => {
  if (newRole && newRole !== member.role) {
    emit('updateRole', member.user.id, newRole)
  }
  editingRole.value = null
}

const handleRemove = async (member: SpaceMemberResource) => {
  const confirmed = await alert.confirm(
    t('labels.spaceMembers.removeConfirm.message', {
      firstname: member.user.firstname,
      lastname: member.user.lastname,
    }),
    {
      title: t('labels.spaceMembers.removeConfirm.title'),
      confirmLabel: t('labels.spaceMembers.removeConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    if (member.can_remove) {
      emit('remove', member.user.id)
    }
  }
}

const handleBulkRemove = async () => {
  const confirmed = await alert.confirm(
    t('labels.spaceMembers.removeConfirm.bulkMessage', { count: selectionCount.value }),
    {
      title: t('labels.spaceMembers.removeConfirm.bulkTitle'),
      confirmLabel: t('labels.spaceMembers.removeConfirm.bulkConfirmLabel', {
        count: selectionCount.value,
      }),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedMembers.value.keys())
    for (const id of selectedIds) {
      emit('remove', id)
    }
    clearSelection()
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-2 sm:flex-row">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="memberFilters"
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
          $t('labels.spaceMembers.selection.selected', { count: selectionCount })
        }}</Badge>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row">
        <Button
          size="sm"
          variant="destructive"
          @click="handleBulkRemove"
        >
          <Icon name="lucide:user-minus" />
          {{ $t('labels.spaceMembers.actions.remove', { count: selectionCount }) }}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          @click="clearSelection"
        >
          {{ $t('labels.spaceMembers.selection.clear') }}
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
                :disabled="removableMembers.length === 0"
                @update:checked="handleSelectAll"
              />
            </TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="firstname"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.spaceMembers.columns.member') }}
            </TableSortableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="email"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.spaceMembers.columns.email') }}
            </TableSortableHead>
            <TableHead>{{ $t('labels.spaceMembers.columns.role') }}</TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="created_at"
              @update:model-value="(value) => emit('update:sortBy', value)"
            >
              {{ $t('labels.spaceMembers.columns.joined') }}
            </TableSortableHead>
            <TableHead class="w-24"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="6"
          />
          <TableEmptyRow
            v-else-if="!members || members.length === 0"
            :colspan="6"
            :icon="UsersIcon"
            :label="$t('labels.spaceMembers.empty')"
          />

          <template v-else>
            <TableRow
              v-for="member in members"
              :key="member.id"
              :class="{ 'bg-muted/30': isMemberSelected(member) }"
            >
              <TableCell class="w-12">
                <Checkbox
                  :disabled="!member.can_remove"
                  :checked="isMemberSelected(member)"
                  @update:checked="(checked: boolean) => handleMemberSelect(member, checked)"
                />
              </TableCell>

              <TableCell>
                <div class="flex items-center gap-3">
                  <Avatar
                    :name="member.user.name"
                    :avatar="member.user.avatar"
                    size="sm"
                  />
                  <div class="font-medium">
                    {{ member.user.firstname }} {{ member.user.lastname }}
                  </div>
                </div>
              </TableCell>

              <TableCell class="text-muted-foreground text-sm">
                {{ member.user.email }}
              </TableCell>

              <TableCell>
                <div
                  v-if="editingRole === member.id"
                  class="w-32"
                >
                  <Select
                    :model-value="member.role ?? undefined"
                    @update:model-value="(value) => handleRoleChange(member, value as string)"
                  >
                    <SelectTrigger class="h-8">
                      <SelectValue :placeholder="$t('labels.spaceMembers.assignRole')" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem
                        v-for="role in availableRoles"
                        :key="role.key"
                        :value="role.key"
                      >
                        {{ role.name }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <Badge
                  v-else
                  variant="outline"
                  size="sm"
                  :class="member.can_assign_space_role ? 'cursor-pointer' : ''"
                  @click="member.can_assign_space_role ? (editingRole = member.id) : undefined"
                >
                  {{
                    member.role
                      ? availableRoles.find((role) => role.key === member.role)?.name || member.role
                      : $t('labels.spaceMembers.assignRole')
                  }}
                </Badge>
              </TableCell>

              <TableCell class="text-muted-foreground text-sm">
                {{ formatRelativeTime(member.joined_at) }}
              </TableCell>

              <TableCell class="text-right">
                <div class="flex justify-end gap-1">
                  <Button
                    v-if="member.can_assign_space_role"
                    size="icon"
                    variant="ghost"
                    :title="$t('labels.spaceMembers.tooltip.editRole')"
                    @click="editingRole = member.id"
                  >
                    <Icon name="lucide:shield" />
                  </Button>
                  <Button
                    v-if="member.can_remove"
                    size="icon"
                    variant="ghost"
                    :title="$t('labels.spaceMembers.tooltip.remove')"
                    @click="handleRemove(member)"
                  >
                    <Icon
                      name="lucide:user-minus"
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
