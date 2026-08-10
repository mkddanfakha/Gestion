<template>
  <div class="dashboard-chart" :style="{ height: `${height}px` }">
    <canvas ref="canvasRef" aria-label="Répartition des modes de paiement" role="img"></canvas>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'
import type { DashboardPaymentMethod } from '@/types/dashboard'
import { formatDashboardCurrency } from '@/utils/dashboardFormatters'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

const props = withDefaults(defineProps<{
  data: DashboardPaymentMethod[]
  height?: number
}>(), {
  height: 280,
})

const canvasRef = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

const palette = ['#059669', '#2563eb', '#d97706', '#7c3aed', '#0891b2', '#64748b']

function buildChart() {
  if (!canvasRef.value) return
  chart?.destroy()

  chart = new Chart(canvasRef.value, {
    type: 'doughnut',
    data: {
      labels: props.data.map((item) => item.label),
      datasets: [
        {
          data: props.data.map((item) => item.amount),
          backgroundColor: props.data.map((_, index) => palette[index % palette.length]),
          borderWidth: 0,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { boxWidth: 12, padding: 14 },
        },
        tooltip: {
          callbacks: {
            label: (context) => {
              const item = props.data[context.dataIndex]
              return `${item.label} : ${formatDashboardCurrency(item.amount)} (${item.percentage} %)`
            },
          },
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
