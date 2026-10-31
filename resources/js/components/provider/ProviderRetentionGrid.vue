<script setup lang="ts">
/**
 * Revenue retention by start month: one row per cohort, one cell per month since it started,
 * tinted with series 1 by how much of its first month's MRR the cohort still brings in.
 */
const props = defineProps<{
  cohorts: { label: string; values: number[] }[]
}>()

const { t } = useI18n()
const { formatNumber } = useFormat()

const columns = computed(() =>
  Array.from(
    { length: Math.max(1, ...props.cohorts.map((cohort) => cohort.values.length)) },
    (_, k) => k
  )
)

/** 100 % sits at a mid tint; growth deepens it, loss fades it. */
const tint = (value: number) =>
  `color-mix(in oklch, var(--series-1) ${Math.round(Math.min(70, Math.max(6, 30 + (value - 1) * 120)))}%, transparent)`
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-1 text-sm tabular-nums">
      <thead>
        <tr class="text-muted">
          <th class="w-12" />
          <th
            v-for="k in columns"
            :key="k"
            scope="col"
            class="font-normal"
          >
            {{ t('providerDashboard.retention.month', { n: k }) }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="cohort in cohorts"
          :key="cohort.label"
        >
          <th
            scope="row"
            class="pr-2 text-left font-normal whitespace-nowrap text-muted"
          >
            {{ cohort.label }}
          </th>
          <td
            v-for="k in columns"
            :key="k"
            class="h-8 min-w-12 rounded-md text-center text-primary"
            :style="
              cohort.values[k] === undefined ? undefined : { background: tint(cohort.values[k]) }
            "
          >
            {{ cohort.values[k] === undefined ? '' : `${formatNumber(cohort.values[k] * 100)}%` }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
