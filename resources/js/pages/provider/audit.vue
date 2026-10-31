<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { SelectField } from '~/components/ui/form'
import { Panel } from '~/components/ui/panel'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { useProviderConsole } from '~/composables/useProviderConsole'
import { INSTANCE_AUDIT_ACTIONS, instanceAuditLabel } from '~/lib/instance-audit'
import type { InstanceAuditActionKey, InstanceAuditEntry } from '~/types/provider-console'

const { t } = useI18n()
const { formatDateTime } = useFormat()
const { useAuditQuery } = useProviderConsole()

useSeoMeta({ title: computed(() => t('providerConsole.audit.title')) })

/** Sentinel for "every action": SelectField reads null as cleared. */
const ALL = 'all'

const action = ref<InstanceAuditActionKey | typeof ALL>(ALL)
const page = ref(1)
const perPage = ref(50)

watch([action, perPage], () => {
  page.value = 1
})

const params = computed(() => ({
  page: page.value,
  per_page: perPage.value,
  ...(action.value === ALL ? {} : { action: action.value }),
}))

const { data, isLoading } = useAuditQuery(params)

const actionOptions = computed(() => [
  { value: ALL, label: t('providerConsole.audit.allActions') },
  ...INSTANCE_AUDIT_ACTIONS.map((value) => ({ value, label: instanceAuditLabel(t, value) })),
])

const variant = (entry: InstanceAuditEntry) => {
  if (entry.action.startsWith('root.elevation')) return 'warning'
  if (entry.action.startsWith('root.impersonation')) return 'ai'
  if (entry.action === 'root.user.suspended') return 'destructive'
  return 'secondary'
}

/** The human part of an entry: who or what it was about, and why. */
const detail = (entry: InstanceAuditEntry) => {
  const meta = entry.metadata
  const parts = [meta.user_email, meta.team_name, meta.reason ?? meta.suspension_reason].filter(
    (part): part is string => typeof part === 'string' && part !== ''
  )
  return parts.join(', ')
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('providerConsole.audit.title')"
      :description="$t('providerConsole.audit.description')"
    >
      <template #actions>
        <SelectField
          v-model="action"
          name="action"
          class="w-64"
          :options="actionOptions"
        />
      </template>
    </ContentHeader>

    <Panel
      flush
      class="mt-6"
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('providerConsole.audit.columns.time') }}</TableHead>
            <TableHead>{{ $t('providerConsole.audit.columns.actor') }}</TableHead>
            <TableHead>{{ $t('providerConsole.audit.columns.action') }}</TableHead>
            <TableHead>{{ $t('providerConsole.audit.columns.detail') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="4"
          />
          <TableEmptyRow
            v-else-if="!data?.data.length"
            :colspan="4"
            :label="$t('providerConsole.audit.empty')"
          />
          <TableRow
            v-for="entry in data?.data"
            v-else
            :key="entry.id"
          >
            <TableCell class="text-sm whitespace-nowrap text-muted">
              {{ entry.created_at ? formatDateTime(entry.created_at) : '' }}
            </TableCell>
            <TableCell class="text-sm">{{ entry.actor?.name ?? $t('providerConsole.audit.system') }}</TableCell>
            <TableCell>
              <Badge
                :variant="variant(entry)"
                size="sm"
              >
                {{ instanceAuditLabel(t, entry.action) }}
              </Badge>
            </TableCell>
            <TableCell class="max-w-md truncate text-sm">{{ detail(entry) }}</TableCell>
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
  </div>
</template>
