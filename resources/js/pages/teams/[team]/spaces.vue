<script setup lang="ts">
import SpaceIcon from '~/assets/images/space.svg?component'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)
const { useTeamOverviewQuery } = useTeamWorkspace()
const { data: overview, isLoading } = useTeamOverviewQuery(teamId)

useSeoMeta({ title: computed(() => t('labels.teamWorkspace.nav.spaces')) })

const rows = computed(() =>
  (overview.value?.structure ?? []).flatMap((team) =>
    team.spaces.map((space) => ({ ...space, team }))
  )
)
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="t('labels.teamWorkspace.nav.spaces')"
      :description="t('labels.teamWorkspace.spaces.description')"
    />

    <Panel flush>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ t('labels.teamWorkspace.spaces.columns.name') }}</TableHead>
            <TableHead>{{ t('labels.teamWorkspace.spaces.columns.team') }}</TableHead>
            <TableHead>{{ t('labels.teamWorkspace.spaces.columns.content') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="3"
          />
          <TableEmptyRow
            v-else-if="rows.length === 0"
            :colspan="3"
            :icon="SpaceIcon"
            :label="t('labels.teamWorkspace.spaces.empty')"
          />
          <TableRow
            v-for="space in rows"
            v-else
            :key="space.id"
          >
            <TableCell>
              <RouterLink
                :to="{ name: 'space', params: { space: space.id } }"
                class="font-medium text-primary hover:underline"
              >
                {{ space.name }}
              </RouterLink>
            </TableCell>
            <TableCell>
              <RouterLink
                :to="{ name: 'team-overview', params: { team: space.team.id } }"
                class="flex items-center gap-2 hover:underline"
              >
                <TeamAvatar
                  :name="space.team.name"
                  :avatar="space.team.avatar"
                  :color="space.team.color"
                  size="sm"
                />
                {{ space.team.name }}
              </RouterLink>
            </TableCell>
            <TableCell class="text-sm">
              <span class="flex items-center gap-2">
                <span
                  :class="['size-2 rounded-full', space.published ? 'bg-success' : 'bg-muted']"
                />
                {{
                  space.published
                    ? t('labels.teamWorkspace.structure.published')
                    : t('labels.teamWorkspace.structure.unpublished')
                }}
              </span>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Panel>
  </div>
</template>
