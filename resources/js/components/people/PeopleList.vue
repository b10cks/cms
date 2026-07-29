<script setup lang="ts">
import UsersIcon from '~/assets/images/users.svg?component'
import Icon from '~/components/Icon.vue'
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
import type { RoleCatalogEntry } from '~/types/authorization'

const props = withDefaults(
  defineProps<{
    people: PersonResource[]
    isLoading: boolean
    isFetching?: boolean
    meta?: LaravelMeta
    currentPage: number
    perPage: number
    sortBy?: { column: string; direction: 'asc' | 'desc' }
    availableRoles?: RoleCatalogEntry[]
    resourceType: 'space' | 'team'
    canManageInvites?: boolean
  }>(),
  {
    sortBy: () => ({ column: 'firstname', direction: 'asc' as const }),
    availableRoles: () => [],
    canManageInvites: false,
  }
)

const emit = defineEmits<{
  updateRole: [userId: string, role: string]
  removeMember: [userId: string]
  resendInvite: [inviteId: string]
  deleteInvite: [inviteId: string]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime, formatRelativeTime } = useFormat()

const editingRole = ref<string | null>(null)
const selected = ref<Map<string, PersonResource>>(new Map())

const roleName = (role: string | null) =>
  role ? (props.availableRoles.find((entry) => entry.key === role)?.name ?? role) : null

const stateVariant = (state: PersonState): 'warning' | 'destructive' | 'success' => {
  switch (state) {
    case 'pending':
      return 'warning'
    case 'expired':
      return 'destructive'
    default:
      return 'success'
  }
}

const expiresInDays = (expiresAt: string | null) => {
  if (!expiresAt) return 0
  return Math.ceil((new Date(expiresAt).getTime() - Date.now()) / (1000 * 60 * 60 * 24))
}

const isSelectable = (person: PersonResource) =>
  person.kind === 'invite' ? props.canManageInvites : person.can_remove

const selectablePeople = computed(() => props.people.filter(isSelectable))
const selectionCount = computed(() => selected.value.size)
const isAllSelected = computed(
  () => selectionCount.value > 0 && selectablePeople.value.length === selectionCount.value
)
const selectedMembers = computed(() =>
  Array.from(selected.value.values()).filter((person) => person.kind === 'member')
)
const selectedInvites = computed(() =>
  Array.from(selected.value.values()).filter((person) => person.kind === 'invite')
)

const isSelected = (person: PersonResource) => selected.value.has(person.id)

const toggleSelect = (person: PersonResource, checked: boolean) => {
  if (!isSelectable(person)) return
  if (checked) {
    selected.value.set(person.id, person)
  } else {
    selected.value.delete(person.id)
  }
}

const toggleSelectAll = (checked: boolean) => {
  if (checked) {
    selectablePeople.value.forEach((person) => selected.value.set(person.id, person))
  } else {
    selected.value.clear()
  }
}

const clearSelection = () => selected.value.clear()

const handleRoleChange = (person: PersonResource, role: string) => {
  if (person.user_id && role && role !== person.role) {
    emit('updateRole', person.user_id, role)
  }
  editingRole.value = null
}

