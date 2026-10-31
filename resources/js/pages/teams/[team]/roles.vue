<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import TeamRoleDialog from '~/components/teams/TeamRoleDialog.vue'
import TeamRolesList from '~/components/teams/TeamRolesList.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import type { CreateTeamSpaceRolePayload, RoleCatalogEntry } from '~/types/authorization'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)

const {
  useTeamSpaceRolesQuery,
  useCreateTeamSpaceRoleMutation,
  useUpdateTeamSpaceRoleMutation,
  useDeleteTeamSpaceRoleMutation,
} = useTeams()
const { useAuthorizationQuery } = useAuthorization()
const { data: authorization, isLoading: isLoadingAuthorization } = useAuthorizationQuery(
  computed(() => ({ team_id: teamId.value }))
)
const { data: teamRoles, isLoading, isFetching } = useTeamSpaceRolesQuery(teamId)

const createRole = useCreateTeamSpaceRoleMutation()
const updateRole = useUpdateTeamSpaceRoleMutation()
const deleteRole = useDeleteTeamSpaceRoleMutation()

useSeoMeta({ title: computed(() => t('labels.teamRoles.title')) })

const spaceRoles = computed(() => teamRoles.value || authorization.value?.roles.space || [])
const availableAbilities = computed(() =>
  Array.from(new Set(spaceRoles.value.flatMap((role) => role.abilities))).sort()
)

const isDialogOpen = ref(false)
const selectedRole = ref<RoleCatalogEntry | null>(null)

const openRole = (role: RoleCatalogEntry | null) => {
  selectedRole.value = role
  isDialogOpen.value = true
}

const close = () => {
  isDialogOpen.value = false
  selectedRole.value = null
}

const saveRole = (payload: CreateTeamSpaceRolePayload) => {
  if (selectedRole.value) {
    updateRole.mutate(
      { teamId: teamId.value, roleId: selectedRole.value.id, payload },
      { onSuccess: close }
    )
    return
  }
  createRole.mutate({ teamId: teamId.value, payload }, { onSuccess: close })
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="t('labels.teamRoles.title')"
      :description="t('labels.teamRoles.description')"
    >
      <template #actions>
        <Button
          variant="primary"
          @click="openRole(null)"
        >
          <Icon name="lucide:shield-plus" />
          {{ t('labels.teamRoles.create') }}
        </Button>
      </template>
    </ContentHeader>

    <TeamRolesList
      :roles="spaceRoles"
      :is-loading="isLoading || isLoadingAuthorization"
      :is-fetching="isFetching"
      @view="openRole"
      @edit="openRole"
      @delete="(role) => deleteRole.mutate({ teamId, roleId: role.id })"
    />

    <TeamRoleDialog
      v-model:open="isDialogOpen"
      :role="selectedRole"
      :available-abilities="availableAbilities"
      :existing-keys="spaceRoles.map((role) => role.key)"
      :is-submitting="createRole.isPending.value || updateRole.isPending.value"
      @submit="saveRole"
    />
  </div>
</template>
