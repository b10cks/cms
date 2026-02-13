<script setup lang="ts">
import { computed, ref } from 'vue'
import type { InviteQueryParams } from '~/types/invites'
import InvitesList from './InvitesList.vue'

const props = defineProps<{
  teamId: string
}>()

const emit = defineEmits<{
  delete: [inviteId: string]
  resend: [inviteId: string]
}>()

const currentPage = ref(1)
const perPage = ref(24)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const filters = ref<Record<string, unknown>>({})

const queryParams = computed<InviteQueryParams>(() => {
  return {
    ...filters.value,
    sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
    page: currentPage.value,
    per_page: perPage.value,
  }
})

const { useTeamInvitesQuery } = useInvites()
const { data: invitesData, isLoading } = useTeamInvitesQuery(props.teamId, queryParams)

const handleDelete = (inviteId: string) => {
  emit('delete', inviteId)
}

const handleResend = (inviteId: string) => {
  emit('resend', inviteId)
}

const handleCurrentPageUpdate = (page: number) => {
  currentPage.value = page
}

const handlePerPageUpdate = (perPageValue: number) => {
  perPage.value = perPageValue
  currentPage.value = 1 // Reset to first page when changing per page
}

const handleSortByUpdate = (sort: { column: string; direction: 'asc' | 'desc' }) => {
  sortBy.value = sort
  currentPage.value = 1 // Reset to first page when changing sort
}

const handleFiltersUpdate = (filtersValue: Record<string, unknown>) => {
  filters.value = filtersValue
  currentPage.value = 1 // Reset to first page when changing filters
}
</script>

<template>
  <InvitesList
    :invites="invitesData?.data || []"
    :is-loading="isLoading"
    :meta="invitesData?.meta"
    :current-page="currentPage"
    :per-page="perPage"
    :sort-by="sortBy"
    @delete="handleDelete"
    @resend="handleResend"
    @update:current-page="handleCurrentPageUpdate"
    @update:per-page="handlePerPageUpdate"
    @update:sort-by="handleSortByUpdate"
    @update:filters="handleFiltersUpdate"
  />
</template>
