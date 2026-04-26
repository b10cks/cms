<script setup lang="ts">
import StopIcon from '~/assets/images/error.svg?component'
import Icon from '~/components/Icon.vue'
import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import TeamInvitesList from '~/components/invites/TeamInvitesList.vue'
import TeamMembersList from '~/components/teams/TeamMembersList.vue'
import TeamRoleDialog from '~/components/teams/TeamRoleDialog.vue'
import TeamRolesList from '~/components/teams/TeamRolesList.vue'
import TeamSamlProviderSettings from '~/components/teams/TeamSamlProviderSettings.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent } from '~/components/ui/card'
import IconName from '~/components/ui/IconName.vue'
import { useAuthorization } from '~/composables/useAuthorization'
import { teamNavigationItems } from '~/lib/access-control'
import type { CreateTeamSpaceRolePayload, RoleCatalogEntry } from '~/types/authorization'
import type { TeamSamlProviderPayload, TeamUserQueryParams, UpdateTeamUserPayload } from '~/types/teams'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const teamId = computed(() => route.params.team as string)

const {
  useTeamQuery,
  useTeamUsersQuery,
  useUpdateTeamUserMutation,
  useRemoveTeamUserMutation,
  useTeamSpaceRolesQuery,
  useTeamSamlProviderQuery,
  useCreateTeamSpaceRoleMutation,
  useUpdateTeamSpaceRoleMutation,
  useDeleteTeamSpaceRoleMutation,
  useUpsertTeamSamlProviderMutation,
  useDeleteTeamSamlProviderMutation,
} = useTeams()

const { useDeleteTeamInviteMutation, useResendTeamInviteMutation } = useInvites()
const { useAuthorizationQuery, useAccessControl } = useAuthorization()

const { data: team, isLoading: isLoadingTeam } = useTeamQuery(teamId)
const { data: authorization, isLoading: isLoadingAuthorization } = useAuthorizationQuery(
  computed(() => ({ team_id: teamId.value }))
)
const access = useAccessControl(computed(() => ({ team_id: teamId.value })))

useSeoMeta({
  title: computed(() =>
    team.value ? t('labels.teams.detailTitle', { name: team.value.name }) : t('labels.loading')
  ),
})

const currentPage = ref(1)
const perPage = ref(20)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'firstname',
  direction: 'asc',
})
const filters = ref<Record<string, unknown>>({})
const activeView = computed(() => {
  const routeName = route.name as string | undefined

  if (routeName === 'team-roles') return 'roles'
  if (routeName === 'team-saml') return 'saml'

  return 'people'
})
const isCreateInviteDialogOpen = ref(false)
const isRoleDialogOpen = ref(false)
const selectedRole = ref<RoleCatalogEntry | null>(null)

const canViewMembers = computed(() => access.hasAbility('team.members.view'))
const canViewInvites = computed(() => access.hasAbility('team.invites.view'))
const canManageInvites = computed(() => access.hasAbility('team.invites.manage'))
const canManageRoles = computed(() => access.canAccessRoute('team-roles'))
const canManageSaml = computed(() => access.canAccessRoute('team-saml'))
const canViewPeople = computed(() => access.canAccessRoute('team'))

const queryParams = computed<TeamUserQueryParams>(() => ({
  ...filters.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  page: currentPage.value,
  per_page: perPage.value,
}))

const { data: membersData, isLoading: isLoadingMembers } = useTeamUsersQuery(
  teamId,
  queryParams,
  canViewMembers
)
const { data: teamRoles, isLoading: isLoadingRoles } = useTeamSpaceRolesQuery(
  teamId,
  canManageRoles
)
const { data: samlProviderResponse, isLoading: isLoadingSamlProvider } = useTeamSamlProviderQuery(
  teamId,
  canManageSaml
)

const updateUserMutation = useUpdateTeamUserMutation()
const removeUserMutation = useRemoveTeamUserMutation()
const createRoleMutation = useCreateTeamSpaceRoleMutation()
const updateRoleMutation = useUpdateTeamSpaceRoleMutation()
const deleteRoleMutation = useDeleteTeamSpaceRoleMutation()
const upsertSamlProviderMutation = useUpsertTeamSamlProviderMutation()
const deleteSamlProviderMutation = useDeleteTeamSamlProviderMutation()

const deleteInviteMutation = useDeleteTeamInviteMutation()
const resendInviteMutation = useResendTeamInviteMutation()

const members = computed(() => membersData.value?.data || [])
const meta = computed(() => membersData.value?.meta)
const availableRoles = computed(() => authorization.value?.roles.team || [])
const spaceRoles = computed(() => teamRoles.value || authorization.value?.roles.space || [])
const availableSpaceAbilities = computed(() =>
  Array.from(new Set(spaceRoles.value.flatMap((role) => role.abilities))).sort()
)
const isRoleSubmitting = computed(() => {
  return createRoleMutation.isPending.value || updateRoleMutation.isPending.value
})
const visibleTabs = computed(() => access.filterVisibleItems(teamNavigationItems))
const availableViews = computed(() => visibleTabs.value.map((item) => item.routeName))

