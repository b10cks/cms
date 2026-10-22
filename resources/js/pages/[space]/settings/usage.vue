<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import InvoicesTable from '~/components/space-settings/usage/InvoicesTable.vue'
import LiveUsageCard from '~/components/space-settings/usage/LiveUsageCard.vue'
import PeriodHistoryTable from '~/components/space-settings/usage/PeriodHistoryTable.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'

const TrendChart = defineAsyncComponent(() => import('~/components/stats/TrendChart.vue'))

const route = useRoute()
const { t } = useI18n()
const { formatDateTime, formatFileSize } = useFormat()
const spaceId = route.params.space as string

const { useUsageHistoryQuery, useUsageTimeseriesQuery } = useUsageHistory(spaceId)
const { useInvoicesQuery } = useInvoices(spaceId)
const { useCurrentSubscriptionQuery } = useSubscription(spaceId)
const { useUsageQuery } = useSpaceUsage(spaceId)

const { data: periods, isLoading: periodsLoading } = useUsageHistoryQuery()
const { data: invoices, isLoading: invoicesLoading } = useInvoicesQuery()
const { data: current } = useCurrentSubscriptionQuery()
const { data: liveUsage, isLoading: liveUsageLoading } = useUsageQuery()

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

const points = computed(() => timeseries.value?.points ?? [])
const chartPeriods = computed(() => points.value.map((point) => formatDateTime(point.date, 'D MMM')))
const chartSeries = computed(() => [
  {
    key: 'traffic',
    label: t('labels.usage.history.traffic'),
    color: 'var(--series-1)',
    values: points.value.map((point) => point.value),
  },
])
</script>

<template>
  <div class="content-grid gap-y-4 pb-8">
    <ContentHeader :header="$t('labels.usage.history.title')">
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

    <LiveUsageCard
      :usage="liveUsage"
      :is-loading="liveUsageLoading"
    />

    <Panel
      v-if="periodsLoading || selectedPeriod"
      :title="$t('labels.usage.history.traffic')"
      :meta="selectedPeriod?.plan_name"
    >
      <Skeleton
        v-if="periodsLoading || timeseriesLoading"
        class="h-[268px] w-full"
      />
      <TrendChart
        v-else-if="points.length"
        :periods="chartPeriods"
        :series="chartSeries"
        :format="formatFileSize"
        :height="208"
      />
      <p
        v-else
        class="flex h-[268px] items-center justify-center text-muted"
      >
        {{ $t('labels.usage.history.noTrend') }}
      </p>
    </Panel>

    <Panel
      :title="$t('labels.usage.history.periods')"
      flush
    >
      <PeriodHistoryTable
        :periods="periods ?? []"
        :selected-id="selectedPeriodId"
        :loading="periodsLoading"
        @select="selectedPeriodId = $event"
      />
    </Panel>

    <Panel
      :title="$t('labels.invoices.title')"
      flush
    >
      <InvoicesTable
        :invoices="invoices ?? []"
        :loading="invoicesLoading"
      />
    </Panel>
  </div>
</template>
