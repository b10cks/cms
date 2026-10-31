<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import StatTile from '~/components/stats/StatTile.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '~/components/ui/table'
import { useProviderConsole } from '~/composables/useProviderConsole'

const { t } = useI18n()
const { formatNumber, formatRelativeTime } = useFormat()
const { useHealthQuery, useRecheckHealthMutation } = useProviderConsole()

useSeoMeta({ title: computed(() => t('providerConsole.health.title')) })

const { data: health } = useHealthQuery()
const recheck = useRecheckHealthMutation()

const queued = computed(() =>
  health.value ? health.value.queues.reduce((sum, queue) => sum + (queue.size ?? 0), 0) : null
)
const spaceDbs = computed(() => health.value?.space_databases)
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('providerConsole.health.title')"
      :description="$t('providerConsole.health.description')"
    />

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
      <StatTile
        :label="$t('providerConsole.health.queued')"
        :value="queued === null ? null : formatNumber(queued)"
        :detail="health ? $t('providerConsole.health.connection', { name: health.queue_connection }) : undefined"
      />
      <StatTile
        :label="$t('providerConsole.health.failedJobs')"
        :value="health ? (health.failed_jobs_24h === null ? '–' : formatNumber(health.failed_jobs_24h)) : null"
        :detail="$t('providerConsole.health.last24h')"
      />
      <StatTile
        :label="$t('providerConsole.health.spaceDatabases')"
        :value="spaceDbs ? `${spaceDbs.checked - spaceDbs.failed}/${spaceDbs.checked}` : null"
        :detail="spaceDbs ? $t('providerConsole.health.sampled', { total: formatNumber(spaceDbs.total) }) : undefined"
      />
    </dl>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <Panel
        flush
        :title="$t('providerConsole.health.queues')"
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{{ $t('providerConsole.health.queue') }}</TableHead>
              <TableHead class="text-right">{{ $t('providerConsole.health.size') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="queue in health?.queues ?? []"
              :key="queue.name"
            >
              <TableCell class="font-semibold text-primary">{{ queue.name }}</TableCell>
              <TableCell class="text-right tabular-nums">
                {{ queue.size === null ? $t('providerConsole.health.unknown') : formatNumber(queue.size) }}
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </Panel>

      <Panel
        :title="$t('providerConsole.health.spaceDatabases')"
        :meta="spaceDbs ? $t('providerConsole.health.checkedAt', { time: formatRelativeTime(spaceDbs.checked_at) }) : undefined"
      >
        <template #actions>
          <Button
            size="sm"
            variant="outline"
            :loading="recheck.isPending.value"
            @click="recheck.mutate()"
          >
            <Icon name="lucide:refresh-cw" />
            {{ $t('providerConsole.health.recheck') }}
          </Button>
        </template>
        <p
          v-if="spaceDbs && spaceDbs.failed === 0"
          class="flex items-center gap-2 text-sm"
        >
          <Icon
            name="lucide:check"
            class="text-success"
          />
          {{ $t('providerConsole.health.allReachable', { count: spaceDbs.checked }) }}
        </p>
        <ul
          v-else-if="spaceDbs"
          class="space-y-1 text-sm"
        >
          <li
            v-for="failure in spaceDbs.failures"
            :key="failure.space_id"
            class="flex items-center gap-2"
          >
            <Icon
              name="lucide:circle-x"
              class="text-destructive"
            />
            {{ failure.space_name ?? failure.space_id }}
          </li>
        </ul>
      </Panel>
    </div>
  </div>
</template>
