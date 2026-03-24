<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import SpaceInvitesList from '~/components/invites/SpaceInvitesList.vue'
import SpaceMembersList from '~/components/spaces/SpaceMembersList.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { useAuthorization } from '~/composables/useAuthorization'
import { useSpaceMembers } from '~/composables/useSpaceMembers'
import type { SpaceMemberQueryParams, UpdateSpaceMemberPayload } from '~/types/spaces'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const { useAuthorizationQuery, useAccessControl } = useAuthorization()
const { data: authorization } = useAuthorizationQuery(computed(() => ({ space_id: spaceId.value })))
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))

const canManagePeople = computed(() =>
  access.hasAnyAbility(['space.members.manage', 'space.invites.manage'])
)
const canViewInvites = computed(() =>
  access.hasAnyAbility(['space.invites.view', 'space.invites.manage'])
)
const canViewMembers = computed(() =>
  access.hasAnyAbility(['space.members.view', 'space.members.manage'])
)

useSeoMeta({
  title: computed(() => t('labels.settings.people.title')),
})

const { useSpaceInvitesQuery, useDeleteSpaceInviteMutation, useResendSpaceInviteMutation } =
  useInvites()
const { mutate: deleteInvite } = useDeleteSpaceInviteMutation()
const { mutate: resendInvite } = useResendSpaceInviteMutation()

const { useSpaceMembersQuery, useUpdateSpaceMemberMutation, useRemoveSpaceMemberMutation } =
  useSpaceMembers()

const inviteDialogOpen = ref(false)

const currentPage = ref(1)
const perPage = ref(20)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'firstname',
  direction: 'asc',
})
const filters = ref<Record<string, unknown>>({})

const queryParams = computed<SpaceMemberQueryParams>(() => ({
  ...filters.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  page: currentPage.value,
  per_page: perPage.value,
}))

const { data: membersData, isLoading: isLoadingMembers } = useSpaceMembersQuery(
  spaceId,
  queryParams,
  canViewMembers
)

const updateMemberMutation = useUpdateSpaceMemberMutation()
const removeMemberMutation = useRemoveSpaceMemberMutation()

const members = computed(() => membersData.value?.data || [])
const membersMeta = computed(() => membersData.value?.meta)
const availableRoles = computed(() => authorization.value?.roles.space || [])

const handleDeleteInvite = (inviteId: string) => {
  deleteInvite({ spaceId: spaceId.value, inviteId })
}

const handleResendInvite = (inviteId: string) => {
  resendInvite({ spaceId: spaceId.value, inviteId })
}

const handleUpdateRole = (userId: string, role: string) => {
  const payload: UpdateSpaceMemberPayload = { role }
  updateMemberMutation.mutate({ spaceId: spaceId.value, userId, payload })
}

const handleRemoveMember = (userId: string) => {
  removeMemberMutation.mutate({ spaceId: spaceId.value, userId })
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.people.title')"
      :description="$t('labels.settings.people.description')"
    >
      <template #actions>
        <Button
          v-if="canManagePeople"
          variant="primary"
          @click="inviteDialogOpen = true"
        >
          <Icon name="lucide:user-plus" />
          {{ $t('actions.invite') }}
        </Button>
      </template>
    </ContentHeader>

    <template v-if="canViewInvites">
      <div class="space-y-3">
        <div class="space-y-1">
          <h3 class="font-semibold">{{ $t('labels.settings.people.invitesTitle') }}</h3>
          <p class="text-muted-foreground text-sm">
            {{ $t('labels.settings.people.invitesDescription') }}
          </p>
        </div>

        <SpaceInvitesList
          :space-id="spaceId"
          :available-roles="availableRoles"
          :enabled="canViewInvites"
          @delete="handleDeleteInvite"
          @resend="handleResendInvite"
        />
      </div>
    </template>

    <template v-if="canViewMembers">
      <div class="space-y-3">
        <div class="space-y-1">
          <h3 class="font-semibold">{{ $t('labels.spaceMembers.title') }}</h3>
          <p class="text-muted-foreground text-sm">
            {{ $t('labels.spaceMembers.description') }}
          </p>
        </div>

        <SpaceMembersList
          :members="members"
          :is-loading="isLoadingMembers"
          :meta="membersMeta"
          :current-page="currentPage"
          :per-page="perPage"
          :sort-by="sortBy"
          :available-roles="availableRoles"
          @update-role="handleUpdateRole"
          @remove="handleRemoveMember"
          @update:current-page="
            (val) => {
              currentPage = val
            }
          "
          @update:per-page="
            (val) => {
              perPage = val
              currentPage = 1
            }
          "
          @update:sort-by="
            (val) => {
              sortBy = val
              currentPage = 1
            }
          "
          @update:filters="
            (val) => {
              filters = val
              currentPage = 1
            }
          "
        />
      </div>
    </template>

    <CreateInviteDialog
      v-model:open="inviteDialogOpen"
      :available-roles="availableRoles"
      :space-id="spaceId"
      resource-type="space"
    />
  </div>
</template>
