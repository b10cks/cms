<script setup lang="ts">
import { hasRootWriteAccess } from '~/lib/access-control'
import TeamDetailsSettings from '~/components/teams/TeamDetailsSettings.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Skeleton } from '~/components/ui/skeleton'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)

const { useTeamQuery, useTeamHierarchyQuery } = useTeams()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ team_id: teamId.value })))
// Moving to the top level and changing the type are root writes: they need a running elevation.
const isRoot = computed(() => hasRootWriteAccess(access.authorization.value))

const { data: team } = useTeamQuery(teamId)
const { data: hierarchy } = useTeamHierarchyQuery()

useSeoMeta({ title: computed(() => t('labels.teamWorkspace.nav.settings')) })
</script>

<template>
  <div class="content-grid">
    <ContentHeader :header="t('labels.teamWorkspace.nav.settings')" />

    <TeamDetailsSettings
      v-if="team"
      :team="team"
      :hierarchy="hierarchy ?? []"
      :is-root="isRoot"
    />
    <Skeleton
      v-else
      class="h-64 w-full"
    />
  </div>
</template>
