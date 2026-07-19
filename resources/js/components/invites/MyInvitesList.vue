<script setup lang="ts">
import UsersIcon from '~/assets/images/users.svg?component'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
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

defineProps<{
  invites: InviteResource[]
  isLoading: boolean
  isFetching?: boolean
  meta?: LaravelMeta
  currentPage: number
  perPage: number
  sortBy: { column: string; direction: 'asc' | 'desc' }
  actingInviteId?: string | null
}>()

const emit = defineEmits<{
  accept: [invite: InviteResource]
  decline: [invite: InviteResource]
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
  'update:sortBy': [sort: { column: string; direction: 'asc' | 'desc' }]
}>()

const { alert } = useAlertDialog()
const { t } = useI18n()
const { formatDateTime, formatRelativeTime } = useFormat()

const getStatusVariant = (
  status: InviteStatus
): 'warning' | 'success' | 'destructive' | 'secondary' => {
  switch (status) {
    case InviteStatus.PENDING:
      return 'warning'
    case InviteStatus.ACCEPTED:
      return 'success'
    case InviteStatus.EXPIRED:
      return 'destructive'
    default:
      return 'secondary'
  }
}

const resourceName = (invite: InviteResource) =>
  invite.space?.name || invite.team?.name || t('labels.account.invites.unknownResource')

const handleDecline = async (invite: InviteResource) => {
  const confirmed = await alert.confirm(
    t('labels.account.invites.declineConfirm.message', { name: resourceName(invite) }),
    {
      title: t('labels.account.invites.declineConfirm.title'),
      confirmLabel: t('labels.account.invites.declineConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    emit('decline', invite)
  }
}
</script>

<template>
  <div class="space-y-2">
    <div class="rounded-lg border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('labels.account.invites.columns.resource') }}</TableHead>
            <TableHead>{{ $t('labels.account.invites.columns.invitedBy') }}</TableHead>
            <TableHead>{{ $t('labels.account.invites.columns.role') }}</TableHead>
            <TableHead>{{ $t('labels.account.invites.columns.status') }}</TableHead>
            <TableSortableHead
              :model-value="sortBy"
              column="created_at"
              @update:model-value="(value) => value && emit('update:sortBy', value)"
            >
              {{ $t('labels.account.invites.columns.received') }}
            </TableSortableHead>
            <TableHead class="w-48"></TableHead>
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
            v-else-if="!invites || invites.length === 0"
            :colspan="6"
            :icon="UsersIcon"
            :label="$t('labels.account.invites.empty')"
          />

          <template v-else>
            <TableRow
              v-for="invite in invites"
              :key="invite.id"
            >
              <TableCell>
                <div class="flex flex-col">
                  <span class="font-medium">{{ resourceName(invite) }}</span>
                  <span class="text-muted-foreground text-xs">
                    {{
                      invite.space
                        ? $t('labels.account.invites.resourceType.space')
                        : $t('labels.account.invites.resourceType.team')
                    }}
                  </span>
                </div>
              </TableCell>

              <TableCell class="text-sm">
                {{ invite.inviter?.name || '—' }}
              </TableCell>

              <TableCell>
                {{ invite.role }}
              </TableCell>

              <TableCell>
                <Badge
                  :variant="getStatusVariant(invite.status)"
                  size="sm"
                >
                  {{ $t(`labels.invites.status.${invite.status}`) }}
                </Badge>
              </TableCell>

              <TableCell class="text-muted-foreground text-sm">
                <SimpleTooltip :tooltip="formatDateTime(invite.created_at)">
                  {{ formatRelativeTime(invite.created_at) }}
                </SimpleTooltip>
              </TableCell>

              <TableCell class="text-right">
                <div
                  v-if="invite.status === InviteStatus.PENDING"
                  class="flex justify-end gap-2"
                >
                  <Button
                    size="sm"
                    variant="primary"
                    :loading="actingInviteId === invite.id"
                    @click="emit('accept', invite)"
                  >
                    <Icon name="lucide:check" />
                    {{ $t('labels.account.invites.actions.accept') }}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    :disabled="actingInviteId === invite.id"
                    @click="handleDecline(invite)"
                  >
                    <Icon name="lucide:x" />
                    {{ $t('labels.account.invites.actions.decline') }}
                  </Button>
                </div>
                <SimpleTooltip
                  v-else-if="invite.status === InviteStatus.EXPIRED"
                  :tooltip="formatDateTime(invite.expires_at)"
                >
                  <span class="text-muted-foreground text-sm">
                    {{ $t('labels.account.invites.expiredHint') }}
                  </span>
                </SimpleTooltip>
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
