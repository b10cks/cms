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

const currentPage = ref(1)
const perPage = ref(25)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})

const queryParams = computed(() => ({
  page: currentPage.value,
  per_page: perPage.value,
  sort: sortBy.value.direction === 'asc' ? `+${sortBy.value.column}` : `-${sortBy.value.column}`,
}))

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
          :current-page="currentPage"
          :per-page="perPage"
          :sort-by="sortBy"
          :acting-invite-id="actingInviteId"
          @update:current-page="currentPage = $event"
          @update:per-page="perPage = $event"
          @update:sort-by="sortBy = $event"
          @accept="handleAccept"
          @decline="handleDecline"
        />
      </CardContent>
    </Card>
  </div>
</template>
