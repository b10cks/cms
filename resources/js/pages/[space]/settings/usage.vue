<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import InvoicesTable from '~/components/space-settings/usage/InvoicesTable.vue'
import PeriodHistoryTable from '~/components/space-settings/usage/PeriodHistoryTable.vue'
import StatsLineChart from '~/components/stats/StatsLineChart.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Skeleton } from '~/components/ui/skeleton'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string

const { useUsageHistoryQuery, useUsageTimeseriesQuery } = useUsageHistory(spaceId)
const { useInvoicesQuery } = useInvoices(spaceId)
const { useCurrentSubscriptionQuery } = useSubscription(spaceId)

const { data: periods, isLoading: periodsLoading } = useUsageHistoryQuery()
const { data: invoices, isLoading: invoicesLoading } = useInvoicesQuery()
const { data: current } = useCurrentSubscriptionQuery()

useSeoMeta({
  title: computed(() => t('labels.usage.history.title')),
})

const selectedPeriodId = ref<string | null>(null)
const selectedMetric = ref<UsageTimeseriesMetric>('traffic')

// Default to the newest period once history loads (and recover if it disappears).
watch(
  periods,
  (list) => {
    if (!list?.length) {
      selectedPeriodId.value = null
      return
    }
    if (!selectedPeriodId.value || !list.some((p) => p.id === selectedPeriodId.value)) {
      selectedPeriodId.value = list[0].id
    }
  },
  { immediate: true }
)

const { data: timeseries, isLoading: timeseriesLoading } = useUsageTimeseriesQuery(
  selectedPeriodId,
  selectedMetric
)

const selectedPeriod = computed(() =>
  (periods.value ?? []).find((p) => p.id === selectedPeriodId.value)
)

const isTraffic = computed(() => selectedMetric.value === 'traffic')

const chartData = computed(() =>
  (timeseries.value?.points ?? []).map((point) => ({
    name: new Date(point.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
    value: isTraffic.value ? +(point.value / (1024 * 1024)).toFixed(2) : point.value,
  }))
)

const chartLabel = computed(() =>
  isTraffic.value ? t('labels.usage.history.trafficMb') : t('labels.plans.quotas.requests')
)
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.usage.history.title')"
      :description="$t('labels.usage.history.description')"
    >
      <template #actions>
        <Button
          v-if="current?.billing_portal_url"
          variant="outline"
          as="a"
          :href="current.billing_portal_url"
          target="_blank"
        >
          <Icon name="lucide:external-link" />
          {{ $t('actions.subscriptions.manageBilling') }}
        </Button>
      </template>
    </ContentHeader>

    <div
      v-if="periodsLoading"
      class="space-y-6"
    >
      <Card>
        <CardHeader>
          <div class="flex items-center justify-between gap-2">
            <Skeleton class="h-5 w-48" />
            <Skeleton class="h-8 w-40" />
          </div>
        </CardHeader>
        <CardContent>
          <Skeleton class="h-[260px] w-full" />
        </CardContent>
      </Card>
      <div class="mt-6 space-y-2">
        <Skeleton class="h-4 w-32" />
        <div class="space-y-2 rounded-lg border p-3">
          <Skeleton
            v-for="i in 3"
            :key="i"
            class="h-10 w-full"
          />
        </div>
      </div>
    </div>

    <template v-else>
      <!-- In-period trend chart -->
      <Card v-if="selectedPeriod">
        <CardHeader>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <CardTitle class="text-base">
              {{ selectedPeriod.plan_name }} · {{ $t('labels.usage.history.trend') }}
            </CardTitle>
            <div class="flex gap-1">
              <Button
                size="sm"
                :variant="isTraffic ? 'default' : 'outline'"
                @click="selectedMetric = 'traffic'"
              >
                {{ $t('labels.plans.quotas.traffic') }}
              </Button>
              <Button
                size="sm"
                :variant="!isTraffic ? 'default' : 'outline'"
                @click="selectedMetric = 'requests'"
              >
                {{ $t('labels.plans.quotas.requests') }}
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <Skeleton
            v-if="timeseriesLoading"
            class="h-[260px] w-full"
          />
          <StatsLineChart
            v-else-if="chartData.length"
            :title="chartLabel"
            :y-axis-label="chartLabel"
            :data="chartData"
            :height="260"
          />
          <p
            v-else
            class="py-8 text-center text-sm text-muted"
          >
            {{ $t('labels.usage.history.noTrend') }}
          </p>
        </CardContent>
      </Card>

      <!-- Period history -->
      <div class="mt-6 space-y-2">
        <h3 class="text-sm font-semibold text-primary">
          {{ $t('labels.usage.history.periods') }}
        </h3>
        <PeriodHistoryTable
          :periods="periods ?? []"
          :selected-id="selectedPeriodId"
          @select="selectedPeriodId = $event"
        />
      </div>

      <!-- Invoices -->
      <div class="mt-6 space-y-2">
        <h3 class="text-sm font-semibold text-primary">{{ $t('labels.invoices.title') }}</h3>
        <InvoicesTable
          :invoices="invoices ?? []"
          :loading="invoicesLoading"
        />
      </div>
    </template>
  </div>
</template>
