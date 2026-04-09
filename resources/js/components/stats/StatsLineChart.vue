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

interface LineDataPoint {
  name: string
  value: number
}

interface Props {
  title: string
  data: LineDataPoint[]
  height?: number
  yAxisLabel?: string
  color?: string
  fill?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  height: 300,
  color: 'rgba(99, 102, 241, 1)',
  fill: true,
})

const chartData = computed(() => ({
  labels: props.data.map((item) => item.name),
  datasets: [
    {
      label: props.yAxisLabel || props.title,
      data: props.data.map((item) => item.value),
      borderColor: props.color,
      backgroundColor: props.fill ? props.color.replace('1)', '0.1)') : 'transparent',
      fill: props.fill,
      tension: 0.4,
      pointRadius: 2,
      pointHoverRadius: 5,
    },
  ],
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
      display: false,
    },
    tooltip: {
      backgroundColor: 'oklch(20.63% 0.008 277.83',
      multiKeyBackground: 'oklch(20.63% 0.008 277.83',
      titleColor: 'oklch(82.89% 0.012 279)',
      bodyColor: 'oklch(55.99% 0.04 277.73)',
      boxWidth: 12,
      boxHeight: 12,
      boxPadding: 4,
      padding: 12,
      titleFont: {
        family: '"Inter", ui-sans-serif',
        size: 14,
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
        maxTicksLimit: 5,
        color: '#6D7292',
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
