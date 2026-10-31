<script setup lang="ts">
import UsageShareBar from '~/components/provider/UsageShareBar.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { SelectField } from '~/components/ui/form'
import { Panel } from '~/components/ui/panel'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import { useProviderConsole } from '~/composables/useProviderConsole'
import type { ProviderUsageRow } from '~/types/provider-console'

const { t, locale } = useI18n()
const { formatCurrency, formatFileSize, formatNumber } = useFormat()
const { useUsageQuery } = useProviderConsole()

useSeoMeta({ title: computed(() => t('providerConsole.usage.title')) })

const monthKey = (date: Date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`

const monthOptions = computed(() => {
  const formatter = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' })
  const now = new Date()
  return Array.from({ length: 12 }, (_, offset) => {
    const date = new Date(now.getFullYear(), now.getMonth() - offset, 1)
    return { value: monthKey(date), label: formatter.format(date) }
  })
})

const month = ref(monthKey(new Date()))
const { data: rows, isLoading } = useUsageQuery(month)

const flagVariant = (flag: ProviderUsageRow['flags'][number]) =>
  flag === 'storage' ? 'warning' : 'destructive'
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('providerConsole.usage.title')"
      :description="$t('providerConsole.usage.description')"
    >
      <template #actions>
        <SelectField
          v-model="month"
          name="month"
          class="w-48"
          :options="monthOptions"
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
            <TableHead>{{ $t('providerConsole.usage.columns.team') }}</TableHead>
            <TableHead class="text-right">{{ $t('providerConsole.usage.columns.revenue') }}</TableHead>
            <TableHead class="text-right">{{ $t('providerConsole.usage.columns.aiSpend') }}</TableHead>
            <TableHead>{{ $t('providerConsole.usage.columns.storage') }}</TableHead>
            <TableHead class="text-right">{{ $t('providerConsole.usage.columns.apiRequests') }}</TableHead>
            <TableHead>{{ $t('providerConsole.usage.columns.traffic') }}</TableHead>
            <TableHead>{{ $t('providerConsole.usage.columns.flags') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="7"
          />
          <TableEmptyRow
            v-else-if="!rows?.length"
            :colspan="7"
            :label="$t('providerConsole.usage.empty')"
          />
          <TableRow
            v-for="row in rows"
            v-else
            :key="row.team.id"
          >
            <TableCell>
              <div class="flex min-w-0 items-center gap-2">
                <Avatar
                  :name="row.team.name"
                  size="sm"
                  class="size-6"
                />
                <span class="truncate font-semibold text-primary">{{ row.team.name }}</span>
              </div>
            </TableCell>
            <TableCell class="text-right font-semibold text-primary tabular-nums">
              {{ formatCurrency(row.revenue, 'EUR') }}
            </TableCell>
            <TableCell class="text-right tabular-nums">{{ formatCurrency(row.ai_spend, 'USD') }}</TableCell>
            <TableCell>
              <UsageShareBar
                :share="row.storage.share"
                :detail="formatFileSize(row.storage.used)"
              />
            </TableCell>
            <TableCell class="text-right tabular-nums">{{ formatNumber(row.api_requests) }}</TableCell>
            <TableCell>
              <UsageShareBar
                :share="row.traffic.share"
                :detail="formatFileSize(row.traffic.used)"
              />
            </TableCell>
            <TableCell>
              <div class="flex flex-wrap gap-1">
                <Badge
                  v-for="flag in row.flags"
                  :key="flag"
                  :variant="flagVariant(flag)"
                  size="sm"
                >
                  {{
                    flag === 'storage'
                      ? $t('providerConsole.usage.storageFlag', { percent: Math.round((row.storage.share ?? 0) * 100) })
                      : $t(`providerConsole.flags.${flag}`)
                  }}
                </Badge>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Panel>
  </div>
</template>
