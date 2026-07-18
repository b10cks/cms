<script setup lang="ts">
import PeopleList from '~/components/people/PeopleList.vue'
import SearchFilter, { type FilterableField } from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { usePeople } from '~/composables/usePeople'
import type { RoleCatalogEntry } from '~/types/authorization'
import type { PeopleQueryParams, PersonSegment } from '~/types/people'

const props = withDefaults(
  defineProps<{
    resourceType: 'space' | 'team'
    resourceId: string
    availableRoles?: RoleCatalogEntry[]
    enabled?: boolean
    canManageInvites?: boolean
  }>(),
  {
    availableRoles: () => [],
    enabled: true,
    canManageInvites: false,
  }
)

const { t } = useI18n()

const segment = ref<PersonSegment>('all')
const currentPage = ref(1)
const perPage = ref(20)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'firstname',
  direction: 'asc',
})
const filters = ref<Record<string, unknown>>({})

const queryParams = computed<PeopleQueryParams>(() => ({
  ...filters.value,
  segment: segment.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  page: currentPage.value,
  per_page: perPage.value,
}))

const resourceId = computed(() => props.resourceId)
const enabled = computed(() => props.enabled)

const { useSpacePeopleQuery, useTeamPeopleQuery } = usePeople()
const { data, isLoading, isFetching } =
  props.resourceType === 'space'
    ? useSpacePeopleQuery(resourceId, queryParams, enabled)
    : useTeamPeopleQuery(resourceId, queryParams, enabled)

const people = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
const counts = computed(() => data.value?.counts ?? { members: 0, pending: 0, total: 0 })

const segments = computed(() => [
  { value: 'all' as const, label: t('labels.people.segments.all'), count: counts.value.total },
  {
    value: 'members' as const,
    label: t('labels.people.segments.members'),
    count: counts.value.members,
  },
  {
    value: 'pending' as const,
    label: t('labels.people.segments.pending'),
    count: counts.value.pending,
  },
])

const peopleFilters = computed((): FilterableField[] => [
  {
    id: 'name',
    label: t('labels.people.filters.name'),
    operators: [
      { value: 'like', label: t('labels.spaceMembers.operators.contains') },
      { value: '^like', label: t('labels.spaceMembers.operators.startsWith') },
      { value: 'like$', label: t('labels.spaceMembers.operators.endsWith') },
      { value: 'eq', label: t('labels.spaceMembers.operators.equals') },
    ],
  },
  {
    id: 'email',
    label: t('labels.people.filters.email'),
    operators: [
      { value: 'like', label: t('labels.spaceMembers.operators.contains') },
      { value: '^like', label: t('labels.spaceMembers.operators.startsWith') },
      { value: 'like$', label: t('labels.spaceMembers.operators.endsWith') },
      { value: 'eq', label: t('labels.spaceMembers.operators.equals') },
    ],
  },
  {
    id: 'role',
    label: t('labels.people.filters.role'),
    operators: [{ value: 'eq', label: t('labels.spaceMembers.operators.equals') }],
    items: props.availableRoles.map((role) => ({ value: role.key, label: role.name })),
  },
])

const sortOptions = computed(() => [
  { value: 'firstname', label: t('labels.people.sort.name') },
  { value: 'email', label: t('labels.people.sort.email') },
  { value: 'role', label: t('labels.people.sort.role') },
  { value: 'joined_at', label: t('labels.people.sort.joined') },
])

const selectSegment = (value: PersonSegment) => {
  if (segment.value === value) return
  segment.value = value
  currentPage.value = 1
}

const handleSort = (value: { column: string; direction: 'asc' | 'desc' }) => {
  sortBy.value = value
  currentPage.value = 1
}

const handleFilters = (value: Record<string, unknown>) => {
  filters.value = value
  currentPage.value = 1
}

const handlePerPage = (value: number) => {
  perPage.value = value
  currentPage.value = 1
}

const { useUpdateSpaceMemberMutation, useRemoveSpaceMemberMutation } = useSpaceMembers()
const { useUpdateTeamUserMutation, useRemoveTeamUserMutation } = useTeams()
const {
  useDeleteSpaceInviteMutation,
  useResendSpaceInviteMutation,
  useDeleteTeamInviteMutation,
  useResendTeamInviteMutation,
} = useInvites()

const updateSpaceMember = useUpdateSpaceMemberMutation()
const removeSpaceMember = useRemoveSpaceMemberMutation()
const updateTeamUser = useUpdateTeamUserMutation()
const removeTeamUser = useRemoveTeamUserMutation()
const deleteSpaceInvite = useDeleteSpaceInviteMutation()
const resendSpaceInvite = useResendSpaceInviteMutation()
const deleteTeamInvite = useDeleteTeamInviteMutation()
const resendTeamInvite = useResendTeamInviteMutation()

const isSpace = props.resourceType === 'space'

const handleUpdateRole = (userId: string, role: string) => {
  if (isSpace) {
    updateSpaceMember.mutate({ spaceId: props.resourceId, userId, payload: { role } })
  } else {
    updateTeamUser.mutate({ teamId: props.resourceId, userId, payload: { role } })
  }
}

const handleRemoveMember = (userId: string) => {
  if (isSpace) {
    removeSpaceMember.mutate({ spaceId: props.resourceId, userId })
  } else {
    removeTeamUser.mutate({ teamId: props.resourceId, userId })
  }
}

const handleResendInvite = (inviteId: string) => {
  if (isSpace) {
    resendSpaceInvite.mutate({ spaceId: props.resourceId, inviteId })
  } else {
    resendTeamInvite.mutate({ teamId: props.resourceId, inviteId })
  }
}

const handleDeleteInvite = (inviteId: string) => {
  if (isSpace) {
    deleteSpaceInvite.mutate({ spaceId: props.resourceId, inviteId })
  } else {
    deleteTeamInvite.mutate({ teamId: props.resourceId, inviteId })
  }
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <Tabs
        :model-value="segment"
        class="self-start"
        @update:model-value="(value) => selectSegment(value as PersonSegment)"
      >
        <TabsList>
          <TabsTrigger
            v-for="item in segments"
            :key="item.value"
            :value="item.value"
          >
            {{ item.label }}
            <Badge size="xs">
              {{ item.count }}
            </Badge>
          </TabsTrigger>
        </TabsList>
      </Tabs>

      <div class="flex flex-1 flex-col gap-2 sm:flex-row lg:max-w-xl lg:justify-end">
        <SearchFilter
          :model-value="filters"
          :filterable-fields="peopleFilters"
          @update:model-value="handleFilters"
        />
        <SortSelect
          :model-value="sortBy"
          :options="sortOptions"
          @update:model-value="handleSort"
        />
      </div>
    </div>

    <PeopleList
      :people="people"
      :is-loading="isLoading"
      :is-fetching="isFetching"
      :meta="meta"
      :current-page="currentPage"
      :per-page="perPage"
      :sort-by="sortBy"
      :available-roles="availableRoles"
      :resource-type="resourceType"
      :can-manage-invites="canManageInvites"
      @update-role="handleUpdateRole"
      @remove-member="handleRemoveMember"
      @resend-invite="handleResendInvite"
      @delete-invite="handleDeleteInvite"
      @update:current-page="(val) => (currentPage = val)"
      @update:per-page="handlePerPage"
      @update:sort-by="handleSort"
    />
  </div>
</template>
