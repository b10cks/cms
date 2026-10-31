<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import StatTile from '~/components/stats/StatTile.vue'
import CreateTeamDialog from '~/components/teams/CreateTeamDialog.vue'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import TeamActivityList from '~/components/teams/workspace/TeamActivityList.vue'
import TeamAttentionList from '~/components/teams/workspace/TeamAttentionList.vue'
import TeamMoveDialog from '~/components/teams/workspace/TeamMoveDialog.vue'
import TeamStructurePanel from '~/components/teams/workspace/TeamStructurePanel.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'
import type { CreateTeamPayload } from '~/types/teams'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)

const { useTeamQuery, useTeamHierarchyQuery, useCreateTeamMutation } = useTeams()
const { useTeamOverviewQuery, useTeamAuditLogsQuery, useMoveTeamMutation } = useTeamWorkspace()
const { useAuthorizationQuery, useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ team_id: teamId.value })))
const { data: authorization } = useAuthorizationQuery(computed(() => ({ team_id: teamId.value })))

const { data: team } = useTeamQuery(teamId)
const { data: overview } = useTeamOverviewQuery(teamId)

const canViewActivity = computed(() => access.hasAbility('team.audit.view'))
const canInvite = computed(() => access.hasAbility('team.invites.manage'))
const canCreateChild = computed(() => access.hasAbility('team.children.manage'))
const isRoot = computed(() => access.authorization.value?.is_root ?? false)

const { data: activity } = useTeamAuditLogsQuery(teamId, { per_page: 5 }, canViewActivity)
const { data: hierarchy } = useTeamHierarchyQuery(canCreateChild)

useSeoMeta({
  title: computed(() => team.value?.name ?? t('labels.teamWorkspace.nav.overview')),
})

const kpis = computed(() => overview.value?.kpis)
const tiles = computed(() => {
  const k = kpis.value
  const sso = k?.sso
  return [
    {
      label: t('labels.teamWorkspace.kpis.members'),
      value: k ? String(k.members.total) : null,
      detail: k ? t('labels.teamWorkspace.kpis.membersDetail', { count: k.members.active_30d }) : '',
    },
    {
      label: t('labels.teamWorkspace.kpis.spaces'),
      value: k ? String(k.spaces.total) : null,
      detail: k ? t('labels.teamWorkspace.kpis.spacesDetail', { count: k.spaces.published }) : '',
    },
    {
      label: t('labels.teamWorkspace.kpis.pendingInvites'),
      value: k ? String(k.pending_invites.total) : null,
      detail: k ? t('labels.teamWorkspace.kpis.pendingDetail', { count: k.pending_invites.stale }) : '',
    },
    {
      label: t('labels.teamWorkspace.kpis.expiring'),
      value: k ? String(k.expiring_access.total) : null,
      detail: t('labels.teamWorkspace.kpis.expiringDetail'),
    },
    {
      label: t('labels.teamWorkspace.kpis.sso'),
      value: sso
        ? t(sso.enabled ? 'labels.teamWorkspace.kpis.ssoOn' : 'labels.teamWorkspace.kpis.ssoOff')
        : null,
      detail: sso
        ? t(
            sso.enabled
              ? 'labels.teamWorkspace.kpis.ssoOnDetail'
              : sso.configured
                ? 'labels.teamWorkspace.kpis.ssoDisabledDetail'
                : 'labels.teamWorkspace.kpis.ssoNotSetUp'
          )
        : '',
    },
  ]
})

const isInviteOpen = ref(false)
const isCreateOpen = ref(false)
const availableTeamRoles = computed(() => authorization.value?.roles.team ?? [])

// The switcher's "New sub-team" lands here with ?create=sub-team.
watch(
  () => route.query.create,
  (create) => {
    if (create !== 'sub-team') return
    if (canCreateChild.value) isCreateOpen.value = true
    router.replace({ query: { ...route.query, create: undefined } })
  },
  { immediate: true }
)

