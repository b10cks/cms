<script setup lang="ts">
import { ArcElement, Chart as ChartJS, Legend, Tooltip, type ChartOptions } from 'chart.js'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import { Pie } from 'vue-chartjs'
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'

ChartJS.register(ArcElement, Tooltip, Legend)

interface PieDataPoint {
  name: string
  count: number
  icon?: string
  color?: string | null
}

interface Props {
  title: string | CleanTranslation
  data: PieDataPoint[]
  height?: number
}

const props = withDefaults(defineProps<Props>(), {
  height: 300,
})

const chartData = computed(() => ({
  labels: props.data.map((item) => item.name),
  datasets: [
    {
      data: props.data.map((item) => item.count),
      backgroundColor: props.data.map(
        (item, i) =>
          item.color ??
          [
            'rgba(251, 146, 60, 0.8)',
            'rgba(99, 102, 241, 0.8)',
            'rgba(236, 72, 153, 0.8)',
            'rgba(34, 197, 94, 0.8)',
            'rgba(139, 92, 246, 0.8)',
            'rgba(59, 130, 246, 0.8)',
            'rgba(168, 85, 247, 0.8)',
            'rgba(244, 63, 94, 0.8)',
          ][i % 8]
      ),
      borderWidth: 0,
    },
  ],
}))

const chartOptions: ChartOptions<'pie'> = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
      labels: {
        padding: 24,
        color: '#6D7292',
        font: {
          family: '"Inter", ui-sans-serif',
          size: 14,
        },
      },
    },
    tooltip: {
      titleFont: {
        family: '"Inter", ui-sans-serif',
        size: 14,
        weight: 'bold',
      },
      bodyFont: {
        family: '"Inter", ui-sans-serif',
        size: 14,
      },
      callbacks: {
        label: (context) => {
          const value = context.parsed || 0
          const total = context.dataset.data.reduce(
            (acc: number, curr) => acc + (curr as number),
            0
          )
          const percentage = ((value / total) * 100).toFixed(1)
          return `${context.label || ''}: ${value} (${percentage}%)`
        },
      },
    },
  },
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ title }}</CardTitle>
    </CardHeader>
    <CardContent :style="{ height: `${height}px` }">
      <Pie
        :data="chartData"
        :options="chartOptions"
      />
    </CardContent>
  </Card>
</template>
