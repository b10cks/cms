<script setup lang="ts">
import { keepPreviousData, useQuery } from '@tanstack/vue-query'

import { api } from '~/api'
import type { ActivityCalendar } from '~/components/stats/StatsActivityHeatmap.vue'
import StatTile from '~/components/stats/StatTile.vue'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'

// Keep the chart code out of the landing route's chunk; the dashboard shell paints
// immediately and the charts stream in.
const TrendChart = defineAsyncComponent(() => import('~/components/stats/TrendChart.vue'))
const DistributionList = defineAsyncComponent(
  () => import('~/components/stats/DistributionList.vue')
)
const StatsActivityHeatmap = defineAsyncComponent(
  () => import('~/components/stats/StatsActivityHeatmap.vue')
)

/** The parts of `GET /spaces/{space}/stats` the dashboard reads. */
interface SpaceStats {
  content: {
    count: {
      total: number
      new: number
      published: number
      draft: number
      blocks: number
      new_blocks: number
    }
    by_type: { name: string; color: string | null; count: number }[]
    languages: Record<string, number>
  }
  assets: {
    count: { total: number; by_type: { category: string; count: number }[] }
    storage: { total_size: number; new_size: number }
  }
  redirects: { count: { total: number; new: number } }
  data_sources: {
    data_sources: { count: { total: number; new: number } }
    data_entries: {
      count: { total: number }
      by_data_source: { data_source_name: string; count: number }[]
    }
  }
  user_activity: {
    total_users: number
    new_users: number
    role_distribution: Record<string, number>
  }
  activity?: ActivityCalendar
  system: {
    api: { total_requests: number; success_rate: number; avg_response_time_ms: number }
    traffic: { total_bytes: number; cache_hit_rate: number }
  }
  trends: {
    periods: string[]
    content_creation: Record<string, number>
    content_editing: Record<string, number>
    content_publishing: Record<string, number>
    asset_uploads: Record<string, { count: number; total_size: number }>
    redirect_creation: Record<string, number>
    block_creation: Record<string, number>
    data_source_creation: Record<string, number>
    data_entry_creation: Record<string, number>
    api_usage: Record<string, number>
    response_time: Record<string, number | null>
    traffic_usage: Record<string, { total_bytes: number; request_count: number }>
  }
}

type Change = { value: number; label: string; title?: string }

const props = defineProps<{
  spaceId: string
  period: 'daily' | 'weekly' | 'monthly'
  dateRange: string
}>()

const { formatDuration, formatNumber, formatFileSize, formatDateTime } = useFormat()
const { t } = useI18n()

const startOf = (range: string): Date => {
  const now = new Date()
  const daysAgo = (days: number) => new Date(now.getTime() - days * 86_400_000)
  switch (range) {
    case 'last7':
      return daysAgo(7)
    case 'last90':
      return daysAgo(90)
    case 'thisMonth':
      return new Date(now.getFullYear(), now.getMonth(), 1)
    case 'thisYear':
      return new Date(now.getFullYear(), 0, 1)
    default:
      return daysAgo(30)
  }
}

const {
  data: stats,
  isLoading,
  isFetching,
  error,
} = useQuery({
  queryKey: computed(
    () => ['spaces', props.spaceId, 'stats', props.period, props.dateRange] as const
  ),
  queryFn: () =>
    api.client.get<SpaceStats>(`/mgmt/v1/spaces/${props.spaceId}/stats`, {
      period: props.period,
      start_date: startOf(props.dateRange).toISOString(),
      end_date: new Date().toISOString(),
    }),
  placeholderData: keepPreviousData,
  enabled: computed(() => !!props.spaceId),
})

const count = (value: number) => formatNumber(value)
const percent = (value: number) => formatNumber(value, value < 100 ? 1 : 0)

const periods = computed(() => stats.value?.trends.periods ?? [])
const periodLabels = computed(() =>
  periods.value.map((period) =>
    formatDateTime(period, props.period === 'monthly' ? 'MMM YYYY' : 'D MMM')
  )
)
const valuesOf = <T,>(values: Record<string, T> | undefined, pick: (value: T) => number) =>
  periods.value.map((period) => {
    const value = values?.[period]
    return value === undefined ? 0 : pick(value)
  })
const counts = (values: Record<string, number> | undefined) =>
  valuesOf(values, (value) => value)
const sum = (values: number[]) => values.reduce((total, value) => total + value, 0)

const changeOf = (value: number, format: (value: number) => string = count): Change => ({
  value,
  label: format(Math.abs(value)),
  title: t('dashboard.tiles.new_in_range'),
})

