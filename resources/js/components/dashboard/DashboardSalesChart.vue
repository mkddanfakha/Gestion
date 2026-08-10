<template>
  <div class="dashboard-chart" :style="{ height: `${height}px` }">
    <canvas ref="canvasRef" aria-label="Graphique des ventes" role="img"></canvas>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Filler,
  Tooltip,
  Legend,
} from 'chart.js'
import type { DashboardChartPoint } from '@/types/dashboard'
import { formatDashboardCurrency } from '@/utils/dashboardFormatters'

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip, Legend)

const props = withDefaults(defineProps<{
  data: DashboardChartPoint[]
  height?: number
}>(), {
  height: 320,
})

const canvasRef = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

function isDarkMode(): boolean {
  return document.documentElement.classList.contains('dark')
}

function buildChart() {
  if (!canvasRef.value) return
  chart?.destroy()

  const dark = isDarkMode()
  const gridColor = dark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(148, 163, 184, 0.25)'
  const textColor = dark ? '#cbd5e1' : '#64748b'

  chart = new Chart(canvasRef.value, {
    type: 'line',
    data: {
      labels: props.data.map((point) => point.label),
      datasets: [
        {
          label: "Chiffre d'affaires",
          data: props.data.map((point) => point.total),
          borderColor: '#059669',
          backgroundColor: dark ? 'rgba(5, 150, 105, 0.15)' : 'rgba(5, 150, 105, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: props.data.length > 20 ? 0 : 3,
          pointHoverRadius: 5,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (context) => `${formatDashboardCurrency(Number(context.parsed.y))}`,
          },
        },
      },
      scales: {
        x: {
          ticks: { color: textColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
          grid: { color: gridColor },
        },
        y: {
          beginAtZero: true,
          ticks: {
            color: textColor,
            callback: (value) => formatDashboardCurrency(Number(value)),
          },
          grid: { color: gridColor },
        },
      },
    },
  })
}

onMounted(buildChart)
watch(() => props.data, buildChart, { deep: true })
onUnmounted(() => chart?.destroy())
</script>

<style scoped>
.dashboard-chart {
  position: relative;
  width: 100%;
}
</style>
