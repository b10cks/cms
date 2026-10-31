<script setup lang="ts">
import UsersIcon from '~/assets/images/users.svg?component'
import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { SelectField } from '~/components/ui/form'
import { SettingsSection } from '~/components/ui/settings-section'
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
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { TeamJoinRequestResource } from '~/types/team-domains'

const props = defineProps<{ teamId: string }>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime, formatRelativeTime } = useFormat()
const { useAuthorizationQuery } = useAuthorization()
const { useTeamJoinRequestsQuery, useDecideJoinRequestMutation } = useTeamDomains()

const teamId = toRef(props, 'teamId')
const { data: requests, isLoading } = useTeamJoinRequestsQuery(teamId)
const { data: authorization } = useAuthorizationQuery(computed(() => ({ team_id: teamId.value })))
const decideMutation = useDecideJoinRequestMutation()

const chosenRoles = ref<Record<string, string>>({})

const roleOptions = computed(() =>
  (authorization.value?.roles.team ?? []).map((role) => ({ value: role.key, label: role.name }))
)

const roleFor = (request: TeamJoinRequestResource) =>
  chosenRoles.value[request.id] ?? request.default_role ?? 'member'

const isActing = (request: TeamJoinRequestResource) =>
  decideMutation.isPending.value && decideMutation.variables.value?.requestId === request.id

const approve = (request: TeamJoinRequestResource) => {
  decideMutation.mutate({
    teamId: teamId.value,
    requestId: request.id,
    decision: 'approve',
    role: roleFor(request),
  })
}

const decline = async (request: TeamJoinRequestResource) => {
  const confirmed = await alert.confirm(
    t('components.teamDomains.requests.declineConfirm.message', { name: request.user?.name }),
    {
      title: t('components.teamDomains.requests.declineConfirm.title'),
      confirmLabel: t('components.teamDomains.requests.decline'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )

  if (confirmed) {
    decideMutation.mutate({ teamId: teamId.value, requestId: request.id, decision: 'decline' })
  }
}
</script>

<template>
  <SettingsSection
    id="join-requests"
    wide
    :title="$t('components.teamDomains.requests.title')"
    :description="$t('components.teamDomains.requests.description')"
  >
    <div class="overflow-hidden rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('components.teamDomains.requests.columns.person') }}</TableHead>
            <TableHead>{{ $t('components.teamDomains.requests.columns.domain') }}</TableHead>
            <TableHead>{{ $t('components.teamDomains.requests.columns.requested') }}</TableHead>
            <TableHead class="w-44">{{ $t('components.teamDomains.requests.columns.role') }}</TableHead>
            <TableHead class="w-56" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="5"
            :rows="2"
          />
          <TableEmptyRow
            v-else-if="!requests?.length"
            :colspan="5"
            :icon="UsersIcon"
            :label="$t('components.teamDomains.requests.empty')"
          />
          <template v-else>
            <TableRow
              v-for="request in requests"
              :key="request.id"
            >
              <TableCell>
                <div class="flex items-center gap-3">
                  <Avatar
                    :name="request.user?.name ?? ''"
                    :avatar="request.user?.avatar"
                  />
                  <div class="flex min-w-0 flex-col">
                    <span class="truncate font-medium">{{ request.user?.name }}</span>
                    <span class="truncate text-sm text-muted">{{ request.user?.email }}</span>
                  </div>
                </div>
              </TableCell>
              <TableCell class="text-sm">{{ request.domain }}</TableCell>
              <TableCell class="text-sm text-muted">
                <SimpleTooltip :tooltip="formatDateTime(request.created_at)">
                  {{ formatRelativeTime(request.created_at) }}
                </SimpleTooltip>
              </TableCell>
              <TableCell>
                <SelectField
                  :model-value="roleFor(request)"
                  :name="`join-request-role-${request.id}`"
                  :options="roleOptions"
                  @update:model-value="(value) => (chosenRoles[request.id] = value)"
                />
              </TableCell>
              <TableCell>
                <div class="flex justify-end gap-2">
                  <Button
                    size="sm"
                    variant="primary"
                    :loading="isActing(request) && decideMutation.variables.value?.decision === 'approve'"
                    :disabled="isActing(request)"
                    @click="approve(request)"
                  >
                    <Icon name="lucide:check" />
                    {{ $t('components.teamDomains.requests.approve') }}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    :disabled="isActing(request)"
                    @click="decline(request)"
                  >
                    <Icon name="lucide:x" />
                    {{ $t('components.teamDomains.requests.decline') }}
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          </template>
        </TableBody>
      </Table>
    </div>
  </SettingsSection>
</template>
