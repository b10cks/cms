<script setup lang="ts">
import StopIcon from '~/assets/images/error.svg?component'
import CreateTeamDialog from '~/components/teams/CreateTeamDialog.vue'
import EditTeamDialog from '~/components/teams/EditTeamDialog.vue'
import TeamsList from '~/components/teams/TeamsList.vue'
import { Card, CardContent } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { useAuthorization } from '~/composables/useAuthorization'
import type {
  CreateTeamPayload,
  TeamHierarchyItem,
  TeamResource,
  UpdateTeamPayload,
} from '~/types/teams'

const { t } = useI18n()
const router = useRouter()

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({})))
const isRoot = computed(() => access.authorization.value?.is_root ?? false)

useSeoMeta({
  title: computed(() => t('labels.teams.pageTitle')),
})

const { sortBy, filters, paginationBindings, queryParams, setSortBy, setFilters } =
  useTableQueryState({
    defaultSort: { column: 'name', direction: 'asc' },
    pageSize: 20,
    resetOnSort: true,
    resetOnFilters: true,
    resetOnPageSize: true,
  })

const selectedTeamForEdit = ref<TeamResource | null>(null)
const isEditDialogOpen = ref(false)

const {
  useTeamsQuery,
  useTeamHierarchyQuery,
  useCreateTeamMutation,
  useUpdateTeamMutation,
  useDeleteTeamMutation,
} = useTeams()

const {
  data: teamsData,
  isLoading: isLoadingTeams,
  isFetching: isFetchingTeams,
  isError: isTeamsError,
} = useTeamsQuery(queryParams)
const { data: hierarchyData, isError: isHierarchyError } = useTeamHierarchyQuery()

const hasError = computed(() => isTeamsError.value || isHierarchyError.value)

const createTeamMutation = useCreateTeamMutation()
const updateTeamMutation = useUpdateTeamMutation()
const deleteTeamMutation = useDeleteTeamMutation()

const teams = computed(() => teamsData.value?.data || [])
const hierarchy = computed(() => hierarchyData.value || [])
const meta = computed(() => teamsData.value?.meta)

const hierarchyHasCreatableNode = (items: TeamHierarchyItem[]): boolean =>
  items.some((item) => item.can_create_child || hierarchyHasCreatableNode(item.children ?? []))

// Show the create affordance only when the user can actually create a team
// somewhere (root, or holds team.children.manage on at least one team).
const canCreateTeam = computed(() => isRoot.value || hierarchyHasCreatableNode(hierarchy.value))

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

const hasActiveFilters = computed(() => Object.keys(filters.value).length > 0)
</script>

<template>
  <div class="flex flex-col gap-8">
    <ContentHeader
      :header="$t('labels.teams.pageTitle')"
      :description="$t('labels.teams.pageDescription')"
    >
      <template #actions>
        <CreateTeamDialog
          v-if="canCreateTeam"
          :hierarchy="hierarchy"
          :is-root="isRoot"
          @submit="handleCreateTeam"
        />
      </template>
    </ContentHeader>

    <Card
      v-if="hasError"
      variant="surface"
    >
      <CardContent class="flex flex-col items-center gap-3 py-12! text-center">
        <StopIcon class="w-32 text-muted" />
        <h2 class="font-semibold text-primary">{{ $t('labels.teams.loadError.title') }}</h2>
        <p class="text-muted-foreground mx-auto max-w-2xl text-sm">
          {{ $t('labels.teams.loadError.description') }}
        </p>
      </CardContent>
    </Card>

    <TeamsList
      v-else
      :teams="teams"
      :is-loading="isLoadingTeams"
      :is-fetching="isFetchingTeams"
      :meta="meta"
      :sort-by="sortBy"
      v-bind="paginationBindings"
      @view="handleViewTeam"
      @edit="handleEditTeam"
      @delete="handleDeleteTeam"
      @update:sort-by="setSortBy"
      @update:filters="setFilters"
    >
      <template
        v-if="canCreateTeam && !hasActiveFilters"
        #empty-actions
      >
        <CreateTeamDialog
          :hierarchy="hierarchy"
          :is-root="isRoot"
          @submit="handleCreateTeam"
        />
      </template>
    </TeamsList>
  </div>

  <EditTeamDialog
    v-model:open="isEditDialogOpen"
    :team="selectedTeamForEdit"
    :hierarchy="hierarchy"
    :is-root="isRoot"
    @submit="handleUpdateTeam"
  />
</template>
