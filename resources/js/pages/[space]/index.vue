<script setup lang="ts">
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import SpaceDashboard from '~/components/SpaceDashboard.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { SelectField } from '~/components/ui/form'

const { useCurrentSpaceQuery } = useSpaces()
const { t } = useI18n()
const { data: space } = useCurrentSpaceQuery()
const { formatDateTime } = useFormat()

const period = ref('daily')
const dateRange = ref('thisMonth')

useSeoMeta({
  title: computed(() => t('dashboard.title')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid pb-6">
      <div v-if="space">
        <ContentHeader :header="space.name">
          <template #before-header>
            <NuxtImg
              v-if="space.icon"
              :src="space.icon"
              :alt="space.name"
              :width="80"
              :height="80"
              class="size-10 rounded-md object-cover"
            />
          </template>
          <div class="flex items-center gap-2">
            <span>
              {{ formatDateTime(space.updated_at ?? space.content_updated_at) }}
            </span>
            <SpaceBadge
              v-if="space.badge"
              :badge="space.badge"
              size="xs"
            />
          </div>
          <template #actions>
            <div class="flex items-center gap-4">
              <SelectField
                v-model="dateRange"
                name="dateRange"
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
                v-model="period"
                name="period"
                :placeholder="t('dashboard.filters.period')"
                :options="[
                  { value: 'daily', label: t('dashboard.filters.daily') },
                  { value: 'weekly', label: t('dashboard.filters.weekly') },
                  { value: 'monthly', label: t('dashboard.filters.monthly') },
                ]"
              />
            </div>
          </template>
        </ContentHeader>
        <SpaceDashboard
          class="mt-6"
          :space-id="space.id"
          :period="period"
          :date-range="dateRange"
        />
      </div>
    </div>
  </div>
</template>
