<script setup lang="ts">
import { RouterLink } from 'vue-router'

import Icon from '~/components/Icon.vue'
import ProviderSearch from '~/components/provider/ProviderSearch.vue'
import ProviderSegments from '~/components/provider/ProviderSegments.vue'
import CreateTeamDialog from '~/components/teams/CreateTeamDialog.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import { useProviderConsole } from '~/composables/useProviderConsole'
import { useTeamTypes } from '~/composables/useTeamTypes'
import { hasRootWriteAccess } from '~/lib/access-control'
import type { ProviderTenant } from '~/types/provider-console'

type Segment = 'all' | 'paying' | 'trial' | 'flagged'

const { t } = useI18n()
const { formatCurrency, formatRelativeTime } = useFormat()
const { getTeamTypeLabel } = useTeamTypes()
const { useTenantsQuery } = useProviderConsole()
const { useTeamHierarchyQuery, useCreateTeamMutation } = useTeams()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({})))
const canWrite = computed(() => hasRootWriteAccess(access.authorization.value))

useSeoMeta({ title: computed(() => t('providerConsole.tenants.title')) })

const { data: tenants, isLoading } = useTenantsQuery()
const { data: hierarchy } = useTeamHierarchyQuery()
const createTeam = useCreateTeamMutation()

const search = ref('')
const segment = ref<Segment>('all')
const collapsed = ref(new Set<string>())

const matchesSegment = (tenant: ProviderTenant, value: Segment) =>
  value === 'all' ||
  (value === 'paying' && tenant.mrr > 0) ||
  (value === 'trial' && tenant.is_trial) ||
  (value === 'flagged' && tenant.flags.length > 0)

const segments = computed(() =>
  (['all', 'paying', 'trial', 'flagged'] as const).map((value) => ({
    value,
    label: t(`providerConsole.tenants.segments.${value}`),
    count: tenants.value?.filter((tenant) => matchesSegment(tenant, value)).length ?? null,
  }))
)

const isFiltered = computed(() => segment.value !== 'all' || search.value.trim() !== '')

const childrenByParent = computed(() => {
  const map = new Map<string | null, ProviderTenant[]>()
  for (const tenant of tenants.value ?? []) {
    const known = tenant.parent_id && tenants.value?.some((other) => other.id === tenant.parent_id)
    const parent = known ? tenant.parent_id : null
    map.set(parent, [...(map.get(parent) ?? []), tenant])
  }
  return map
})

/** The tree while unfiltered; a flat list of matches once searching or segmenting. */
const rows = computed<Array<{ tenant: ProviderTenant; depth: number; hasChildren: boolean }>>(() => {
  if (isFiltered.value) {
    const term = search.value.trim().toLowerCase()
    return (tenants.value ?? [])
      .filter((tenant) => matchesSegment(tenant, segment.value))
      .filter((tenant) => !term || tenant.name.toLowerCase().includes(term))
      .map((tenant) => ({ tenant, depth: 0, hasChildren: false }))
  }

  const result: Array<{ tenant: ProviderTenant; depth: number; hasChildren: boolean }> = []
  const walk = (parentId: string | null, depth: number) => {
    for (const tenant of childrenByParent.value.get(parentId) ?? []) {
      const hasChildren = (childrenByParent.value.get(tenant.id)?.length ?? 0) > 0
      result.push({ tenant, depth, hasChildren })
      if (hasChildren && !collapsed.value.has(tenant.id)) walk(tenant.id, depth + 1)
    }
  }
  walk(null, 0)
  return result
})

