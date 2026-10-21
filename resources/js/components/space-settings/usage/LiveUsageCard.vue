<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'
import { Progress } from '~/components/ui/progress'
import { Skeleton } from '~/components/ui/skeleton'

const props = defineProps<{
  usage?: SpaceUsage | null
  isLoading?: boolean
}>()

const { t } = useI18n()
const { formatUnit } = useUsageFormatters()

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
  <Card v-if="isLoading || rows.length">
    <CardHeader>
      <CardTitle class="text-base">{{ $t('labels.usage.live') }}</CardTitle>
    </CardHeader>
    <CardContent>
      <div
        v-if="isLoading"
        class="grid gap-4 sm:grid-cols-2"
        aria-hidden="true"
      >
        <div
          v-for="i in 3"
          :key="i"
          class="space-y-1"
        >
          <div class="flex justify-between">
            <Skeleton class="h-4 w-20" />
            <Skeleton class="h-4 w-28" />
          </div>
          <Skeleton class="h-2 w-full rounded-full" />
        </div>
      </div>
      <div
        v-else
        class="grid gap-4 sm:grid-cols-2"
      >
        <div
          v-for="row in rows"
          :key="row.key"
          class="space-y-1"
        >
          <div class="flex justify-between text-sm">
            <span class="font-medium">{{ row.label }}</span>
            <span :class="row.over ? 'font-medium text-destructive' : 'text-muted'">
              {{ row.usedLabel
              }}<template v-if="row.limitLabel">
                / {{ row.limitLabel
                }}<template v-if="row.perMonth"> {{ $t('labels.plans.perMonth') }}</template>
              </template>
            </span>
          </div>
          <Progress
            :model-value="row.metric.unlimited ? 0 : row.metric.percentage"
            :variant="row.variant"
          />
          <p
            v-if="row.over"
            class="text-xs font-medium text-destructive"
          >
            {{ $t('labels.usage.overLimit', { percentage: row.metric.percentage }) }}
          </p>
        </div>
      </div>
      <p
        v-if="usage?.period?.resets_at"
        class="mt-3 text-xs text-muted"
      >
        {{
          $t('labels.plans.usageResets', {
            date: new Date(usage.period.resets_at).toLocaleDateString(),
          })
        }}
      </p>
    </CardContent>
  </Card>
</template>
