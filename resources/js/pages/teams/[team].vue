<script setup lang="ts">
import StopIcon from '~/assets/images/error.svg?component'
import Icon from '~/components/Icon.vue'
import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import PeopleManager from '~/components/people/PeopleManager.vue'
import TeamDetailsSettings from '~/components/teams/TeamDetailsSettings.vue'
import TeamRoleDialog from '~/components/teams/TeamRoleDialog.vue'
import TeamRolesList from '~/components/teams/TeamRolesList.vue'
import TeamSamlProviderSettings from '~/components/teams/TeamSamlProviderSettings.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent } from '~/components/ui/card'
import { Spinner } from '~/components/ui/spinner'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { useAuthorization } from '~/composables/useAuthorization'
import { teamNavigationItems } from '~/lib/access-control'
import type { CreateTeamSpaceRolePayload, RoleCatalogEntry } from '~/types/authorization'
import type { TeamSamlProviderPayload } from '~/types/teams'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const teamId = computed(() => route.params.team as string)

const {
  useTeamQuery,
  useTeamHierarchyQuery,
  useTeamSpaceRolesQuery,
  useTeamSamlProviderQuery,
  useCreateTeamSpaceRoleMutation,
  useUpdateTeamSpaceRoleMutation,
  useDeleteTeamSpaceRoleMutation,
  useUpsertTeamSamlProviderMutation,
  useDeleteTeamSamlProviderMutation,
} = useTeams()

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

const activeView = computed(() => {
  const routeName = route.name as string | undefined

  if (routeName === 'team-roles') return 'roles'
  if (routeName === 'team-saml') return 'saml'
  if (routeName === 'team-settings') return 'settings'

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
const canManageSettings = computed(() => access.canAccessRoute('team-settings'))
const canViewPeople = computed(() => access.canAccessRoute('team'))
const isRoot = computed(() => access.authorization.value?.is_root ?? false)

const { data: hierarchyData } = useTeamHierarchyQuery(canManageSettings)
const hierarchy = computed(() => hierarchyData.value || [])

const {
  data: teamRoles,
  isLoading: isLoadingRoles,
  isFetching: isFetchingRoles,
} = useTeamSpaceRolesQuery(teamId, canManageRoles)
const { data: samlProviderResponse, isLoading: isLoadingSamlProvider } = useTeamSamlProviderQuery(
  teamId,
  canManageSaml
)

const createRoleMutation = useCreateTeamSpaceRoleMutation()
const updateRoleMutation = useUpdateTeamSpaceRoleMutation()
const deleteRoleMutation = useDeleteTeamSpaceRoleMutation()
const upsertSamlProviderMutation = useUpsertTeamSamlProviderMutation()
const deleteSamlProviderMutation = useDeleteTeamSamlProviderMutation()

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
      activeView.value === 'roles'
        ? 'team-roles'
        : activeView.value === 'saml'
          ? 'team-saml'
          : activeView.value === 'settings'
            ? 'team-settings'
            : 'team'

    if (!views.includes(currentView)) {
      router.replace({
        name: views[0],
        params: { team: teamId.value },
      })
    }
  },
  { immediate: true }
)

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

const viewTabs = computed(() => {
  const tabs: { value: string; route: string; icon: string; label: string }[] = []

  if (canViewPeople.value) {
    tabs.push({
      value: 'people',
      route: 'team',
      icon: 'lucide:users',
      label: t('labels.teams.tabs.people'),
    })
  }

  if (canManageRoles.value) {
    tabs.push({
      value: 'roles',
      route: 'team-roles',
      icon: 'lucide:shield',
      label: t('labels.teams.tabs.roles'),
    })
  }

  if (canManageSaml.value) {
    tabs.push({
      value: 'saml',
      route: 'team-saml',
      icon: 'lucide:key-round',
      label: t('labels.teams.tabs.saml'),
    })
  }

  if (canManageSettings.value) {
    tabs.push({
      value: 'settings',
      route: 'team-settings',
      icon: 'lucide:settings',
      label: t('labels.teams.tabs.settings'),
    })
  }

  return tabs
})

const selectView = (value: string | number) => {
  const tab = viewTabs.value.find((item) => item.value === value)
  if (tab && tab.value !== activeView.value) {
    router.push({ name: tab.route, params: { team: teamId.value } })
  }
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
      <Spinner class="size-8" />
    </div>

    <template v-else-if="team">
      <div class="mt-8">
        <div class="flex items-start gap-4">
          <Avatar
            :name="team.name"
            :avatar="team.avatar"
            :border-color="team.color"
            size="lg"
            class="mt-1"
          />
          <div class="flex-1">
            <h1 class="flex items-center gap-2 text-xl font-bold">
              <Icon
                v-if="team.icon"
                :name="`lucide:${team.icon}`"
                :style="{ color: team.color || undefined }"
              />
              {{ team.name }}
            </h1>
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
        class="flex flex-col gap-6"
      >
        <Tabs
          :model-value="activeView"
          class="self-start"
          @update:model-value="selectView"
        >
          <TabsList>
            <TabsTrigger
              v-for="tab in viewTabs"
              :key="tab.value"
              :value="tab.value"
            >
              <Icon :name="tab.icon" />
              {{ tab.label }}
            </TabsTrigger>
          </TabsList>
        </Tabs>

        <div class="flex-1 space-y-6">
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

            <p
              v-if="canViewMembers"
              class="text-muted-foreground text-sm"
            >
              {{ $t('labels.teamMembers.helper') }}
            </p>

            <PeopleManager
              v-if="canViewMembers || canViewInvites"
              resource-type="team"
              :resource-id="teamId"
              :available-roles="availableRoles"
              :enabled="canViewMembers || canViewInvites"
              :can-manage-invites="canManageInvites"
            />
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
              :is-fetching="isFetchingRoles"
              @view="handleViewRole"
              @edit="handleViewRole"
              @delete="handleDeleteRole"
            />
          </template>

          <template v-else-if="activeView === 'settings' && canManageSettings">
            <TeamDetailsSettings
              :team="team"
              :hierarchy="hierarchy"
              :is-root="isRoot"
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
              <Spinner />
              {{ $t('labels.loading') }}
            </div>
          </template>
        </div>
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
        :existing-keys="spaceRoles.map((role) => role.key)"
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
