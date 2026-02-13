<script setup lang="ts">
import type { TeamsQueryParams } from '~/api/resources/teams'
import CreateTeamDialog from '~/components/teams/CreateTeamDialog.vue'
import EditTeamDialog from '~/components/teams/EditTeamDialog.vue'
import TeamsList from '~/components/teams/TeamsList.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
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
const { data: hierarchyData, isLoading: isLoadingHierarchy } = useTeamHierarchyQuery()

const createTeamMutation = useCreateTeamMutation()
const updateTeamMutation = useUpdateTeamMutation()
const deleteTeamMutation = useDeleteTeamMutation()

const teams = computed(() => teamsData.value?.data || [])
const hierarchy = computed(() => hierarchyData.value || [])
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
  <div class="flex flex-col gap-8">
    <ContentHeader
      :header="$t('labels.teams.pageTitle')"
      :description="$t('labels.teams.pageDescription')"
    >
      <template #actions>
        <CreateTeamDialog
          :hierarchy="hierarchy"
          @submit="handleCreateTeam"
        />
      </template>
    </ContentHeader>

    <TeamsList
      :teams="teams"
      :hierarchy="hierarchy"
      :is-loading="isLoadingTeams"
      :meta="meta"
      :current-page="currentPage"
      :per-page="perPage"
      :sort-by="sortBy"
      @view="handleViewTeam"
      @edit="handleEditTeam"
      @delete="handleDeleteTeam"
      @update:current-page="handleCurrentPageUpdate"
      @update:per-page="handlePerPageUpdate"
      @update:sort-by="handleSortByUpdate"
      @update:filters="handleFiltersUpdate"
    />
  </div>

  <EditTeamDialog
    v-model:open="isEditDialogOpen"
    :team="selectedTeamForEdit"
    :hierarchy="hierarchy"
    @submit="handleUpdateTeam"
  />
</template>
