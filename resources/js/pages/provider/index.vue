<script setup lang="ts">
import { useFullscreen } from '@vueuse/core'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import StatTile from '~/components/stats/StatTile.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { SelectField } from '~/components/ui/form'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'
import { downloadPng, printElement } from '~/lib/export-element'
import { runtimeConfig } from '~/lib/runtime-config'

// Charts stay out of the route chunk; tiles paint first.
const TrendChart = defineAsyncComponent(() => import('~/components/stats/TrendChart.vue'))
const StackedBarChart = defineAsyncComponent(() => import('~/components/stats/StackedBarChart.vue'))
const DistributionList = defineAsyncComponent(
  () => import('~/components/stats/DistributionList.vue')
)
const ProviderRetentionGrid = defineAsyncComponent(
  () => import('~/components/provider/ProviderRetentionGrid.vue')
)

const { t } = useI18n()
const { formatCurrency, formatDateTime, formatFileSize, formatNumber } = useFormat()
const { useProviderMetricsQuery } = useProvider()

useSeoMeta({ title: computed(() => t('providerDashboard.title')) })

const billing = runtimeConfig.public.features.billing
const range = ref<ProviderMetricsRange>('12m')
const { data, isLoading, isFetching, error } = useProviderMetricsQuery(range)

const root = ref<HTMLElement | null>(null)
const { isFullscreen: presenting, enter: present, exit: stopPresenting } = useFullscreen(root)
const exporting = ref(false)

const month = (key: string, format = 'MMM YYYY') => formatDateTime(`${key}-01`, format)
const periods = computed(() => (data.value?.months ?? []).map((key) => month(key)))
const span = computed(() => {
  const months = data.value?.months ?? []
  return months.length
    ? t('providerDashboard.span', { from: month(months[0]), to: month(months.at(-1) ?? months[0]) })
    : ''
})

const compact = (value: number) =>
  formatNumber(value, 0, { notation: 'compact', maximumFractionDigits: 1 })
const money = (currency: string) => (value: number) =>
  formatCurrency(value, currency, {
    notation: 'compact',
    maximumFractionDigits: Math.abs(value) < 1000 ? 0 : 1,
  })
const eur = money('EUR')
const percent = (fraction: number) => `${formatNumber(fraction * 100, fraction < 0.1 ? 1 : 0)}%`

type Change = { value: number; label: string; title?: string; inverse?: boolean }
type ChangeKind = 'relative' | 'absolute' | 'points'

/** The change from `base` to `value`: in percent, in the unit, or in percentage points. */
const changeOf = (
  value: number | null,
  base: number | null,
  kind: ChangeKind,
  format: (value: number) => string = compact
): { value: number; label: string } | null => {
  if (value === null || base === null) return null
  if (kind === 'points') {
    const points = (value - base) * 100
    return { value: points, label: `${formatNumber(Math.abs(points), 1)}pp` }
  }
  if (kind === 'absolute') return { value: value - base, label: format(Math.abs(value - base)) }
  if (base === 0) return null
  const ratio = (value - base) / Math.abs(base)
  return { value: ratio, label: percent(Math.abs(ratio)) }
}

interface Tile {
  key: string
  label: string
  value: string | null
  detail?: string
  change?: Change
  series?: Array<number | null>
  format?: (value: number) => string
  color?: string
}

interface TileSpec {
  key: string
  metric: ProviderMetric
  format: (value: number) => string
  detail: string
  kind?: ChangeKind
  color?: string
  /** A fall is the good direction (churn). */
  inverse?: boolean
}

/** A tile from a metric: month-over-month in the change slot, year-over-year in the detail line. */
const tile = ({
  key,
  metric,
  format,
  detail,
  kind = 'relative',
  color,
  inverse,
}: TileSpec): Tile => {
  const mom = changeOf(metric.current, metric.previous, kind, format)
  const yoy = changeOf(metric.current, metric.year_ago, kind, format)
  return {
    key,
    label: t(`providerDashboard.tiles.${key}`),
    value: metric.current === null ? '–' : format(metric.current),
    detail: yoy
      ? `${detail}, ${t('providerDashboard.change.yoy', { change: `${yoy.value < 0 ? '−' : '+'}${yoy.label}` })}`
      : detail,
    change: mom ? { ...mom, inverse, title: t('providerDashboard.change.mom') } : undefined,
    series: metric.series,
    format,
    color,
  }
}

/** Placeholder tiles keep their labels while loading so the grid holds still. */
const loading = (keys: string[]): Tile[] =>
  keys.map((key) => ({ key, label: t(`providerDashboard.tiles.${key}`), value: null }))

