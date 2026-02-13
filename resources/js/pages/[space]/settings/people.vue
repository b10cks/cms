<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import CreateInviteDialog from '~/components/invites/CreateInviteDialog.vue'
import SpaceInvitesList from '~/components/invites/SpaceInvitesList.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('labels.settings.people.title')),
})

const { useSpaceInvitesQuery, useDeleteSpaceInviteMutation, useResendSpaceInviteMutation } =
  useInvites()
const { mutate: deleteInvite } = useDeleteSpaceInviteMutation()
const { mutate: resendInvite } = useResendSpaceInviteMutation()

const inviteDialogOpen = ref(false)

const handleDeleteInvite = (inviteId: string) => {
  deleteInvite({ spaceId: spaceId.value, inviteId })
}

const handleResendInvite = (inviteId: string) => {
  resendInvite({ spaceId: spaceId.value, inviteId })
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
          variant="primary"
          @click="inviteDialogOpen = true"
        >
          <Icon name="lucide:user-plus" />
          {{ $t('actions.invite') }}
        </Button>
      </template>
    </ContentHeader>

    <SpaceInvitesList
      :space-id="spaceId"
      @delete="handleDeleteInvite"
      @resend="handleResendInvite"
    />

    <CreateInviteDialog
      v-model:open="inviteDialogOpen"
      :space-id="spaceId"
      resource-type="space"
    />
  </div>
</template>