const handleRemoveMember = async (person: PersonResource) => {
  if (!person.user_id) return
  const confirmed = await alert.confirm(
    t('labels.people.removeConfirm.message', { name: person.user?.name ?? person.email }),
    {
      title: t('labels.people.removeConfirm.title'),
      confirmLabel: t('labels.people.removeConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) emit('removeMember', person.user_id)
}

const handleDeleteInvite = async (person: PersonResource) => {
  if (!person.invite_id) return
  const confirmed = await alert.confirm(
    t('labels.invites.deleteConfirm.message', { email: person.email }),
    {
      title: t('labels.invites.deleteConfirm.title'),
      confirmLabel: t('labels.invites.deleteConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) emit('deleteInvite', person.invite_id)
}

const handleResendInvite = (person: PersonResource) => {
  if (person.invite_id) emit('resendInvite', person.invite_id)
}

const handleBulkRemove = async () => {
  const members = selectedMembers.value.filter((person) => person.can_remove && person.user_id)
  if (members.length === 0) return
  const confirmed = await alert.confirm(
    t('labels.people.removeConfirm.bulkMessage', { count: members.length }),
    {
      title: t('labels.people.removeConfirm.bulkTitle'),
      confirmLabel: t('labels.people.removeConfirm.bulkConfirmLabel', { count: members.length }),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    members.forEach((person) => emit('removeMember', person.user_id as string))
    clearSelection()
  }
}

const handleBulkResend = async () => {
  const invites = selectedInvites.value
  if (invites.length === 0) return
  const confirmed = await alert.confirm(
    t('labels.invites.actions.bulkResend', { count: invites.length }),
    {
      title: t('labels.invites.resendConfirm.title'),
      confirmLabel: t('labels.invites.resendConfirm.confirmLabel', { count: invites.length }),
      cancelLabel: t('actions.cancel'),
    }
  )
  if (confirmed) {
    invites.forEach((person) => emit('resendInvite', person.invite_id as string))
    clearSelection()
  }
}

const handleBulkDelete = async () => {
  const invites = selectedInvites.value
  if (invites.length === 0) return
  const confirmed = await alert.confirm(
    t('labels.invites.actions.bulkDelete', { count: invites.length }),
    {
      title: t('labels.invites.deleteConfirm.bulkTitle'),
      confirmLabel: t('labels.invites.deleteConfirm.bulkConfirmLabel', { count: invites.length }),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    invites.forEach((person) => emit('deleteInvite', person.invite_id as string))
    clearSelection()
  }
}
</script>

<template>
  <div class="space-y-2">
    <div
      v-if="selectionCount > 0"
      class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4"
    >
      <Badge variant="secondary">
        {{ $t('labels.selectionCount', { count: selectionCount }) }}
      </Badge>
      <div class="flex flex-col gap-2 sm:flex-row">
        <Button
          v-if="selectedMembers.length > 0"
          size="sm"
          variant="destructive"
          @click="handleBulkRemove"
        >
          <Icon name="lucide:user-minus" />
          {{ $t('labels.people.actions.remove', { count: selectedMembers.length }) }}
        </Button>
        <Button
          v-if="selectedInvites.length > 0 && canManageInvites"
          size="sm"
          variant="outline"
          @click="handleBulkResend"
        >
          <Icon name="lucide:send" />
          {{ $t('labels.people.actions.resend', { count: selectedInvites.length }) }}
        </Button>
        <Button
          v-if="selectedInvites.length > 0 && canManageInvites"
          size="sm"
          variant="destructive"
          @click="handleBulkDelete"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('labels.people.actions.delete', { count: selectedInvites.length }) }}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          @click="clearSelection"
        >
          {{ $t('labels.people.selection.clear') }}
        </Button>
      </div>
    </div>

    <div class="rounded-lg border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-12">
              <Checkbox
                :model-value="isAllSelected ? true : selectionCount > 0 ? 'indeterminate' : false"
                :disabled="selectablePeople.length === 0"
                :aria-label="$t('labels.people.selection.selectAll')"
                @update:model-value="(value) => toggleSelectAll(value === true)"
              />
            </TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="firstname"
              @update:model-value="(value) => value && emit('update:sortBy', value)"
            >
              {{ $t('labels.people.columns.member') }}
            </TableSortableHead>
            <TableHead>{{ $t('labels.people.columns.role') }}</TableHead>
            <TableHead>{{ $t('labels.people.columns.status') }}</TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="joined_at"
              @update:model-value="(value) => value && emit('update:sortBy', value)"
            >
              {{ $t('labels.people.columns.joined') }}
            </TableSortableHead>
            <TableHead class="w-24" />
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
          <TableEmptyRow
            v-else-if="!people || people.length === 0"
            :colspan="6"
            :icon="UsersIcon"
            :label="$t('labels.people.empty')"
          />

          <template v-else>
            <TableRow
              v-for="person in people"
              :key="person.id"
              :class="{ 'bg-muted/30': isSelected(person) }"
            >
              <TableCell class="w-12">
                <Checkbox
                  :disabled="!isSelectable(person)"
                  :model-value="isSelected(person)"
                  :aria-label="
                    $t('labels.people.selection.selectRow', {
                      name: person.user?.name ?? person.email,
                    })
                  "
                  @update:model-value="(value) => toggleSelect(person, value === true)"
                />
              </TableCell>

              <TableCell>
                <div class="flex items-center gap-3">
                  <Avatar
                    :name="person.user?.name ?? person.email"
                    :avatar="person.user?.avatar"
                    size="sm"
                    :class="person.kind === 'invite' ? 'opacity-60 grayscale' : ''"
                  />
                  <div class="min-w-0">
                    <div class="truncate font-medium">
                      {{ person.user?.name ?? person.email }}
                    </div>
                    <div class="text-muted-foreground truncate text-sm">
                      <template v-if="person.kind === 'invite'">
                        {{ $t('labels.people.pendingInvite') }}
                      </template>
                      <template v-else>
                        {{ person.email }}
                      </template>
                    </div>
                    <div
                      v-if="
                        resourceType === 'team' &&
                        person.membership_origin === 'space' &&
                        person.space_memberships.length > 0
                      "
                      class="mt-1 flex flex-wrap items-center gap-1"
                    >
                      <Badge
                        variant="secondary"
                        size="sm"
                      >
                        {{ $t('labels.teamMembers.spaceOnly') }}
                      </Badge>
                      <span class="text-muted-foreground text-xs">
                        {{
                          person.space_memberships
                            .map((membership) => membership.space.name)
                            .join(', ')
                        }}
                      </span>
                    </div>
                  </div>
                </div>
              </TableCell>

              <TableCell>
                <div
                  v-if="editingRole === person.id"
                  class="w-32"
                >
                  <Select
                    :model-value="person.role ?? undefined"
                    @update:model-value="(value) => handleRoleChange(person, value as string)"
                  >
                    <SelectTrigger class="h-8">
                      <SelectValue :placeholder="$t('labels.people.assignRole')" />
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
                <button
                  v-else-if="person.kind === 'member' && person.can_assign_role"
                  type="button"
                  class="cursor-pointer rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                  :aria-label="$t('labels.people.tooltip.editRole')"
                  @click="editingRole = person.id"
                >
                  <Badge
                    variant="secondary"
                    size="sm"
                  >
                    {{ roleName(person.role) ?? $t('labels.people.assignRole') }}
                  </Badge>
                </button>
                <Badge
                  v-else-if="person.role || person.kind === 'member'"
                  variant="secondary"
                  size="sm"
                >
                  {{ roleName(person.role) ?? $t('labels.people.assignRole') }}
                </Badge>
                <span
                  v-else
                  class="text-muted-foreground text-sm"
                >
                  {{ roleName(person.role) ?? '—' }}
                </span>
              </TableCell>

              <TableCell>
                <Badge
                  :variant="stateVariant(person.state)"
                  size="sm"
                >
                  {{ $t(`labels.people.state.${person.state}`) }}
                </Badge>
              </TableCell>

              <TableCell class="text-muted-foreground text-sm">
                <template v-if="person.kind === 'member' && person.joined_at">
                  <SimpleTooltip :tooltip="formatDateTime(person.joined_at)">
                    {{ formatRelativeTime(person.joined_at) }}
                  </SimpleTooltip>
                </template>
                <template v-else-if="person.kind === 'invite'">
                  <SimpleTooltip
                    v-if="person.state === 'pending' && person.expires_at"
                    :tooltip="formatDateTime(person.expires_at)"
                  >
                    {{
                      $t('labels.invites.tooltip.expiresInDays', {
                        count: expiresInDays(person.expires_at),
                      })
                    }}
                  </SimpleTooltip>
                  <SimpleTooltip
                    v-else-if="person.invited_at"
                    :tooltip="formatDateTime(person.invited_at)"
                  >
                    {{ formatRelativeTime(person.invited_at) }}
                  </SimpleTooltip>
                </template>
              </TableCell>

              <TableCell class="text-right">
                <div class="flex justify-end gap-1">
                  <template v-if="person.kind === 'member'">
                    <SimpleTooltip
                      v-if="person.can_assign_role"
                      :tooltip="$t('labels.people.tooltip.editRole')"
                    >
                      <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="$t('labels.people.tooltip.editRole')"
                        @click="editingRole = person.id"
                      >
                        <Icon name="lucide:shield" />
                      </Button>
                    </SimpleTooltip>
                    <SimpleTooltip
                      v-if="person.can_remove"
                      :tooltip="$t('labels.people.tooltip.remove')"
                    >
                      <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="$t('labels.people.tooltip.remove')"
                        @click="handleRemoveMember(person)"
                      >
                        <Icon
                          name="lucide:user-minus"
                          class="text-destructive"
                        />
                      </Button>
                    </SimpleTooltip>
                  </template>
                  <template v-else>
                    <SimpleTooltip
                      v-if="canManageInvites"
                      :tooltip="$t('labels.invites.tooltip.resend')"
                    >
                      <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="$t('labels.invites.tooltip.resend')"
                        @click="handleResendInvite(person)"
                      >
                        <Icon name="lucide:send" />
                      </Button>
                    </SimpleTooltip>
                    <SimpleTooltip
                      v-if="canManageInvites"
                      :tooltip="$t('labels.invites.tooltip.delete')"
                    >
                      <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="$t('labels.invites.tooltip.delete')"
                        @click="handleDeleteInvite(person)"
                      >
                        <Icon
                          name="lucide:trash-2"
                          class="text-destructive"
                        />
                      </Button>
                    </SimpleTooltip>
                  </template>
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