const revenueTiles = computed(() => {
  const revenue = data.value?.revenue
  if (!revenue) return loading(['mrr', 'net_new_mrr', 'paying_spaces', 'arpa', 'revenue_churn'])
  const last = revenue.movement.new.length - 1
  return [
    tile({
      key: 'mrr',
      metric: revenue.mrr,
      format: eur,
      detail: t('providerDashboard.details.arr', { value: eur(revenue.arr) }),
    }),
    tile({
      key: 'net_new_mrr',
      metric: revenue.net_new_mrr,
      format: eur,
      detail: t('providerDashboard.details.movement', {
        new: eur(revenue.movement.new[last] ?? 0),
        expansion: eur(revenue.movement.expansion[last] ?? 0),
        churn: eur(Math.abs(revenue.movement.churn[last] ?? 0)),
      }),
    }),
    tile({
      key: 'paying_spaces',
      metric: revenue.paying_spaces,
      format: compact,
      kind: 'absolute',
      detail: t(
        'providerDashboard.details.teams',
        { count: compact(revenue.paying_teams.current ?? 0) },
        revenue.paying_teams.current ?? 0
      ),
    }),
    tile({
      key: 'arpa',
      metric: revenue.arpa,
      format: eur,
      detail: t('providerDashboard.details.per_team'),
    }),
    tile({
      key: 'revenue_churn',
      metric: revenue.revenue_churn,
      format: percent,
      kind: 'points',
      color: 'var(--series-2)',
      inverse: true,
      detail: t('providerDashboard.details.churn', {
        rate: percent(revenue.logo_churn.current ?? 0),
      }),
    }),
  ]
})

const growthTiles = computed(() => {
  const growth = data.value?.growth
  if (!growth) return loading(['new_users', 'new_spaces', 'new_teams'])
  const thisMonth = t('providerDashboard.details.this_month')
  return [
    tile({ key: 'new_users', metric: growth.new_users, format: compact, detail: thisMonth }),
    tile({
      key: 'new_spaces',
      metric: growth.new_spaces,
      format: compact,
      detail: thisMonth,
      color: 'var(--series-2)',
    }),
    tile({
      key: 'new_teams',
      metric: growth.new_teams,
      format: compact,
      detail: thisMonth,
      color: 'var(--series-3)',
    }),
  ]
})

const engagementTiles = computed(() => {
  const engagement = data.value?.engagement
  if (!engagement) return loading(['api_requests', 'traffic'])
  return [
    tile({
      key: 'api_requests',
      metric: engagement.api_requests,
      format: compact,
      detail: t('providerDashboard.details.api'),
    }),
    tile({
      key: 'traffic',
      metric: engagement.traffic_bytes,
      format: formatFileSize,
      detail: t('providerDashboard.details.traffic'),
    }),
  ]
})

const movementSeries = computed(() => {
  const movement = data.value?.revenue?.movement
  return [
    {
      key: 'new',
      label: t('providerDashboard.series.new'),
      color: 'var(--series-1)',
      values: movement?.new ?? [],
    },
    {
      key: 'expansion',
      label: t('providerDashboard.series.expansion'),
      color: 'var(--series-3)',
      values: movement?.expansion ?? [],
    },
    {
      key: 'churn',
      label: t('providerDashboard.series.churn'),
      color: 'var(--series-2)',
      values: movement?.churn ?? [],
    },
  ]
})

const values = (metric: ProviderMetric | undefined) =>
  (metric?.series ?? []).map((value) => value ?? 0)

const signUpSeries = computed(() => {
  const growth = data.value?.growth
  return [
    {
      key: 'users',
      label: t('providerDashboard.series.users'),
      color: 'var(--series-1)',
      values: values(growth?.new_users),
    },
    {
      key: 'spaces',
      label: t('providerDashboard.series.spaces'),
      color: 'var(--series-2)',
      values: values(growth?.new_spaces),
    },
    {
      key: 'teams',
      label: t('providerDashboard.series.teams'),
      color: 'var(--series-3)',
      values: values(growth?.new_teams),
    },
  ]
})

const plans = computed(() =>
  (data.value?.revenue?.by_plan ?? []).map((plan) => ({ name: plan.name, count: plan.mrr }))
)

const cohorts = computed(() =>
  (data.value?.revenue?.retention ?? []).map((cohort) => ({
    label: month(cohort.month, 'MMM YY'),
    values: cohort.values,
  }))
)

async function exportPng() {
  if (!root.value) return
  exporting.value = true
  try {
    await downloadPng(root.value, `b10cks-dashboard-${data.value?.months.at(-1) ?? 'export'}`)
  } catch {
    toast.error(t('providerDashboard.actions.export_failed'))
  } finally {
    exporting.value = false
  }
}

