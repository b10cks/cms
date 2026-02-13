<script setup lang="ts">
import type { TeamsQueryParams } from '~/api/resources/teams'
import AppHeader from '~/components/AppHeader.vue'
import TeamHierarchyTree from '~/components/teams/TeamHierarchyTree.vue'
import TeamSelector from '~/components/TeamSelector.vue'
import type { CreateTeamPayload, TeamResource, UpdateTeamPayload } from '~/types/teams'

const { t } = useI18n()

useSeoMeta({
  title: computed(() => t('labels.teams.pageTitle')),
})

const currentPage = ref(1)
const perPage = ref(20)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'name',
  direction: 'asc',
})
const filters = ref<Record<string, unknown>>({})
const selectedTeamForEdit = ref<TeamResource | null>(null)
const isEditDialogOpen = ref(false)

const queryParams = computed<TeamsQueryParams>(() => ({
  ...filters.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  page: currentPage.value,
  per_page: perPage.value,
}))

const {
  useTeamsQuery,
  useTeamHierarchyQuery,
  useCreateTeamMutation,
  useUpdateTeamMutation,
  useDeleteTeamMutation,
} = useTeams()

const { data: teamsData, isLoading: isLoadingTeams } = useTeamsQuery(queryParams)
const { teams } = useGlobalTeam()

const createTeamMutation = useCreateTeamMutation()
const updateTeamMutation = useUpdateTeamMutation()
const deleteTeamMutation = useDeleteTeamMutation()

const meta = computed(() => teamsData.value?.meta)

const handleCreateTeam = (payload: CreateTeamPayload) => {
  createTeamMutation.mutate(payload)
}

const handleEditTeam = (team: TeamResource) => {
  selectedTeamForEdit.value = team
  isEditDialogOpen.value = true
}

const handleUpdateTeam = (payload: UpdateTeamPayload) => {
  if (selectedTeamForEdit.value) {
    updateTeamMutation.mutate({
      id: selectedTeamForEdit.value.id,
      payload,
    })
  }
}

const handleDeleteTeam = (teamId: string) => {
  deleteTeamMutation.mutate(teamId)
}

const handleViewTeam = (teamId: string) => {
  router.push({ name: 'team', params: { team: teamId } })
}

const handleCurrentPageUpdate = (page: number) => {
  currentPage.value = page
}

const handlePerPageUpdate = (perPageValue: number) => {
  perPage.value = perPageValue
  currentPage.value = 1
}

const handleSortByUpdate = (sort: { column: string; direction: 'asc' | 'desc' }) => {
  sortBy.value = sort
  currentPage.value = 1
}

const handleFiltersUpdate = (filtersValue: Record<string, unknown>) => {
  filters.value = filtersValue
  currentPage.value = 1
}

const handleHierarchySelect = (teamId: string) => {
  handleViewTeam(teamId)
}
</script>

<template>
  <AppHeader>
    <div class="flex items-start">
      <TeamSelector size="sm" />
    </div>
  </AppHeader>
  <div class="flex w-full grow bg-background pt-14">
    <aside class="w-sm shrink-0 border-r border-border bg-surface">
      <TeamHierarchyTree
        :title="$t('labels.teams.hierarchyTitle')"
        :teams="teams"
        @select="handleHierarchySelect"
      />
    </aside>
    <main class="content-grid mx-auto">
      <RouterView />
    </main>
  </div>
</template>
