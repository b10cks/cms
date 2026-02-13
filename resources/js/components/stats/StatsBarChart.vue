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

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

interface BarDataPoint {
  name: string
  value: number
}

interface Props {
  title: string
  data: BarDataPoint[]
  height?: number
  yAxisLabel?: string
  color?: string
}

const props = withDefaults(defineProps<Props>(), {
  height: 300,
  color: 'rgba(139, 92, 246, 0.8)',
})

const chartData = computed(() => ({
  labels: props.data.map((item) => item.name),
  datasets: [
    {
      label: props.yAxisLabel || props.title,
      data: props.data.map((item) => item.value),
      backgroundColor: props.color,
      borderColor: props.color.replace('0.8', '1'),
      borderWidth: 1,
      borderRadius: 4,
    },
  ],
}))

const chartOptions: ChartOptions<'bar'> = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      backgroundColor: 'rgba(0, 0, 0, 0.8)',
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
