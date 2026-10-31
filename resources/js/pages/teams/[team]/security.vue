<script setup lang="ts">
import TeamSamlProviderSettings from '~/components/teams/TeamSamlProviderSettings.vue'
import TeamDomainsSettings from '~/components/teams/TeamDomainsSettings.vue'
import TeamJoinRequests from '~/components/teams/TeamJoinRequests.vue'
import TeamSamlProviderSkeleton from '~/components/teams/TeamSamlProviderSkeleton.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import type { TeamSamlProviderPayload } from '~/types/teams'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ team_id: teamId.value })))
const canManageMembers = computed(() => access.hasAbility('team.members.manage'))
const canManageDomains = computed(() => access.hasAbility('team.domains.manage'))

const {
  useTeamSamlProviderQuery,
  useUpsertTeamSamlProviderMutation,
  useDeleteTeamSamlProviderMutation,
} = useTeams()
const { data: samlProvider, isLoading } = useTeamSamlProviderQuery(teamId)
const upsertProvider = useUpsertTeamSamlProviderMutation()
const deleteProvider = useDeleteTeamSamlProviderMutation()

useSeoMeta({ title: computed(() => t('labels.teamWorkspace.nav.security')) })

const save = (payload: TeamSamlProviderPayload) =>
  upsertProvider.mutate({ teamId: teamId.value, payload })
</script>

<template>
  <div class="content-grid">
    <ContentHeader :header="t('labels.teamWorkspace.nav.security')" />

    <TeamSamlProviderSettings
      v-if="samlProvider"
      :team-id="teamId"
      :provider="samlProvider.data"
      :defaults="samlProvider.defaults"
      :is-loading="isLoading"
      :is-saving="upsertProvider.isPending.value"
      :is-deleting="deleteProvider.isPending.value"
      @save="save"
      @delete="deleteProvider.mutate(teamId)"
    />
    <TeamSamlProviderSkeleton v-else />

    <div
      v-if="canManageDomains || canManageMembers"
      class="divide-y divide-border border-t border-border pt-8"
    >
      <TeamDomainsSettings
        v-if="canManageDomains"
        :team-id="teamId"
        :sso-enabled="samlProvider?.data?.enabled ?? false"
      />
      <TeamJoinRequests
        v-if="canManageMembers"
        :team-id="teamId"
      />
    </div>
  </div>
</template>
