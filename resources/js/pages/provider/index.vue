<script setup lang="ts">
import StatsCard from '~/components/stats/StatsCard.vue'
import { SelectField } from '~/components/ui/form'

const { formatNumber } = useFormat()
const { t } = useI18n()
const { useProviderStatsQuery } = useProvider()

useSeoMeta({
  title: t('labels.provider.dashboard.title'),
})

const dateRange = ref('thisMonth')

const dateRangeValues = computed(() => {
  const now = new Date()
  const endDate = new Date()
  let startDate: Date

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
    case 'thisYear':
      startDate = new Date(now.getFullYear(), 0, 1)
      break
    case 'thisMonth':
    default:
      startDate = new Date(now.getFullYear(), now.getMonth(), 1)
      break
  }

  return {
    start_date: startDate.toISOString(),
    end_date: endDate.toISOString(),
  }
})

const { data: stats, isLoading, error } = useProviderStatsQuery(dateRangeValues)

const cards = computed(() => {
  if (!stats.value) {
    return []
  }

  return [
    {
      key: 'teams',
      title: t('labels.provider.dashboard.cards.teams'),
      icon: 'lucide:users-round',
      total: stats.value.summary.teams.total,
      created: stats.value.summary.teams.created_in_period,
    },
    {
      key: 'spaces',
      title: t('labels.provider.dashboard.cards.spaces'),
      icon: 'lucide:boxes',
      total: stats.value.summary.spaces.total,
      created: stats.value.summary.spaces.created_in_period,
    },
    {
      key: 'users',
      title: t('labels.provider.dashboard.cards.users'),
      icon: 'lucide:user-round',
      total: stats.value.summary.users.total,
      created: stats.value.summary.users.created_in_period,
    },
  ]
})
</script>

<template>
  <div class="content-grid mx-auto w-full py-8 bg-b">
    <div class="space-y-6">
      <div>
        <h1 class="text-2xl font-bold text-primary">
          {{ t('labels.provider.dashboard.title') }}
        </h1>
        <p class="text-muted">
          {{ t('labels.provider.dashboard.description') }}
        </p>
      </div>

      <div class="flex items-center justify-between">
        <div class="ml-auto flex space-x-4">
          <SelectField
            name="providerDateRange"
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
        </div>
      </div>

      <div
        v-if="isLoading"
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
        <p class="text-destructive">{{ error.message }}</p>
      </div>

      <div
        v-else-if="!stats"
        class="p-8 text-center"
      >
        <p class="text-muted">{{ t('dashboard.no_data') }}</p>
      </div>

      <template v-else>
        <h2 class="text-lg font-semibold text-primary">
          {{ t('labels.provider.dashboard.sections.summary') }}
        </h2>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
          <StatsCard
            v-for="card in cards"
            :key="card.key"
            :title="card.title"
            :icon="card.icon"
          >
            <div class="text-3xl font-bold text-primary">
              {{ formatNumber(card.total) }}
            </div>
            <p class="text-sm text-muted">
              {{ t('labels.provider.dashboard.cards.total') }}
            </p>
            <p class="text-sm text-muted">
              {{
                t('labels.provider.dashboard.cards.createdInTimeframe', {
                  count: formatNumber(card.created),
                })
              }}
            </p>
          </StatsCard>
        </div>
      </template>
    </div>
  </div>
</template>
