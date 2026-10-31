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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { ACCESS_SOURCE_ICONS, summarizeAccess } from '~/lib/people-access'
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
    /** Roles of the other scope (space roles on a team, team roles on a space), only to name them. */
    otherRoles?: RoleCatalogEntry[]
    /** Team or space name, for pending invites ("Editor on Campaigns"). */
    resourceName?: string
    /** Spaces a team role reaches. */
    spaceCount?: number
  }>(),
  {
    sortBy: () => ({ column: 'firstname', direction: 'asc' as const }),
    availableRoles: () => [],
    canManageInvites: false,
    otherRoles: () => [],
    resourceName: '',
    spaceCount: 0,
  }
)

const emit = defineEmits<{
  updateRole: [userId: string, role: string]
  removeMember: [userId: string]
  resendInvite: [inviteId: string]
  deleteInvite: [inviteId: string]
  setExpiry: [person: PersonResource]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDate, formatDateTime, formatRelativeTime } = useFormat()

const editingRole = ref<string | null>(null)
const selected = ref<Map<string, PersonResource>>(new Map())

const roleName = (role: string | null) =>
  role ? (props.availableRoles.find((entry) => entry.key === role)?.name ?? role) : null

const anyRoleName = (role: string | null) =>
  role
    ? ([...props.availableRoles, ...props.otherRoles].find((entry) => entry.key === role)?.name ?? role)
    : ''

const access = (person: PersonResource) => {
  const summary = summarizeAccess(person, {
    resourceType: props.resourceType,
    resourceName: props.resourceName,
    spaceCount: props.spaceCount,
    roleName: anyRoleName,
  })
  return {
    icon: ACCESS_SOURCE_ICONS[summary.source],
    source: t(`labels.teamWorkspace.access.sources.${summary.source}`),
    label: t(`labels.teamWorkspace.access.${summary.key}`, summary.params, Number(summary.params.count ?? 1)),
  }
}

/** Only a person's own (direct) membership carries an end date that can be edited here. */
const hasDirectMembership = (person: PersonResource) =>
  person.kind === 'member' &&
  person.membership_origin === (props.resourceType === 'team' ? 'team' : 'space')

const isExpiringSoon = (date: string) =>
  new Date(date).getTime() - Date.now() < 14 * 24 * 60 * 60 * 1000

