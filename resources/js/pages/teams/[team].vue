<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import TeamInvitesList from '~/components/invites/TeamInvitesList.vue'
import AddMemberDialog from '~/components/teams/AddMemberDialog.vue'
import TeamMembersList from '~/components/teams/TeamMembersList.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import IconName from '~/components/ui/IconName.vue'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import type { AddTeamUserPayload, TeamUserQueryParams, UpdateTeamUserPayload } from '~/types/teams'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const teamId = computed(() => route.params.team as string)

const {
  useTeamQuery,
  useTeamUsersQuery,
  useAddTeamUserMutation,
  useUpdateTeamUserMutation,
  useRemoveTeamUserMutation,
} = useTeams()

const { useTeamInvitesQuery, useDeleteTeamInviteMutation, useResendTeamInviteMutation } =
  useInvites()

const { data: team, isLoading: isLoadingTeam } = useTeamQuery(teamId)

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

const queryParams = computed<TeamUserQueryParams>(() => ({
  ...filters.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  page: currentPage.value,
  per_page: perPage.value,
}))

const { data: membersData, isLoading: isLoadingMembers } = useTeamUsersQuery(teamId, queryParams)

const addUserMutation = useAddTeamUserMutation()
const updateUserMutation = useUpdateTeamUserMutation()
const removeUserMutation = useRemoveTeamUserMutation()

const deleteInviteMutation = useDeleteTeamInviteMutation()
const resendInviteMutation = useResendTeamInviteMutation()

const members = computed(() => membersData.value?.data || [])
const meta = computed(() => membersData.value?.meta)

const availableRoles = ['owner', 'admin', 'editor', 'member', 'viewer']
const isCreateInviteDialogOpen = ref(false)

const handleAddMember = (payload: AddTeamUserPayload) => {
  addUserMutation.mutate({ teamId: teamId.value, payload })
}

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

const getTeamColorStyle = (color: string | null | undefined) => {
  if (!color) return {}
  return { backgroundColor: color }
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
                  $t('labels.teams.memberCount', team.spaces_count || 0, {
                    count: team.user_count || 0,
                  })
                }}
              </span>
              <span class="text-muted-foreground flex items-center gap-1 text-sm">
                <Icon name="lucide:box" />
                {{
                  $t('labels.teams.spaceCount', team.spaces_count || 0, {
                    count: team.spaces_count || 0,
                  })
                }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <Tabs
        default-value="members"
        class="w-full"
      >
        <TabsList class="mb-6">
          <TabsTrigger value="members">
            <Icon
              name="lucide:users"
              class="mr-2 h-4 w-4"
            />
            {{ $t('labels.teams.tabs.members') }}
          </TabsTrigger>
          <TabsTrigger value="invites">
            <Icon
              name="lucide:mail"
              class="mr-2 h-4 w-4"
            />
            {{ $t('labels.teams.tabs.invites') }}
          </TabsTrigger>
        </TabsList>

        <TabsContent
          value="members"
          class="space-y-4"
        >
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">{{ $t('labels.teamMembers.title') }}</h2>
            <AddMemberDialog
              :available-roles="availableRoles"
              @submit="handleAddMember"
            />
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
        </TabsContent>

        <TabsContent
          value="invites"
          class="space-y-4"
        >
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">{{ $t('labels.teams.invitesTitle') }}</h2>
            <CreateInviteDialog
              v-model:open="isCreateInviteDialogOpen"
              resource-type="team"
              :team-id="teamId"
            />
          </div>

          <TeamInvitesList
            :team-id="teamId"
            @delete="handleDeleteInvite"
            @resend="handleResendInvite"
          />
        </TabsContent>
      </Tabs>
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