interface Tile {
  key: string
  label: string
  value: string | null
  detail?: string
  change?: Change
  series?: Array<number | null>
  format?: (value: number) => string
  relative?: boolean
}

/** Builds a row of tiles; while loading every tile keeps its label and the grid holds still. */
const tilesOf = (build: (data: SpaceStats) => Tile[], keys: string[]): Tile[] => {
  const data = stats.value
  if (data) return build(data)
  return keys.map((key) => ({ key, label: t(`dashboard.tiles.${key}`), value: null }))
}

const contentTiles = computed(() =>
  tilesOf(
    ({ content, data_sources, redirects, trends }) => [
      {
        key: 'entries',
        label: t('dashboard.tiles.entries'),
        value: count(content.count.total),
        detail: t(
          'dashboard.tiles.drafts',
          { count: count(content.count.draft) },
          content.count.draft
        ),
        change: changeOf(content.count.new),
        series: counts(trends.content_creation),
        format: count,
        relative: true,
      },
      {
        key: 'blocks',
        label: t('dashboard.tiles.blocks'),
        value: count(content.count.blocks),
        change: changeOf(content.count.new_blocks),
        series: counts(trends.block_creation),
        format: count,
        relative: true,
      },
      {
        key: 'data_sources',
        label: t('dashboard.tiles.data_sources'),
        value: count(data_sources.data_sources.count.total),
        change: changeOf(data_sources.data_sources.count.new),
        series: counts(trends.data_source_creation),
        format: count,
        relative: true,
      },
      {
        key: 'data_entries',
        label: t('dashboard.tiles.data_entries'),
        value: count(data_sources.data_entries.count.total),
        change: changeOf(sum(counts(trends.data_entry_creation))),
        series: counts(trends.data_entry_creation),
        format: count,
        relative: true,
      },
      {
        key: 'redirects',
        label: t('dashboard.tiles.redirects'),
        value: count(redirects.count.total),
        change: changeOf(redirects.count.new),
        series: counts(trends.redirect_creation),
        format: count,
        relative: true,
      },
      {
        key: 'languages',
        label: t('dashboard.tiles.languages'),
        value: count(Object.keys(content.languages).length),
      },
    ],
    ['entries', 'blocks', 'data_sources', 'data_entries', 'redirects', 'languages']
  )
)

const deliveryTiles = computed(() =>
  tilesOf(
    ({ system, trends }) => [
      {
        key: 'traffic',
        label: t('dashboard.tiles.traffic'),
        value: formatFileSize(system.traffic.total_bytes),
        detail: t('dashboard.tiles.cached', { rate: percent(system.traffic.cache_hit_rate) }),
        series: valuesOf(trends.traffic_usage, (bucket) => bucket.total_bytes),
        format: formatFileSize,
      },
      {
        key: 'api',
        label: t('dashboard.tiles.api'),
        value: count(system.api.total_requests),
        detail: t('dashboard.tiles.success', { rate: percent(system.api.success_rate) }),
        series: counts(trends.api_usage),
        format: count,
      },
      {
        key: 'response_time',
        label: t('dashboard.tiles.response_time'),
        value: formatDuration(system.api.avg_response_time_ms),
        detail: t('dashboard.tiles.response_detail'),
        series: periods.value.map((period) => trends.response_time[period] ?? null),
        format: (value: number) => formatDuration(value),
      },
    ],
    ['traffic', 'api', 'response_time']
  )
)

const assetTiles = computed(() =>
  tilesOf(
    ({ assets, trends }) => [
      {
        key: 'storage',
        label: t('dashboard.tiles.storage'),
        value: formatFileSize(assets.storage.total_size),
        detail: t(
          'dashboard.tiles.files',
          { count: count(assets.count.total) },
          assets.count.total
        ),
        change: changeOf(assets.storage.new_size, formatFileSize),
        series: valuesOf(trends.asset_uploads, (bucket) => bucket.total_size),
        format: formatFileSize,
        relative: true,
      },
    ],
    ['storage']
  )
)

const teamTiles = computed(() =>
  tilesOf(
    ({ user_activity: users, activity }) => [
      {
        key: 'people',
        label: t('dashboard.tiles.people'),
        value: count(users.total_users),
        detail: Object.entries(users.role_distribution)
          .map(([role, amount]) => `${amount} ${role}`)
          .join(', '),
        change: changeOf(users.new_users),
      },
      {
        key: 'events',
        label: t('dashboard.tiles.events'),
        value: count(activity?.total ?? 0),
        detail: t(
          'dashboard.tiles.active_days',
          { count: count(activity?.active_days ?? 0) },
          activity?.active_days ?? 0
        ),
      },
    ],
    ['people', 'events']
  )
)