const SOURCES = ['invite', 'saml', 'owner', 'direct']
const sourceLabel = (source: string | null) =>
  source && SOURCES.includes(source) ? t(`labels.teamWorkspace.people.sources.${source}`) : ''

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

    <div class="rounded-lg border border-border">
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
              {{ $t('labels.teamWorkspace.people.columns.name') }}
            </TableSortableHead>
            <TableHead>
              {{
                resourceType === 'team'
                  ? $t('labels.teamWorkspace.people.columns.teamRole')
                  : $t('labels.people.columns.role')
              }}
            </TableHead>
            <TableHead>{{ $t('labels.teamWorkspace.people.columns.access') }}</TableHead>
            <TableHead>{{ $t('labels.teamWorkspace.people.columns.source') }}</TableHead>
            <TableHead>{{ $t('labels.teamWorkspace.people.columns.lastActive') }}</TableHead>
            <TableHead class="w-12" />
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
            :colspan="7"
          />
          <TableEmptyRow
            v-else-if="!people || people.length === 0"
            :colspan="7"
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
                    :class="['size-8 rounded-full', person.kind === 'invite' ? 'opacity-60 grayscale' : '']"
                  />
                  <div class="min-w-0">
                    <div class="truncate font-medium text-primary">
                      {{ person.user?.name ?? person.email }}
                    </div>
                    <div
                      v-if="person.user"
                      class="truncate text-sm text-muted"
                    >
                      {{ person.email }}
                    </div>
                  </div>
                </div>
              </TableCell>

              <TableCell>
                <div
                  v-if="editingRole === person.id"
                  class="w-36"
                >
                  <Select
                    :model-value="person.role ?? undefined"
                    default-open
                    @update:model-value="(value) => handleRoleChange(person, value as string)"
                    @update:open="(open) => !open && (editingRole = null)"
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
                <Badge
                  v-else-if="person.kind === 'invite'"
                  variant="warning"
                  size="sm"
                >
                  {{ $t('labels.teamWorkspace.people.pending') }}
                </Badge>
                <Badge
                  v-else-if="person.role"
                  type="outline"
                  size="sm"
                >
                  {{ roleName(person.role) }}
                </Badge>
                <Badge
                  v-else-if="person.membership_origin === 'inherited' && person.inherited_role"
                  type="outline"
                  size="sm"
                  class="border-dashed text-muted!"
                >
                  {{ anyRoleName(person.inherited_role) }}
                </Badge>
                <span
                  v-else
                  class="text-sm text-muted"
                >
                  {{ $t('labels.teamWorkspace.people.noRole') }}
                </span>
              </TableCell>

              <TableCell>
                <div class="flex min-w-0 items-center gap-2 text-sm">
                  <SimpleTooltip :tooltip="access(person).source">
                    <Icon
                      :name="access(person).icon"
                      class="shrink-0 text-muted"
                      :aria-label="access(person).source"
                    />
                  </SimpleTooltip>
                  <span class="truncate">{{ access(person).label }}</span>
                  <SimpleTooltip
                    v-if="person.access_expires_at"
                    :tooltip="formatDateTime(person.access_expires_at)"
                  >
                    <Badge
                      :variant="isExpiringSoon(person.access_expires_at) ? 'warning' : 'secondary'"
                      size="sm"
                      class="shrink-0 gap-1"
                    >
                      <Icon name="lucide:clock" />
                      {{ $t('labels.teamWorkspace.access.until', { date: formatDate(person.access_expires_at) }) }}
                    </Badge>
                  </SimpleTooltip>
                </div>
              </TableCell>

              <TableCell class="text-sm text-muted">
                {{ sourceLabel(person.source) }}
              </TableCell>

              <TableCell class="text-sm text-muted">
                <SimpleTooltip
                  v-if="person.kind === 'invite' && person.invited_at"
                  :tooltip="
                    person.state === 'expired' || !person.expires_at
                      ? formatDateTime(person.invited_at)
                      : $t('labels.invites.tooltip.expiresInDays', expiresInDays(person.expires_at))
                  "
                >
                  {{
                    person.state === 'expired'
                      ? $t('labels.people.state.expired')
                      : $t('labels.teamWorkspace.people.invited', {
                          time: formatRelativeTime(person.invited_at),
                        })
                  }}
                </SimpleTooltip>
                <SimpleTooltip
                  v-else-if="person.last_active_at"
                  :tooltip="formatDateTime(person.last_active_at)"
                >
                  {{ formatRelativeTime(person.last_active_at) }}
                </SimpleTooltip>
                <template v-else-if="person.kind === 'member'">
                  {{ $t('labels.teamWorkspace.people.never') }}
                </template>
              </TableCell>

              <TableCell class="text-right">
                <DropdownMenu
                  v-if="
                    person.kind === 'member'
                      ? person.can_assign_role || person.can_remove
                      : canManageInvites
                  "
                >
                  <DropdownMenuTrigger as-child>
                    <Button
                      size="icon"
                      variant="ghost"
                      :aria-label="
                        $t('labels.teamWorkspace.people.menu', {
                          name: person.user?.name ?? person.email,
                        })
                      "
                    >
                      <Icon name="lucide:ellipsis" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <template v-if="person.kind === 'member'">
                      <DropdownMenuItem
                        v-if="person.can_assign_role"
                        @select="editingRole = person.id"
                      >
                        <Icon name="lucide:shield" />
                        {{ $t('labels.people.tooltip.editRole') }}
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        v-if="person.can_remove && hasDirectMembership(person)"
                        @select="emit('setExpiry', person)"
                      >
                        <Icon name="lucide:clock" />
                        {{ $t('labels.teamWorkspace.expiry.action') }}
                      </DropdownMenuItem>
                      <template v-if="person.can_remove">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          class="text-destructive"
                          @select="handleRemoveMember(person)"
                        >
                          <Icon name="lucide:user-minus" />
                          {{ $t('labels.people.tooltip.remove') }}
                        </DropdownMenuItem>
                      </template>
                    </template>
                    <template v-else>
                      <DropdownMenuItem @select="handleResendInvite(person)">
                        <Icon name="lucide:send" />
                        {{ $t('labels.invites.tooltip.resend') }}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        class="text-destructive"
                        @select="handleDeleteInvite(person)"
                      >
                        <Icon name="lucide:trash-2" />
                        {{ $t('labels.invites.tooltip.delete') }}
                      </DropdownMenuItem>
                    </template>
                  </DropdownMenuContent>
                </DropdownMenu>
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
