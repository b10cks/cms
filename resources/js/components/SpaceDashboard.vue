<script setup lang="ts">
import { api } from '~/api'
import StatsCard from '~/components/stats/StatsCard.vue'
import StatsLineChart from '~/components/stats/StatsLineChart.vue'
import StatsMultiLineChart from '~/components/stats/StatsMultiLineChart.vue'
import StatsPieChart from '~/components/stats/StatsPieChart.vue'
import { installChart } from '~/plugins/chart'
import { SelectField } from './ui/form'

installChart()

const { formatDuration, formatNumber, formatTrafficSize, formatFileSize } = useFormat()
const { t } = useI18n()

const props = defineProps<{
  spaceId: string
}>()

const loading = ref(true)
const error = ref<string | null>(null)
const stats = ref<any>(null)
const period = ref('daily')
const dateRange = ref('thisMonth')

const dateRangeValues = computed(() => {
  const now = new Date()
  let startDate: Date
  const endDate = new Date()

  switch (dateRange.value) {
    case 'last7':
      startDate = new Date(now)
      startDate.setDate(now.getDate() - 7)
      break
    case 'last30':
      startDate = new Date(now)
      startDate.setDate(now.getDate() - 30)
      break
    case 'last90':
      startDate = new Date(now)
      startDate.setDate(now.getDate() - 90)
      break
    case 'thisMonth':
      startDate = new Date(now.getFullYear(), now.getMonth(), 1)
      break
    case 'thisYear':
      startDate = new Date(now.getFullYear(), 0, 1)
      break
    default:
      startDate = new Date(now)
      startDate.setDate(now.getDate() - 30)
  }

  return {
    startDate: formatDateToISO(startDate),
    endDate: formatDateToISO(endDate),
  }
})