const activitySeries = computed(() => {
  const trends = stats.value?.trends
  return [
    {
      key: 'edited',
      label: t('dashboard.charts.edited'),
      color: 'var(--series-1)',
      values: counts(trends?.content_editing),
    },
    {
      key: 'published',
      label: t('dashboard.charts.published'),
      color: 'var(--series-3)',
      values: counts(trends?.content_publishing),
    },
    {
      key: 'created',
      label: t('dashboard.charts.created'),
      color: 'var(--series-2)',
      values: counts(trends?.content_creation),
    },
  ]
})

const entriesBySource = computed(() =>
  (stats.value?.data_sources.data_entries.by_data_source ?? []).map((item) => ({
    name: item.data_source_name,
    count: item.count,
  }))
)

const assetTypes = computed(() =>
  (stats.value?.assets.count.by_type ?? []).map((item) => ({
    name: item.category,
    count: item.count,
  }))
)
</script>

<template>
  <div
    class="grid gap-8 transition-opacity duration-100"
    :class="isFetching && !isLoading && 'opacity-60'"
  >
    <p
      v-if="error && !stats"
      class="rounded-xl border border-destructive/30 bg-destructive/8 px-5 py-4 text-primary"
    >
      {{ error.message }}
    </p>

    <section class="grid gap-4">
      <h2 class="text-panel font-semibold text-primary select-none">
        {{ t('dashboard.sections.content') }}
      </h2>
      <dl class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
        <StatTile
          v-for="tile in contentTiles"
          v-bind="tile"
          :key="tile.key"
          :periods="periodLabels"
        />
      </dl>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Panel
          :title="t('dashboard.panels.content_activity')"
          class="md:col-span-2"
        >
          <TrendChart
            v-if="stats"
            :periods="periodLabels"
            :series="activitySeries"
            :format="count"
            :height="192"
          />
          <Skeleton
            v-else
            class="h-[252px] w-full"
          />
        </Panel>
        <Panel :title="t('dashboard.panels.content_by_type')">
          <DistributionList
            v-if="stats"
            :items="stats.content.by_type"
            :format="count"
          />
          <Skeleton
            v-else
            class="h-[252px] w-full"
          />
        </Panel>
        <Panel :title="t('dashboard.panels.entries_by_source')">
          <DistributionList
            v-if="stats"
            :items="entriesBySource"
            :format="count"
          />
          <Skeleton
            v-else
            class="h-[252px] w-full"
          />
        </Panel>
      </div>
    </section>

    <section class="grid gap-4">
      <h2 class="text-panel font-semibold text-primary select-none">
        {{ t('dashboard.sections.delivery') }}
      </h2>
      <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <StatTile
          v-for="tile in deliveryTiles"
          v-bind="tile"
          :key="tile.key"
          :periods="periodLabels"
        />
      </dl>
    </section>

    <section class="grid gap-4">
      <h2 class="text-panel font-semibold text-primary select-none">
        {{ t('dashboard.sections.assets') }}
      </h2>
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <dl class="grid content-start gap-4">
          <StatTile
            v-for="tile in assetTiles"
            v-bind="tile"
            :key="tile.key"
            :periods="periodLabels"
          />
        </dl>
        <Panel
          :title="t('dashboard.panels.assets_by_type')"
          class="lg:col-span-2"
        >
          <DistributionList
            v-if="stats"
            :items="assetTypes"
            :format="count"
          />
          <Skeleton
            v-else
            class="h-[120px] w-full"
          />
        </Panel>
      </div>
    </section>

    <section class="grid gap-4">
      <h2 class="text-panel font-semibold text-primary select-none">
        {{ t('dashboard.sections.team') }}
      </h2>
      <dl class="grid grid-cols-2 gap-4">
        <StatTile
          v-for="tile in teamTiles"
          v-bind="tile"
          :key="tile.key"
        />
      </dl>
      <Panel :title="t('dashboard.panels.activity')">
        <template #actions>
          <RouterLink
            class="rounded-md px-2 py-1 text-sm text-muted transition-colors duration-100 hover:bg-muted-background hover:text-primary"
            :to="{ name: 'space-audit-logs', params: { space: spaceId } }"
          >
            {{ t('dashboard.activity.view_log') }}
          </RouterLink>
        </template>
        <StatsActivityHeatmap
          v-if="stats?.activity"
          :activity="stats.activity"
        />
        <Skeleton
          v-else
          class="h-[220px] w-full"
        />
      </Panel>
    </section>
  </div>
</template>