const toggle = (id: string) => {
  const next = new Set(collapsed.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  collapsed.value = next
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('providerConsole.tenants.title')"
      :description="$t('providerConsole.tenants.description')"
    >
      <template #actions>
        <CreateTeamDialog
          :hierarchy="hierarchy ?? []"
          :is-root="canWrite"
          @submit="createTeam.mutate($event)"
        >
          <template #trigger>
            <Button
              variant="primary"
              :disabled="!canWrite"
              :title="canWrite ? undefined : $t('providerConsole.elevateFirst')"
            >
              <Icon name="lucide:plus" />
              {{ $t('providerConsole.tenants.createTopLevel') }}
            </Button>
          </template>
        </CreateTeamDialog>
      </template>
    </ContentHeader>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <ProviderSearch
        v-model="search"
        :placeholder="$t('providerConsole.tenants.search')"
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
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('providerConsole.tenants.columns.team') }}</TableHead>
            <TableHead>{{ $t('providerConsole.tenants.columns.type') }}</TableHead>
            <TableHead class="text-right">{{ $t('providerConsole.tenants.columns.spaces') }}</TableHead>
            <TableHead class="text-right">{{ $t('providerConsole.tenants.columns.people') }}</TableHead>
            <TableHead class="text-right">{{ $t('providerConsole.tenants.columns.mrr') }}</TableHead>
            <TableHead>{{ $t('providerConsole.tenants.columns.sso') }}</TableHead>
            <TableHead>{{ $t('providerConsole.tenants.columns.lastActive') }}</TableHead>
            <TableHead class="w-20" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="8"
          />
          <TableEmptyRow
            v-else-if="rows.length === 0"
            :colspan="8"
            :label="$t('providerConsole.tenants.empty')"
          />
          <TableRow
            v-for="{ tenant, depth, hasChildren } in rows"
            v-else
            :key="tenant.id"
          >
            <TableCell>
              <div
                class="flex min-w-0 items-center gap-2"
                :style="{ paddingLeft: `${depth * 22}px` }"
              >
                <button
                  v-if="hasChildren"
                  type="button"
                  class="text-muted hover:text-primary"
                  :aria-expanded="!collapsed.has(tenant.id)"
                  :aria-label="tenant.name"
                  @click="toggle(tenant.id)"
                >
                  <Icon :name="collapsed.has(tenant.id) ? 'lucide:chevron-right' : 'lucide:chevron-down'" />
                </button>
                <span
                  v-else-if="!isFiltered"
                  class="w-4 shrink-0"
                />
                <Avatar
                  :name="tenant.name"
                  :avatar="tenant.avatar"
                  size="sm"
                  class="size-6"
                />
                <span class="truncate font-semibold text-primary">{{ tenant.name }}</span>
                <Badge
                  v-for="flag in tenant.flags"
                  :key="flag"
                  variant="destructive"
                  size="sm"
                >
                  {{ $t(`providerConsole.flags.${flag}`) }}
                </Badge>
              </div>
            </TableCell>
            <TableCell>
              <Badge
                v-if="tenant.type"
                type="outline"
                size="sm"
              >
                {{ getTeamTypeLabel(tenant.type) }}
              </Badge>
            </TableCell>
            <TableCell class="text-right tabular-nums">{{ tenant.spaces_count }}</TableCell>
            <TableCell class="text-right tabular-nums">{{ tenant.people_count }}</TableCell>
            <TableCell class="text-right font-semibold text-primary tabular-nums">
              {{ formatCurrency(tenant.mrr, 'EUR', { maximumFractionDigits: 0 }) }}
            </TableCell>
            <TableCell class="text-sm">
              <span
                v-if="tenant.sso"
                class="flex items-center gap-1"
              >
                <Icon
                  name="lucide:check"
                  class="text-success"
                />
                SAML
              </span>
              <span
                v-else
                class="text-muted"
              >{{ $t('providerConsole.tenants.ssoOff') }}</span>
            </TableCell>
            <TableCell class="text-sm text-muted">
              {{ tenant.last_active_at ? formatRelativeTime(tenant.last_active_at) : $t('providerConsole.never') }}
            </TableCell>
            <TableCell class="text-right">
              <Button
                size="sm"
                variant="ghost"
                :as="RouterLink"
                :to="{ name: 'team', params: { team: tenant.id } }"
              >
                {{ $t('providerConsole.tenants.open') }}
              </Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Panel>
  </div>
</template>