const fetchDashboardStats = async () => {
  loading.value = true
  error.value = null
  const client = api.client

  try {
    const { startDate, endDate } = dateRangeValues.value
    stats.value = await client.get(`/mgmt/v1/spaces/${props.spaceId}/stats`, {
      period: period.value,
      start_date: startDate,
      end_date: endDate,
    })
  } catch (err: any) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const formatDateToISO = (date: Date): string => {
  return date.toISOString()
}

const formatRoleDistribution = (roleDistribution: Record<string, number>): string => {
  return Object.entries(roleDistribution)
    .map(([role, count]) => `${count} ${role}`)
    .join(', ')
}

// Chart data transformers
const assetsByTypeChartData = computed(() => {
  if (!stats.value?.assets?.count?.by_type) return []
  return stats.value.assets.count.by_type.map((item: any) => ({
    name: item.category,
    count: item.count,
  }))
})

const contentByTypeChartData = computed(() => {
  if (!stats.value?.content?.by_type) return []
  return stats.value.content.by_type.map((item: any) => ({
    name: item.name,
    color: item.color,
    count: item.count,
  }))
})

const trafficUsageChartData = computed(() => {
  if (!stats.value?.trends?.traffic_usage) return []
  return Object.entries(stats.value.trends.traffic_usage).map(([date, data]: [string, any]) => ({
    name: date,
    value: data.total_bytes / (1024 * 1024),
  }))
})

const apiUsageChartData = computed(() => {
  if (!stats.value?.trends?.api_usage) return []
  return Object.entries(stats.value.trends.api_usage).map(([date, count]) => ({
    name: date,
    value: count as number,
  }))
})

const assetUploadTrendData = computed(() => {
  if (!stats.value?.trends?.asset_uploads) return { labels: [], datasets: [] }

  const entries = Object.entries(stats.value.trends.asset_uploads)
  const labels = entries.map(([date]) => date)

  return {
    labels,
    datasets: [
      {
        label: t('dashboard.charts.upload_size_mb'),
        data: entries.map(([, data]: [string, any]) => data.total_size / (1024 * 1024)),
        color: 'rgba(236, 72, 153, 0.8)',
      },
    ],
  }
})

const contentActivityData = computed(() => {
  if (!stats.value?.trends?.content_creation) return { labels: [], datasets: [] }

  const labels = stats.value.trends.periods || []
  const creation = labels.map((period: string) => stats.value.trends.content_creation[period] || 0)
  const editing = labels.map((period: string) => stats.value.trends.content_editing[period] || 0)
  const publishing = labels.map(
    (period: string) => stats.value.trends.content_publishing[period] || 0
  )

  return {
    labels,
    datasets: [
      {
        label: t('dashboard.charts.content_created'),
        data: creation,
        color: 'rgba(139, 92, 246, 0.8)',
      },
      {
        label: t('dashboard.charts.content_edited'),
        data: editing,
        color: 'rgba(251, 146, 60, 0.8)',
      },
      {
        label: t('dashboard.charts.content_published'),
        data: publishing,
        color: 'rgba(34, 197, 94, 0.8)',
      },
    ],
  }
})

watch([period, dateRange], () => {
  fetchDashboardStats()
})

onMounted(() => {
  fetchDashboardStats()
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div class="ml-auto flex space-x-4">
        <SelectField
          name="dateRange"
          v-model="dateRange"
          :placeholder="t('dashboard.filters.date_range')"
          :options="[
            { value: 'last7', label: t('dashboard.filters.last_7_days') },
            { value: 'last30', label: t('dashboard.filters.last_30_days') },
            { value: 'last90', label: t('dashboard.filters.last_90_days') },
            { value: 'thisMonth', label: t('dashboard.filters.this_month') },
            { value: 'thisYear', label: t('dashboard.filters.this_year') },
          ]"
        />

        <SelectField
          name="period"
          v-model="period"
          :placeholder="t('dashboard.filters.period')"
          :options="[
            { value: 'daily', label: t('dashboard.filters.daily') },
            { value: 'weekly', label: t('dashboard.filters.weekly') },
            { value: 'monthly', label: t('dashboard.filters.monthly') },
          ]"
        />
      </div>
    </div>

    <div
      v-if="loading"
      class="flex h-64 items-center justify-center"
    >
      <div class="text-center">
        <div
          class="spinner mx-auto h-8 w-8 animate-spin rounded-full border-4 border-accent border-t-transparent"
        />
        <p class="mt-2 text-muted">{{ t('dashboard.loading') }}</p>
      </div>
    </div>

    <div
      v-else-if="error"
      class="p-8 text-center"
    >
      <p class="text-destructive">{{ error }}</p>
    </div>

    <div
      v-else-if="!stats"
      class="p-8 text-center"
    >
      <p class="text-muted">{{ t('dashboard.no_data') }}</p>
    </div>

    <template v-else>
      <h2 class="text-lg font-semibold text-primary">
        {{ t('dashboard.sections.content_stats') }}
      </h2>
      <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
        <StatsCard
          icon="lucide:book-open"
          :title="t('dashboard.cards.total_content')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(stats.content.count.total) }}
          </div>
          <p class="text-sm text-muted">
            {{ formatNumber(stats.content.count.published) }} {{ t('dashboard.cards.published') }},
            {{ formatNumber(stats.content.count.draft) }} {{ t('dashboard.cards.draft') }}
          </p>
        </StatsCard>

        <StatsCard
          icon="lucide:images"
          :title="t('dashboard.cards.assets')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatFileSize(stats.assets.storage.total_size) }}
          </div>
          <p class="text-sm text-muted">
            {{ formatNumber(stats.assets.count.total) }} {{ t('dashboard.cards.assets_count') }}
          </p>
        </StatsCard>

        <StatsCard
          icon="lucide:blocks"
          :title="t('dashboard.cards.blocks')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(stats.content.count.blocks) }}
          </div>
        </StatsCard>

        <StatsCard
          icon="lucide:database-zap"
          :title="t('dashboard.cards.data_sources')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(stats.data_sources.data_sources.count.total) }}
          </div>
        </StatsCard>

        <StatsCard
          icon="lucide:split"
          :title="t('dashboard.cards.redirects')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(stats.redirects.count.total) }}
          </div>
        </StatsCard>
      </div>

      <h2 class="text-lg font-semibold text-primary">
        {{ t('dashboard.sections.content_distribution') }}
      </h2>
      <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <StatsPieChart
          :title="t('dashboard.charts.content_by_type')"
          :data="contentByTypeChartData"
          :height="300"
        />
        <StatsPieChart
          :title="t('dashboard.charts.assets_by_type')"
          :data="assetsByTypeChartData"
          :height="300"
        />
      </div>

      <h2 class="text-lg font-semibold text-primary">{{ t('dashboard.sections.usage_stats') }}</h2>
      <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
        <StatsCard
          icon="lucide:cloud-download"
          :title="t('dashboard.cards.traffic_usage')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatTrafficSize(stats.system.traffic.total_bytes, 'MB') }}
          </div>
          <p class="text-sm text-muted">
            {{ formatNumber(stats.system.traffic.cache_hit_rate, 2) }}%
            {{ t('dashboard.cards.cache_hit_rate') }}
          </p>
        </StatsCard>

        <StatsCard
          icon="lucide:server"
          :title="t('dashboard.cards.api_requests')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(stats.system.api.total_requests) }}
          </div>
          <p class="text-sm text-muted">
            {{ formatNumber(stats.system.api.success_rate, 2) }}%
            {{ t('dashboard.cards.success_rate') }}
          </p>
        </StatsCard>

        <StatsCard
          icon="lucide:zap"
          :title="t('dashboard.cards.avg_response_time')"
          :description="t('dashboard.cards.api_response_time')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatDuration(stats.system.api.avg_response_time_ms) }}
          </div>
        </StatsCard>
      </div>

      <h2 class="text-lg font-semibold text-primary">{{ t('dashboard.sections.trends') }}</h2>
      <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <StatsLineChart
          :title="t('dashboard.charts.traffic_usage')"
          :data="trafficUsageChartData"
          :height="300"
          :y-axis-label="t('dashboard.charts.mb')"
          color="rgba(59, 130, 246, 1)"
        />
        <StatsLineChart
          :title="t('dashboard.charts.api_usage')"
          :data="apiUsageChartData"
          :height="300"
          :y-axis-label="t('dashboard.charts.requests')"
          color="rgba(139, 92, 246, 1)"
        />
      </div>

      <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <StatsMultiLineChart
          :title="t('dashboard.charts.asset_upload_trend')"
          :labels="assetUploadTrendData.labels"
          :datasets="assetUploadTrendData.datasets"
          :height="300"
        />
        <StatsMultiLineChart
          :title="t('dashboard.charts.content_activity')"
          :labels="contentActivityData.labels"
          :datasets="contentActivityData.datasets"
          :height="300"
        />
      </div>

      <h2 class="text-lg font-semibold text-primary">{{ t('dashboard.sections.space_stats') }}</h2>
      <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
        <StatsCard
          icon="lucide:users"
          :title="t('dashboard.cards.total_users')"
          :description="formatRoleDistribution(stats.user_activity.role_distribution)"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(stats.user_activity.total_users) }}
          </div>
        </StatsCard>

        <StatsCard
          icon="lucide:globe"
          :title="t('dashboard.cards.languages')"
        >
          <div class="text-3xl font-bold text-primary">
            {{ formatNumber(Object.keys(stats.content.languages).length) }}
          </div>
        </StatsCard>
      </div>
    </template>
  </div>
</template>
