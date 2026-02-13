<script setup lang="ts">
import {
  CategoryScale,
  Chart as ChartJS,
  type ChartOptions,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Title,
  Tooltip,
} from 'chart.js'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import { Line } from 'vue-chartjs'
import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

interface Dataset {
  label: string
  data: number[]
  color: string
}

interface Props {
  title: string | CleanTranslation
  labels: string[]
  datasets: Dataset[]
  height?: number
  fill?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  height: 300,
  fill: true,
})

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.datasets.map((dataset) => ({
    label: dataset.label,
    data: dataset.data,
    borderColor: dataset.color,
    backgroundColor: props.fill ? dataset.color.replace('0.8)', '0.1)') : 'transparent',
    fill: props.fill,
    tension: 0.4,
    pointRadius: 2,
    pointHoverRadius: 5,
  })),
}))

const chartOptions: ChartOptions<'line'> = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index',
    intersect: false,
  },
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
        usePointStyle: true,
        pointStyle: 'circle',
      },
    },
    tooltip: {
      backgroundColor: 'rgba(0, 0, 0, 0.8)',
      padding: 12,
      titleFont: {
        family: '"Inter", ui-sans-serif',
        size: 14,
        weight: 'bold',
      },
      bodyFont: {
        family: '"Inter", ui-sans-serif',
        size: 14,
      },
    },
  },
  scales: {
    x: {
      grid: {
        display: false,
      },
      ticks: {
        color: '#6D7292',
        maxTicksLimit: 5,
        font: {
          family: '"Inter", ui-sans-serif',
          size: 14,
        },
      },
    },
    y: {
      beginAtZero: true,
      grid: {
        color: 'rgba(0, 0, 0, 0.05)',
      },
      ticks: {
        color: '#6D7292',
        font: {
          family: '"Inter", ui-sans-serif',
          size: 14,
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
      <Line
        :data="chartData"
        :options="chartOptions"
      />
    </CardContent>
  </Card>
</template>