function exportPdf() {
  if (root.value) printElement(root.value)
}
</script>

<template>
  <div
    ref="root"
    class="content-grid w-full bg-background pb-10 [&:fullscreen]:overflow-y-auto [&:fullscreen]:px-10"
  >
    <ContentHeader :header="t('providerDashboard.title')">
      <template #actions>
        <span
          v-if="presenting"
          class="flex items-center gap-4 text-sm text-muted tabular-nums"
        >
          {{ span }}
          <Button
            variant="outline"
            size="sm"
            data-export-hidden
            @click="stopPresenting"
          >
            <Icon name="lucide:x" />
            {{ t('providerDashboard.actions.exit') }}
          </Button>
        </span>
        <div
          v-else
          class="flex items-center gap-2"
          data-export-hidden
        >
          <SelectField
            v-model="range"
            name="providerRange"
            :options="[
              { value: '12m', label: t('providerDashboard.range.12m') },
              { value: 'ytd', label: t('providerDashboard.range.ytd') },
              { value: '6m', label: t('providerDashboard.range.6m') },
              { value: '24m', label: t('providerDashboard.range.24m') },
            ]"
          />
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button
                variant="outline"
                :loading="exporting"
                :disabled="!data"
              >
                <Icon name="lucide:download" />
                {{ t('providerDashboard.actions.export') }}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem @select="exportPng">
                <Icon name="lucide:image" />
                {{ t('providerDashboard.actions.png') }}
              </DropdownMenuItem>
              <DropdownMenuItem @select="exportPdf">
                <Icon name="lucide:file-text" />
                {{ t('providerDashboard.actions.pdf') }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button
            variant="primary"
            :disabled="!data"
            @click="present"
          >
            <Icon name="lucide:maximize" />
            {{ t('providerDashboard.actions.present') }}
          </Button>
        </div>
      </template>
    </ContentHeader>

    <div
      class="mt-6 grid gap-8 transition-opacity duration-100"
      :class="isFetching && !isLoading && 'opacity-60'"
    >
      <p
        v-if="error && !data"
        class="rounded-xl border border-destructive/30 bg-destructive/8 px-5 py-4 text-primary"
      >
        {{ error.message }}
      </p>

      <section
        v-if="billing"
        class="grid gap-4"
      >
        <h2 class="text-panel font-semibold text-primary select-none">
          {{ t('providerDashboard.sections.revenue') }}
        </h2>
        <dl class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-5">
          <StatTile
            v-for="item in revenueTiles"
            v-bind="item"
            :key="item.key"
            :periods="periods"
          />
        </dl>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Panel
            :title="t('providerDashboard.panels.mrr_movement')"
            class="lg:col-span-2"
          >
            <StackedBarChart
              v-if="data"
              :periods="periods"
              :series="movementSeries"
              :format="eur"
              :height="208"
            />
            <Skeleton
              v-else
              class="h-[268px] w-full"
            />
          </Panel>
          <Panel :title="t('providerDashboard.panels.revenue_by_plan')">
            <DistributionList
              v-if="data"
              :items="plans"
              :format="eur"
            />
            <Skeleton
              v-else
              class="h-[268px] w-full"
            />
          </Panel>
        </div>
      </section>

      <section class="grid gap-4">
        <h2 class="text-panel font-semibold text-primary select-none">
          {{ t('providerDashboard.sections.growth') }}
        </h2>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <StatTile
            v-for="item in growthTiles"
            v-bind="item"
            :key="item.key"
            :periods="periods"
          />
        </dl>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Panel
            :title="t('providerDashboard.panels.sign_ups')"
            :class="billing ? 'lg:col-span-2' : 'lg:col-span-3'"
          >
            <TrendChart
              v-if="data"
              :periods="periods"
              :series="signUpSeries"
              :format="compact"
              :height="208"
            />
            <Skeleton
              v-else
              class="h-[268px] w-full"
            />
          </Panel>
          <Panel
            v-if="billing"
            :title="t('providerDashboard.panels.retention')"
            :meta="t('providerDashboard.panels.retention_meta')"
          >
            <ProviderRetentionGrid
              v-if="data"
              :cohorts="cohorts"
            />
            <Skeleton
              v-else
              class="h-[268px] w-full"
            />
          </Panel>
        </div>
      </section>

      <section class="grid gap-4">
        <h2 class="text-panel font-semibold text-primary select-none">
          {{ t('providerDashboard.sections.engagement') }}
        </h2>
        <dl class="grid grid-cols-2 gap-4">
          <StatTile
            v-for="item in engagementTiles"
            v-bind="item"
            :key="item.key"
            :periods="periods"
          />
        </dl>
      </section>

    </div>
  </div>
</template>
