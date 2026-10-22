<script setup lang="ts">
import { Panel } from '~/components/ui/panel'
import { Progress } from '~/components/ui/progress'
import { Skeleton } from '~/components/ui/skeleton'

const props = defineProps<{
  usage?: SpaceUsage | null
  isLoading?: boolean
}>()

const { t } = useI18n()
const { formatUnit } = useUsageFormatters()
const { formatDate } = useFormat()

const rows = computed(() => {
  const u = props.usage
  if (!u) return []

  const defs: Array<{ key: string; label: string; metric: UsageMetric; perMonth: boolean }> = [
    { key: 'storage', label: t('labels.plans.quotas.storage'), metric: u.storage, perMonth: false },
    { key: 'traffic', label: t('labels.plans.quotas.traffic'), metric: u.traffic, perMonth: true },
    { key: 'ai', label: t('labels.plans.quotas.aiCredit'), metric: u.ai, perMonth: true },
  ]

  return defs
    .filter((d) => d.metric.available)
    .map((d) => {
      const over = d.metric.exceeded || d.metric.percentage >= 100
      return {
        ...d,
        usedLabel: formatUnit(d.metric.used, d.metric.unit),
        limitLabel: d.metric.unlimited ? null : formatUnit(d.metric.limit, d.metric.unit),
        over: over && !d.metric.unlimited,
        variant: (d.metric.unlimited
          ? 'default'
          : over
            ? 'destructive'
            : d.metric.percentage >= 80
              ? 'warning'
              : 'default') as 'default' | 'warning' | 'destructive',
      }
    })
})
</script>

<template>
  <Panel
    v-if="isLoading || rows.length"
    :title="$t('labels.usage.live')"
    :meta="
      usage?.period?.resets_at
        ? $t('labels.usage.resets', { date: formatDate(usage.period.resets_at) })
        : undefined
    "
  >
    <div class="grid gap-x-8 gap-y-5 sm:grid-cols-3">
      <template v-if="isLoading">
        <div
          v-for="i in 3"
          :key="i"
          class="grid gap-2"
          aria-hidden="true"
        >
          <Skeleton class="h-5 w-24" />
          <Skeleton class="h-7 w-32" />
          <Skeleton class="h-2 w-full rounded-full" />
        </div>
      </template>
      <div
        v-for="row in rows"
        v-else
        :key="row.key"
        class="grid gap-1.5"
      >
        <span class="text-sm text-muted select-none">{{ row.label }}</span>
        <span class="truncate tabular-nums">
          <span
            class="text-xl font-semibold"
            :class="row.over ? 'text-destructive' : 'text-primary'"
          >{{ row.usedLabel }}</span>
          <span
            v-if="row.limitLabel"
            class="text-sm text-muted"
          >
            / {{ row.limitLabel
            }}<template v-if="row.perMonth"> {{ $t('labels.plans.perMonth') }}</template>
          </span>
        </span>
        <Progress
          :model-value="row.metric.unlimited ? 0 : row.metric.percentage"
          :variant="row.variant"
        />
        <span
          v-if="row.over"
          class="text-sm text-destructive"
        >
          {{ $t('labels.usage.overLimit', { percentage: row.metric.percentage }) }}
        </span>
      </div>
    </div>
  </Panel>
</template>
