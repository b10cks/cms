<script setup lang="ts">
import {
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  type ChartOptions,
  Legend,
  LinearScale,
  Title,
  Tooltip,
} from 'chart.js'
import { Bar } from 'vue-chartjs'

import { Card, CardContent, CardHeader, CardTitle } from '~/components/ui/card'
import { installChart } from '~/plugins/chart'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)
installChart()

interface Dataset {
  label: string
  data: number[]
  color: string
}

interface Props {
  title: string
  labels: string[]
  datasets: Dataset[]
  height?: number
  stacked?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  height: 300,
  stacked: false,
})

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.datasets.map((dataset) => ({
    label: dataset.label,
    data: dataset.data,
    backgroundColor: dataset.color,
    borderColor: dataset.color.replace('0.8', '1'),
    borderWidth: 1,
    borderRadius: 4,
  })),
}))

const chartOptions: ChartOptions<'bar'> = {
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
        boxWidth: 8,
        boxHeight: 8,
        padding: 15,
        font: {
          size: 12,
        },
        usePointStyle: true,
        pointStyle: 'rect',
      },
    },
    tooltip: {
      backgroundColor: 'rgba(0, 0, 0, 0.8)',
      multiKeyBackground: 'rgba(0, 0, 0, 0.8)',
      boxWidth: 12,
      boxHeight: 12,
      cornerRadius: 4,
      boxPadding: 8,
      padding: 12,
      titleFont: {
        size: 14,
      },
      bodyFont: {
        size: 13,
      },
    },
  },
  scales: {
    x: {
      stacked: props.stacked,
      grid: {
        display: false,
      },
      ticks: {
        maxTicksLimit: 10,
        font: {
          size: 11,
        },
      },
    },
    y: {
      stacked: props.stacked,
      beginAtZero: true,
      grid: {
        color: 'rgba(0, 0, 0, 0.05)',
      },
      ticks: {
        font: {
          size: 11,
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
      <Bar
        :data="chartData"
        :options="chartOptions"
      />
    </CardContent>
  </Card>
</template>
