<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import PeopleManager from '~/components/people/PeopleManager.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)

const { useAuthorizationQuery, useAccessControl } = useAuthorization()
const { data: authorization } = useAuthorizationQuery(computed(() => ({ team_id: teamId.value })))
const access = useAccessControl(computed(() => ({ team_id: teamId.value })))
const { useTeamQuery } = useTeams()
const { useTeamOverviewQuery } = useTeamWorkspace()
const { data: team } = useTeamQuery(teamId)
const { data: overview } = useTeamOverviewQuery(teamId)

const canViewMembers = computed(() => access.hasAbility('team.members.view'))
const canViewInvites = computed(() => access.hasAbility('team.invites.view'))
const canManageInvites = computed(() => access.hasAbility('team.invites.manage'))

const segments: PersonSegment[] = ['all', 'members', 'pending', 'expiring']
const initialSegment = computed<PersonSegment>(() =>
  segments.find((segment) => segment === route.query.segment) ?? 'all'
)

useSeoMeta({ title: computed(() => t('labels.teamWorkspace.nav.people')) })

const isInviteOpen = ref(false)
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="t('labels.teamWorkspace.nav.people')"
      :description="t('labels.teamWorkspace.people.description')"
    >
      <template #actions>
        <Button
          v-if="canManageInvites"
          variant="primary"
          @click="isInviteOpen = true"
        >
          <Icon name="lucide:user-plus" />
          {{ t('actions.invite') }}
        </Button>
      </template>
    </ContentHeader>

    <PeopleManager
      v-if="canViewMembers || canViewInvites"
      :key="`${teamId}-${initialSegment}`"
      resource-type="team"
      :resource-id="teamId"
      :resource-name="team?.name ?? ''"
      :available-roles="authorization?.roles.team ?? []"
      :other-roles="authorization?.roles.space ?? []"
      :space-count="overview?.kpis.spaces.total ?? team?.spaces_count ?? 0"
      :initial-segment="initialSegment"
      :can-manage-invites="canManageInvites"
      :show-matrix="canViewMembers"
    />

    <CreateInviteDialog
      v-if="canManageInvites"
      v-model:open="isInviteOpen"
      :available-roles="authorization?.roles.team ?? []"
      resource-type="team"
      :team-id="teamId"
    />
  </div>
</template>