watch(
  availableViews,
  (views) => {
    if (views.length === 0) return

    const currentView =
      activeView.value === 'roles' ? 'team-roles' : activeView.value === 'saml' ? 'team-saml' : 'team'

    if (!views.includes(currentView)) {
      router.replace({
        name: views[0],
        params: { team: teamId.value },
      })
    }
  },
  { immediate: true }
)

const handleUpdateRole = (userId: string, role: string) => {
  const payload: UpdateTeamUserPayload = { role }
  updateUserMutation.mutate({ teamId: teamId.value, userId, payload })
}

const handleRemoveMember = (userId: string) => {
  removeUserMutation.mutate({ teamId: teamId.value, userId })
}

const handleDeleteInvite = (inviteId: string) => {
  deleteInviteMutation.mutate({ teamId: teamId.value, inviteId })
}

const handleResendInvite = (inviteId: string) => {
  resendInviteMutation.mutate({ teamId: teamId.value, inviteId })
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

const handleCreateRole = () => {
  selectedRole.value = null
  isRoleDialogOpen.value = true
}

const handleViewRole = (role: RoleCatalogEntry) => {
  selectedRole.value = role
  isRoleDialogOpen.value = true
}

const handleSaveRole = (payload: CreateTeamSpaceRolePayload) => {
  if (selectedRole.value) {
    updateRoleMutation.mutate(
      {
        teamId: teamId.value,
        roleId: selectedRole.value.id,
        payload,
      },
      {
        onSuccess: () => {
          isRoleDialogOpen.value = false
          selectedRole.value = null
        },
      }
    )
    return
  }

  createRoleMutation.mutate(
    {
      teamId: teamId.value,
      payload,
    },
    {
      onSuccess: () => {
        isRoleDialogOpen.value = false
      },
    }
  )
}

const handleDeleteRole = (role: RoleCatalogEntry) => {
  deleteRoleMutation.mutate({ teamId: teamId.value, roleId: role.id })
}

const handleSaveSamlProvider = (payload: TeamSamlProviderPayload) => {
  upsertSamlProviderMutation.mutate({
    teamId: teamId.value,
    payload,
  })
}

const handleDeleteSamlProvider = () => {
  deleteSamlProviderMutation.mutate(teamId.value)
}

const navigateToPeople = () => {
  router.push({ name: 'team', params: { team: teamId.value } })
}

const navigateToRoles = () => {
  router.push({ name: 'team-roles', params: { team: teamId.value } })
}

const navigateToSaml = () => {
  router.push({ name: 'team-saml', params: { team: teamId.value } })
}

const navigateBack = () => {
  if (window.history.length > 1) {
    router.back()
    return
  }

  router.push({ name: 'teams-index' })
}
</script>

<template>
  <div class="flex flex-col gap-8">
    <div
      v-if="isLoadingTeam"
      class="py-12 text-center"
    >
      <Icon
        name="lucide:loader-2"
        class="h-8 w-8 animate-spin"
      />
    </div>

    <template v-else-if="team">
      <div class="mt-8">
        <div class="flex items-start gap-4">
          <div class="flex-1">
            <IconName
              :icon="team.icon || 'users'"
              :name="team.name"
              :color="team.color"
              class="text-xl font-bold"
            />
            <p
              v-if="team.description"
              class="text-muted"
            >
              {{ team.description }}
            </p>
            <div class="mt-2 flex items-center gap-4">
              <Badge v-if="team.type">
                {{ team.type }}
              </Badge>
              <span class="text-muted-foreground flex items-center gap-1 text-sm">
                <Icon name="lucide:users" />
                {{
                  $t('labels.teams.memberCount', {
                    count: team.user_count || 0,
                  })
                }}
              </span>
              <span class="text-muted-foreground flex items-center gap-1 text-sm">
                <Icon name="lucide:box" />
                {{
                  $t('labels.teams.spaceCount', {
                    count: team.spaces_count || 0,
                  })
                }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div
        v-if="availableViews.length > 0"
        class="space-y-6"
      >
        <div class="flex flex-wrap gap-2">
          <Button
            v-if="canViewPeople"
            :variant="activeView === 'people' ? 'default' : 'outline'"
            @click="navigateToPeople"
          >
            <Icon
              name="lucide:users"
              class="mr-2 h-4 w-4"
            />
            {{ $t('labels.teams.tabs.people') }}
          </Button>
          <Button
            v-if="canManageRoles"
            :variant="activeView === 'roles' ? 'default' : 'outline'"
            @click="navigateToRoles"
          >
            <Icon
              name="lucide:shield"
              class="mr-2 h-4 w-4"
            />
            {{ $t('labels.teams.tabs.roles') }}
          </Button>
          <Button
            v-if="canManageSaml"
            :variant="activeView === 'saml' ? 'default' : 'outline'"
            @click="navigateToSaml"
          >
            <Icon
              name="lucide:key-round"
              class="mr-2 h-4 w-4"
            />
            {{ $t('labels.teams.tabs.saml') }}
          </Button>
        </div>

        <template v-if="activeView === 'people' && canViewPeople">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-1">
              <h2 class="font-semibold">{{ $t('labels.teams.peopleTitle') }}</h2>
              <p class="text-muted-foreground max-w-3xl text-sm">
                {{ $t('labels.teams.peopleDescription') }}
              </p>
            </div>

            <Button
              v-if="canManageInvites"
              @click="isCreateInviteDialogOpen = true"
            >
              <Icon name="lucide:user-plus" />
              {{ $t('labels.teams.inviteAction') }}
            </Button>
          </div>

          <div
            v-if="canViewInvites"
            class="space-y-3"
          >
            <div class="space-y-1">
              <h3 class="font-semibold">{{ $t('labels.teams.invitesTitle') }}</h3>
              <p class="text-muted-foreground text-sm">
                {{ $t('labels.teams.invitesDescription') }}
              </p>
            </div>

            <TeamInvitesList
              :team-id="teamId"
              :available-roles="availableRoles"
              @delete="handleDeleteInvite"
              @resend="handleResendInvite"
            />
          </div>

          <div
            v-if="canViewMembers"
            class="space-y-3"
          >
            <div class="space-y-1">
              <h3 class="font-semibold">{{ $t('labels.teamMembers.title') }}</h3>
              <p class="text-muted-foreground text-sm">
                {{ $t('labels.teamMembers.description') }}
              </p>
              <p class="text-muted-foreground text-sm">
                {{ $t('labels.teamMembers.helper') }}
              </p>
            </div>

            <TeamMembersList
              :members="members"
              :is-loading="isLoadingMembers"
              :meta="meta"
              :current-page="currentPage"
              :per-page="perPage"
              :sort-by="sortBy"
              :available-roles="availableRoles"
              @update-role="handleUpdateRole"
              @remove="handleRemoveMember"
              @update:current-page="handleCurrentPageUpdate"
              @update:per-page="handlePerPageUpdate"
              @update:sort-by="handleSortByUpdate"
              @update:filters="handleFiltersUpdate"
            />
          </div>
        </template>

        <template v-else-if="activeView === 'roles' && canManageRoles">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-1">
              <h2 class="font-semibold">{{ $t('labels.teamRoles.title') }}</h2>
              <p class="text-muted-foreground max-w-3xl text-sm">
                {{ $t('labels.teamRoles.description') }}
              </p>
            </div>

            <Button @click="handleCreateRole">
              <Icon name="lucide:shield-plus" />
              {{ $t('labels.teamRoles.create') }}
            </Button>
          </div>

          <TeamRolesList
            :roles="spaceRoles"
            :is-loading="isLoadingRoles || isLoadingAuthorization"
            @view="handleViewRole"
            @edit="handleViewRole"
            @delete="handleDeleteRole"
          />
        </template>

        <template v-else-if="activeView === 'saml' && canManageSaml">
          <TeamSamlProviderSettings
            v-if="samlProviderResponse"
            :team-id="teamId"
            :provider="samlProviderResponse.data"
            :defaults="samlProviderResponse.defaults"
            :is-loading="isLoadingSamlProvider"
            :is-saving="upsertSamlProviderMutation.isPending.value"
            :is-deleting="deleteSamlProviderMutation.isPending.value"
            @save="handleSaveSamlProvider"
            @delete="handleDeleteSamlProvider"
          />
          <div
            v-else
            class="flex items-center gap-2 py-6"
          >
            <Icon
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{ $t('labels.loading') }}
          </div>
        </template>
      </div>

      <Card
        v-else
        variant="surface"
      >
        <CardContent class="flex flex-col gap-3 py-12! text-center items-center">
          <StopIcon class="w-32 text-muted" />
          <h2 class="font-semibold text-primary">{{ $t('labels.teams.limitedAccessTitle') }}</h2>
          <p class="text-muted-foreground mx-auto max-w-2xl text-sm">
            {{ $t('labels.teams.limitedAccessDescription') }}
          </p>
        </CardContent>
      </Card>

      <TeamRoleDialog
        v-model:open="isRoleDialogOpen"
        :role="selectedRole"
        :available-abilities="availableSpaceAbilities"
        :is-submitting="isRoleSubmitting"
        @submit="handleSaveRole"
      />

      <CreateInviteDialog
        v-if="canManageInvites"
        v-model:open="isCreateInviteDialogOpen"
        :available-roles="availableRoles"
        resource-type="team"
        :team-id="teamId"
      />
    </template>

    <div
      v-else
      class="py-12 text-center"
    >
      <Icon
        name="lucide:alert-circle"
        class="text-muted-foreground mx-auto mb-4 h-12 w-12"
      />
      <h2 class="text-xl font-semibold">{{ $t('labels.teams.notFound') }}</h2>
      <p class="text-muted-foreground mt-2">{{ $t('labels.teams.notFoundDescription') }}</p>
      <Button
        class="mt-4"
        @click="navigateBack"
      >
        {{ $t('labels.teams.backToList') }}
      </Button>
    </div>
  </div>
</template>