const createTeam = useCreateTeamMutation()
const handleCreateTeam = (payload: CreateTeamPayload) => createTeam.mutate(payload)

const pendingMove = ref<{
  teamId: string
  teamName: string
  parentId: string
  parentName: string
} | null>(null)

const nodeName = (id: string) => overview.value?.structure.find((node) => node.id === id)?.name ?? ''

const requestMove = (movedId: string, parentId: string) => {
  pendingMove.value = {
    teamId: movedId,
    teamName: nodeName(movedId),
    parentId,
    parentName: nodeName(parentId),
  }
}

const moveTeam = useMoveTeamMutation()
const confirmMove = () => {
  if (!pendingMove.value) return
  moveTeam.mutate(
    { ...pendingMove.value, scopeTeamId: teamId.value },
    { onSettled: () => (pendingMove.value = null) }
  )
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="team?.name ?? ''"
      :description="team?.description ?? undefined"
    >
      <template #before-header>
        <TeamAvatar
          v-if="team"
          :name="team.name"
          :avatar="team.avatar"
          :color="team.color"
          size="lg"
        />
        <Skeleton
          v-else
          class="size-10 rounded-lg"
        />
      </template>
      <template #after-header>
        <Badge
          v-if="team?.type"
          type="outline"
          size="sm"
        >
          {{ team.type }}
        </Badge>
      </template>
      <template #actions>
        <div class="flex items-center gap-2">
          <CreateTeamDialog
            v-if="canCreateChild"
            v-model:open="isCreateOpen"
            :hierarchy="hierarchy ?? []"
            :is-root="isRoot"
            :parent-id="teamId"
            @submit="handleCreateTeam"
          >
            <template #trigger>
              <Button variant="outline">
                <Icon name="lucide:plus" />
                {{ t('labels.teamWorkspace.overview.subTeam') }}
              </Button>
            </template>
          </CreateTeamDialog>
          <Button
            v-if="canInvite"
            variant="primary"
            @click="isInviteOpen = true"
          >
            <Icon name="lucide:user-plus" />
            {{ t('actions.invite') }}
          </Button>
        </div>
      </template>
    </ContentHeader>

    <dl class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
      <StatTile
        v-for="tile in tiles"
        :key="tile.label"
        v-bind="tile"
      />
    </dl>

    <TeamStructurePanel
      :nodes="overview?.structure"
      :current-team-id="teamId"
      :people-count="kpis?.members.total ?? null"
      @move="requestMove"
    />

    <div class="grid gap-4 lg:grid-cols-2">
      <Panel
        :title="t('labels.teamWorkspace.attention.title')"
        :meta="overview?.attention.length ? String(overview.attention.length) : undefined"
      >
        <TeamAttentionList
          v-if="overview"
          :items="overview.attention"
        />
        <Skeleton
          v-else
          class="h-24 w-full"
        />
      </Panel>

      <Panel
        v-if="canViewActivity"
        :title="t('labels.teamWorkspace.activity.title')"
      >
        <template #actions>
          <Button
            size="sm"
            variant="ghost"
            as-child
          >
            <RouterLink :to="{ name: 'team-activity', params: { team: teamId } }">
              {{ t('labels.teamWorkspace.activity.viewAll') }}
            </RouterLink>
          </Button>
        </template>
        <TeamActivityList
          v-if="activity?.data.length"
          :entries="activity.data"
        />
        <p
          v-else-if="activity"
          class="py-6 text-sm text-muted"
        >
          {{ t('labels.teamWorkspace.activity.empty') }}
        </p>
        <Skeleton
          v-else
          class="h-24 w-full"
        />
      </Panel>
    </div>

    <TeamMoveDialog
      :move="pendingMove"
      :is-moving="moveTeam.isPending.value"
      @confirm="confirmMove"
      @cancel="pendingMove = null"
    />

    <CreateInviteDialog
      v-if="canInvite"
      v-model:open="isInviteOpen"
      :available-roles="availableTeamRoles"
      resource-type="team"
      :team-id="teamId"
    />
  </div>
</template>
