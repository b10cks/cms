<script setup lang="ts">
import HistoriesIcon from '~/assets/images/histories.svg?component'
import { Badge } from '~/components/ui/badge'
import { Progress } from '~/components/ui/progress'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

const props = defineProps<{
  periods: SubscriptionPeriod[]
  selectedId?: string | null
  loading?: boolean
}>()

const emit = defineEmits<{ select: [periodId: string] }>()

const { formatUnit } = useUsageFormatters()

const metricDefs: {
  key: keyof SubscriptionPeriod['usage']
  label: string
  unit: UsageUnit
}[] = [
  { key: 'storage', label: 'labels.plans.quotas.storage', unit: 'bytes' },
  { key: 'traffic', label: 'labels.plans.quotas.traffic', unit: 'bytes' },
  { key: 'ai', label: 'labels.plans.quotas.aiCredit', unit: 'usd' },
]

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

function metricVariant(metric: PeriodUsageMetric): 'default' | 'warning' | 'destructive' {
  const pct = metric.percentage ?? 0
  if (pct >= 100) return 'destructive'
  if (pct >= 80) return 'warning'
  return 'default'
}

function reasonVariant(period: SubscriptionPeriod) {
  if (period.is_open) return 'success'
  switch (period.close_reason) {
    case 'upgraded':
      return 'success'
    case 'cancelled':
    case 'expired':
      return 'destructive'
    case 'downgraded':
      return 'secondary'
    default:
      return 'surface'
  }
}
</script>

<template>
  <div class="overflow-hidden rounded-md border border-input">
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('labels.usage.history.columns.plan') }}</TableHead>
          <TableHead>{{ $t('labels.usage.history.columns.period') }}</TableHead>
          <TableHead
            v-for="def in metricDefs"
            :key="def.key"
            class="w-36"
          >
            {{ $t(def.label) }}
          </TableHead>
          <TableHead class="w-28">{{ $t('labels.usage.history.columns.status') }}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableLoadingRow
          v-if="props.loading"
          :colspan="6"
          :rows="4"
        />

        <template v-else-if="props.periods.length">
          <TableRow
            v-for="period in props.periods"
            :key="period.id"
            class="cursor-pointer"
            :data-state="period.id === props.selectedId ? 'selected' : undefined"
            @click="emit('select', period.id)"
          >
            <TableCell class="font-medium">{{ period.plan_name }}</TableCell>
            <TableCell class="whitespace-nowrap text-muted">
              {{ formatDate(period.started_at) }} –
              {{
                period.is_open ? $t('labels.usage.history.present') : formatDate(period.ended_at)
              }}
            </TableCell>

            <template v-if="period.is_open">
              <TableCell
                :colspan="metricDefs.length"
                class="text-xs text-muted"
              >
                {{ $t('labels.usage.history.currentHint') }}
              </TableCell>
            </template>

            <template v-else>
              <TableCell
                v-for="def in metricDefs"
                :key="def.key"
              >
                <div class="space-y-1 py-1">
                  <span
                    class="text-xs whitespace-nowrap"
                    :class="
                      (period.usage[def.key].percentage ?? 0) >= 100
                        ? 'font-medium text-destructive'
                        : 'text-muted'
                    "
                  >
                    {{ formatUnit(period.usage[def.key].used, def.unit) }}
                    <template v-if="period.usage[def.key].limit != null">
                      / {{ formatUnit(period.usage[def.key].limit, def.unit) }}
                    </template>
                  </span>
                  <Progress
                    :model-value="period.usage[def.key].percentage ?? 0"
                    :variant="metricVariant(period.usage[def.key])"
                  />
                </div>
              </TableCell>
            </template>

            <TableCell>
              <Badge :variant="reasonVariant(period)">
                {{
                  period.is_open
                    ? $t('labels.usage.history.current')
                    : $t(`labels.usage.history.reason.${period.close_reason}`)
                }}
              </Badge>
            </TableCell>
          </TableRow>
        </template>

        <TableEmptyRow
          v-else
          :colspan="6"
          :icon="HistoriesIcon"
          :label="$t('labels.usage.history.empty')"
        />
      </TableBody>
    </Table>
  </div>
</template>
