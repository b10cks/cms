<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import InvoicesTable from '~/components/space-settings/usage/InvoicesTable.vue'
import LiveUsageCard from '~/components/space-settings/usage/LiveUsageCard.vue'
import PeriodHistoryTable from '~/components/space-settings/usage/PeriodHistoryTable.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Skeleton } from '~/components/ui/skeleton'

const StatsLineChart = defineAsyncComponent(() => import('~/components/stats/StatsLineChart.vue'))

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string

const { useUsageHistoryQuery, useUsageTimeseriesQuery } = useUsageHistory(spaceId)
const { useInvoicesQuery } = useInvoices(spaceId)
const { useCurrentSubscriptionQuery } = useSubscription(spaceId)
const { useUsageQuery } = useSpaceUsage(spaceId)

const { data: periods, isLoading: periodsLoading } = useUsageHistoryQuery()
const { data: invoices, isLoading: invoicesLoading } = useInvoicesQuery()
const { data: current } = useCurrentSubscriptionQuery()
const { data: liveUsage } = useUsageQuery()

useSeoMeta({
  title: computed(() => t('labels.usage.history.title')),
})

const selectedPeriodId = ref<string | null>(null)

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

const { data: timeseries, isLoading: timeseriesLoading } = useUsageTimeseriesQuery(selectedPeriodId)

const selectedPeriod = computed(() =>
  (periods.value ?? []).find((p) => p.id === selectedPeriodId.value)
)

const chartData = computed(() =>
  (timeseries.value?.points ?? []).map((point) => ({
    name: new Date(point.date).toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
    }),
    value: +(point.value / (1024 * 1024)).toFixed(2),
  }))
)

const chartLabel = computed(() => t('labels.usage.history.trafficMb'))
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

    <!-- Live usage against the current plan quotas -->
    <LiveUsageCard
      :usage="liveUsage"
      class="mb-6"
    />

    <!-- In-period trend chart -->
    <Card v-if="periodsLoading || selectedPeriod">
      <CardHeader>
        <CardTitle
          v-if="selectedPeriod"
          class="text-base"
        >
          {{ selectedPeriod.plan_name }} ·
          {{ $t('labels.usage.history.trend') }}
        </CardTitle>
        <Skeleton
          v-else
          class="h-5 w-48"
        />
      </CardHeader>
      <CardContent>
        <Skeleton
          v-if="periodsLoading || timeseriesLoading"
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
        :loading="periodsLoading"
        @select="selectedPeriodId = $event"
      />
    </div>

    <!-- Invoices -->
    <div class="mt-6 space-y-2">
      <h3 class="text-sm font-semibold text-primary">
        {{ $t('labels.invoices.title') }}
      </h3>
      <InvoicesTable
        :invoices="invoices ?? []"
        :loading="invoicesLoading"
      />
    </div>
  </div>
</template>
