<script setup lang="ts">
import MyInvitesList from '~/components/invites/MyInvitesList.vue'
import { Card, CardContent } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import type { InviteResource } from '~/types/invites'

const { useMyInvitesQuery, useAcceptInviteMutation, useDeclineInviteMutation } = useInvites()
const { selectTeam } = useGlobalTeam()
const { t } = useI18n()
const router = useRouter()

useSeoMeta({
  title: computed(() => t('labels.account.invites.title')),
})

const { sortBy, paginationBindings, queryParams, setSortBy } = useTableQueryState({
  defaultSort: { column: 'created_at', direction: 'desc' },
  pageSize: 25,
})

const { data: invitesData, isLoading, isFetching } = useMyInvitesQuery(queryParams)

const invites = computed(() => invitesData.value?.data || [])
const meta = computed(() => invitesData.value?.meta)

const { mutate: acceptInvite, isPending: isAccepting } = useAcceptInviteMutation()
const { mutate: declineInvite, isPending: isDeclining } = useDeclineInviteMutation()

const actingInviteId = ref<string | null>(null)

const handleAccept = (invite: InviteResource) => {
  actingInviteId.value = invite.id
  acceptInvite(
    { inviteId: invite.id, payload: {} },
    {
      onSuccess: (data) => {
        if (data.space_id) {
          router.push(`/${data.space_id}`)
        } else if (data.team_id) {
          selectTeam(data.team_id)
          router.push(`/teams/${data.team_id}`)
        }
      },
      onSettled: () => {
        actingInviteId.value = null
      },
    }
  )
}

const handleDecline = (invite: InviteResource) => {
  actingInviteId.value = invite.id
  declineInvite(
    { inviteId: invite.id },
    {
      onSettled: () => {
        actingInviteId.value = null
      },
    }
  )
}
</script>

<template>
  <div class="content-grid gap-6 pb-6">
    <ContentHeader
      :header="$t('labels.account.invites.title')"
      :description="$t('labels.account.invites.description')"
    />

    <Card variant="none">
      <CardContent>
        <MyInvitesList
          :invites="invites"
          :is-loading="isLoading"
          :is-fetching="isFetching || isAccepting || isDeclining"
          :meta="meta"
          :sort-by="sortBy"
          v-bind="paginationBindings"
          :acting-invite-id="actingInviteId"
          @update:sort-by="setSortBy"
          @accept="handleAccept"
          @decline="handleDecline"
        />
      </CardContent>
    </Card>
  </div>
</template>
