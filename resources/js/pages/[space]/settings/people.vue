<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import PeopleManager from '~/components/people/PeopleManager.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { useAuthorization } from '~/composables/useAuthorization'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const { useAuthorizationQuery, useAccessControl } = useAuthorization()
const { data: authorization } = useAuthorizationQuery(computed(() => ({ space_id: spaceId.value })))
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))

const canViewPeople = computed(() =>
  access.hasAnyAbility([
    'space.members.view',
    'space.members.manage',
    'space.invites.view',
    'space.invites.manage',
  ])
)
const canManagePeople = computed(() =>
  access.hasAnyAbility(['space.members.manage', 'space.invites.manage'])
)
const canManageInvites = computed(() => access.hasAbility('space.invites.manage'))

useSeoMeta({
  title: computed(() => t('labels.settings.people.title')),
})

const availableRoles = computed(() => authorization.value?.roles.space || [])
const inviteDialogOpen = ref(false)
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

    <PeopleManager
      v-if="canViewPeople"
      resource-type="space"
      :resource-id="spaceId"
      :available-roles="availableRoles"
      :can-manage-invites="canManageInvites"
    />

    <CreateInviteDialog
      v-model:open="inviteDialogOpen"
      :available-roles="availableRoles"
      :space-id="spaceId"
      resource-type="space"
    />
  </div>
</template>
