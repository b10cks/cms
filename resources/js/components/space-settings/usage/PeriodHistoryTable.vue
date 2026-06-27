<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import { Progress } from '~/components/ui/progress'

const props = defineProps<{
  periods: SubscriptionPeriod[]
  selectedId?: string | null
}>()

const emit = defineEmits<{ select: [periodId: string] }>()

const { formatUnit } = useUsageFormatters()

const metricDefs: { key: keyof SubscriptionPeriod['usage']; label: string; unit: UsageUnit }[] = [
  { key: 'storage', label: 'labels.plans.quotas.storage', unit: 'bytes' },
  { key: 'traffic', label: 'labels.plans.quotas.traffic', unit: 'bytes' },
  { key: 'requests', label: 'labels.plans.quotas.requests', unit: 'count' },
  { key: 'ai', label: 'labels.plans.quotas.aiCredit', unit: 'usd' },
]

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
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
  <div
    v-if="periods.length"
    class="space-y-3"
  >
    <button
      v-for="period in props.periods"
      :key="period.id"
      type="button"
      :class="[
        'w-full rounded-lg border p-4 text-left transition-colors',
        period.id === props.selectedId ? 'ring ring-ring' : 'hover:bg-secondary',
      ]"
      @click="emit('select', period.id)"
    >
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <span class="font-semibold text-primary">{{ period.plan_name }}</span>
          <span class="ml-2 text-sm text-muted">
            {{ formatDate(period.started_at) }} –
            {{ period.is_open ? $t('labels.usage.history.present') : formatDate(period.ended_at) }}
          </span>
        </div>
        <Badge :variant="reasonVariant(period)">
          {{
            period.is_open
              ? $t('labels.usage.history.current')
              : $t(`labels.usage.history.reason.${period.close_reason}`)
          }}
        </Badge>
      </div>

      <div
        v-if="!period.is_open"
        class="mt-3 grid gap-3 sm:grid-cols-2"
      >
        <div
          v-for="def in metricDefs"
          :key="def.key"
          class="space-y-1"
        >
          <div class="flex justify-between text-xs">
            <span class="font-medium">{{ $t(def.label) }}</span>
            <span class="text-muted">
              {{ formatUnit(period.usage[def.key].used, def.unit) }}
              <template v-if="period.usage[def.key].limit != null">
                / {{ formatUnit(period.usage[def.key].limit, def.unit) }}
              </template>
            </span>
          </div>
          <Progress :model-value="period.usage[def.key].percentage ?? 0" />
        </div>
      </div>

      <p
        v-else
        class="mt-2 text-xs text-muted"
      >
        {{ $t('labels.usage.history.currentHint') }}
      </p>
    </button>
  </div>

  <div
    v-else
    class="rounded-lg border border-dashed p-8 text-center text-muted"
  >
    {{ $t('labels.usage.history.empty') }}
  </div>
</template>
