<script setup lang="ts">
import { refDebounced } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import ProviderSearch from '~/components/provider/ProviderSearch.vue'
import ProviderSegments from '~/components/provider/ProviderSegments.vue'
import ProviderUserSheet from '~/components/provider/ProviderUserSheet.vue'
import ProviderUserStatusBadge from '~/components/provider/ProviderUserStatusBadge.vue'
import { Avatar } from '~/components/ui/avatar'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { useProviderConsole } from '~/composables/useProviderConsole'
import type { ProviderUser, ProviderUserSegment } from '~/types/provider-console'

const { t } = useI18n()
const { formatRelativeTime } = useFormat()
const { useUsersQuery } = useProviderConsole()

useSeoMeta({ title: computed(() => t('providerConsole.users.title')) })

const search = ref('')
const debouncedSearch = refDebounced(search, 250)
const segment = ref<ProviderUserSegment>('all')
const page = ref(1)
const perPage = ref(25)

watch([debouncedSearch, segment, perPage], () => {
  page.value = 1
})

const params = computed(() => ({
  search: debouncedSearch.value.trim() || undefined,
  segment: segment.value,
  page: page.value,
  per_page: perPage.value,
}))

const { data, isLoading, isFetching } = useUsersQuery(params)
const users = computed(() => data.value?.data ?? [])

const segments = computed(() =>
  (['all', 'active', 'suspended', 'root'] as const).map((value) => ({
    value,
    label: t(`providerConsole.users.segments.${value}`),
    count: data.value?.meta.segments[value] ?? null,
  }))
)

const selectedUserId = ref<string | null>(null)
const sheetOpen = ref(false)

const openUser = (user: ProviderUser) => {
  selectedUserId.value = user.id
  sheetOpen.value = true
}

const teamsLabel = (user: ProviderUser) => {
  if (user.teams_count === 0) return '–'
  const more = user.teams_count - 1
  return more > 0 ? `${user.first_team ?? ''} +${more}` : (user.first_team ?? '')
}

const signInLabel = (method: string) => {
  const key = `providerConsole.users.signIn.${method}`
  const label = t(key)
  return label === key ? method.charAt(0).toUpperCase() + method.slice(1) : label
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('providerConsole.users.title')"
      :description="$t('providerConsole.users.description')"
    />

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <ProviderSearch
        v-model="search"
        :placeholder="$t('providerConsole.users.search')"
      />
      <ProviderSegments
        v-model="segment"
        :segments="segments"
      />
    </div>

    <Panel
      flush
      class="mt-3"
    >
      <Table :class="isFetching && !isLoading ? 'opacity-60 transition-opacity' : 'transition-opacity'">
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('providerConsole.users.columns.name') }}</TableHead>
            <TableHead>{{ $t('providerConsole.users.columns.teams') }}</TableHead>
            <TableHead>{{ $t('providerConsole.users.columns.signIn') }}</TableHead>
            <TableHead>{{ $t('providerConsole.users.columns.status') }}</TableHead>
            <TableHead>{{ $t('providerConsole.users.columns.lastActive') }}</TableHead>
            <TableHead class="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="6"
          />
          <TableEmptyRow
            v-else-if="users.length === 0"
            :colspan="6"
            :label="$t('providerConsole.users.empty')"
          />
          <TableRow
            v-for="user in users"
            v-else
            :key="user.id"
            class="cursor-pointer"
            tabindex="0"
            @click="openUser(user)"
            @keydown.enter="openUser(user)"
          >
            <TableCell>
              <div class="flex min-w-0 items-center gap-3">
                <Avatar
                  :name="user.name"
                  :avatar="user.avatar"
                />
                <div class="min-w-0 leading-tight">
                  <div class="truncate font-semibold text-primary">{{ user.name }}</div>
                  <div class="truncate text-sm text-muted">{{ user.email }}</div>
                </div>
              </div>
            </TableCell>
            <TableCell class="text-sm">{{ teamsLabel(user) }}</TableCell>
            <TableCell class="text-sm text-muted">
              {{ user.sign_in.map(signInLabel).join(', ') }}
            </TableCell>
            <TableCell>
              <ProviderUserStatusBadge :status="user.status" />
            </TableCell>
            <TableCell class="text-sm text-muted">
              {{ user.last_login_at ? formatRelativeTime(user.last_login_at) : $t('providerConsole.never') }}
            </TableCell>
            <TableCell class="text-right">
              <Icon
                name="lucide:chevron-right"
                class="text-muted"
              />
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
      <TablePaginationFooter
        v-if="data?.meta"
        v-model:current-page="page"
        v-model:per-page="perPage"
        :meta="data.meta"
      />
    </Panel>

    <ProviderUserSheet
      v-model:open="sheetOpen"
      :user-id="selectedUserId"
    />
  </div>
</template>
