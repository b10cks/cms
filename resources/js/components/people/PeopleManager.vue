<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import AccessExpiryDialog from '~/components/people/AccessExpiryDialog.vue'
import AccessMatrix from '~/components/people/AccessMatrix.vue'
import PeopleList from '~/components/people/PeopleList.vue'
import SearchFilter, { type FilterableField } from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { usePeople } from '~/composables/usePeople'
import type { RoleCatalogEntry } from '~/types/authorization'

const props = withDefaults(
  defineProps<{
    resourceType: 'space' | 'team'
    resourceId: string
    availableRoles?: RoleCatalogEntry[]
    enabled?: boolean
    canManageInvites?: boolean
    /** Roles of the other scope, only to name them. */
    otherRoles?: RoleCatalogEntry[]
    /** Team or space name, for pending invites. */
    resourceName?: string
    /** Spaces a team role reaches. */
    spaceCount?: number
    initialSegment?: PersonSegment
    /** Offer the people-by-spaces matrix (teams only). */
    showMatrix?: boolean
  }>(),
  {
    availableRoles: () => [],
    enabled: true,
    canManageInvites: false,
    otherRoles: () => [],
    resourceName: '',
    spaceCount: 0,
    initialSegment: 'all',
    showMatrix: false,
  }
)

const { t } = useI18n()

const segment = ref<PersonSegment>(props.initialSegment)
const view = ref<'list' | 'matrix'>('list')
const {
  currentPage,
  sortBy,
  filters,
  paginationBindings,
  queryParams: tableParams,
  setSortBy,
  setFilters,
} = useTableQueryState({
  defaultSort: { column: 'firstname', direction: 'asc' },
  pageSize: 20,
  resetOnSort: true,
  resetOnFilters: true,
  resetOnPageSize: true,
})

const queryParams = computed<PeopleQueryParams>(() => ({
  ...tableParams.value,
  segment: segment.value,
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
const counts = computed(
  () => data.value?.counts ?? { members: 0, pending: 0, expiring: 0, total: 0 }
)

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
  {
    value: 'expiring' as const,
    label: t('labels.teamWorkspace.people.expiring'),
    count: counts.value.expiring,
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

const { useSetAccessExpiryMutation } = useTeamWorkspace()
const setExpiry = useSetAccessExpiryMutation()
const expiryPerson = ref<PersonResource | null>(null)

const expiryTarget = computed(() =>
  expiryPerson.value
    ? {
        name: expiryPerson.value.user?.name ?? expiryPerson.value.email,
        accessExpiresAt: expiryPerson.value.access_expires_at,
      }
    : null
)

const saveExpiry = (accessUntil: string | null) => {
  const userId = expiryPerson.value?.user_id
  if (!userId) return
  setExpiry.mutate(
    { kind: props.resourceType, resourceId: props.resourceId, userId, accessUntil },
    { onSuccess: () => (expiryPerson.value = null) }
  )
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
        v-if="view === 'list'"
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

      <div class="flex flex-1 flex-col gap-2 sm:flex-row lg:justify-end">
        <template v-if="view === 'list'">
          <SearchFilter
            :model-value="filters"
            :filterable-fields="peopleFilters"
            @update:model-value="setFilters"
          />
          <SortSelect
            :model-value="sortBy"
            :options="sortOptions"
            @update:model-value="setSortBy"
          />
        </template>
        <Tabs
          v-if="showMatrix"
          v-model="view"
          class="self-start sm:ml-auto"
        >
          <TabsList>
            <TabsTrigger value="list">
              <Icon name="lucide:list" />
              {{ t('labels.teamWorkspace.people.list') }}
            </TabsTrigger>
            <TabsTrigger value="matrix">
              <Icon name="lucide:grid-3x3" />
              {{ t('labels.teamWorkspace.people.matrix') }}
            </TabsTrigger>
          </TabsList>
        </Tabs>
      </div>
    </div>

    <AccessMatrix
      v-if="showMatrix && view === 'matrix'"
      :team-id="resourceId"
    />

    <PeopleList
      v-else
      :people="people"
      :is-loading="isLoading"
      :is-fetching="isFetching"
      :meta="meta"
      :sort-by="sortBy"
      v-bind="paginationBindings"
      :available-roles="availableRoles"
      :resource-type="resourceType"
      :can-manage-invites="canManageInvites"
      :other-roles="otherRoles"
      :resource-name="resourceName"
      :space-count="spaceCount"
      @set-expiry="(person) => (expiryPerson = person)"
      @update-role="handleUpdateRole"
      @remove-member="handleRemoveMember"
      @resend-invite="handleResendInvite"
      @delete-invite="handleDeleteInvite"
      @update:sort-by="setSortBy"
    />

    <AccessExpiryDialog
      :person="expiryTarget"
      :is-saving="setExpiry.isPending.value"
      @save="saveExpiry"
      @cancel="expiryPerson = null"
    />
  </div>
</template>
