<script setup lang="ts">
import InvitesList from '~/components/invites/InvitesList.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'

const { useMyInvitesQuery } = useInvites()
const { t } = useI18n()

useSeoMeta({
  title: computed(() => t('labels.account.invites.title')),
})

const currentPage = ref(1)
const perPage = ref(25)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const filters = ref<Record<string, unknown>>({})

const queryParams = computed(() => ({
  page: currentPage.value,
  per_page: perPage.value,
  sort: sortBy.value.direction === 'asc' ? `+${sortBy.value.column}` : `-${sortBy.value.column}`,
  ...filters.value,
}))

const { data: invitesData, isLoading } = useMyInvitesQuery(queryParams)

const invites = computed(() => invitesData.value?.data || [])
const meta = computed(() => invitesData.value?.meta)

const handleDeleteInvite = (inviteId: string) => {
  // Personal invites cannot be deleted by the invitee, only by the inviter
  // This is handled in space/team settings
}

const handleResendInvite = (inviteId: string) => {
  // Personal invites cannot be resent by the invitee
  // This is handled in space/team settings
}
</script>

<template>
  <div class="content-grid gap-6 pb-6">
    <ContentHeader
      :header="$t('labels.account.invites.title')"
      :description="$t('labels.account.invites.description')"
    />

    <Card variant="outline">
      <CardHeader>
        <CardTitle>{{ $t('labels.account.invites.myInvites') }}</CardTitle>
        <CardDescription>{{ $t('labels.account.invites.myInvitesDescription') }}</CardDescription>
      </CardHeader>
      <CardContent>
        <InvitesList
          :invites="invites"
          :is-loading="isLoading"
          :meta="meta"
          :current-page="currentPage"
          :per-page="perPage"
          :sort-by="sortBy"
          @update:current-page="currentPage = $event"
          @update:per-page="perPage = $event"
          @update:sort-by="sortBy = $event"
          @update:filters="filters = $event"
          @delete="handleDeleteInvite"
          @resend="handleResendInvite"
        />
      </CardContent>
    </Card>
  </div>
</template>
