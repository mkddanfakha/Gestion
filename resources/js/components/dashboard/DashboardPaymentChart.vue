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

function isDarkMode(): boolean {
  return document.documentElement.classList.contains('dark')
}

const palette = ['#059669', '#2563eb', '#d97706', '#7c3aed', '#0891b2', '#64748b']

function formatSegmentPercentage(value: number): string {
  const rounded = Math.round(value)
  return `${Number.isInteger(value) || value === rounded ? rounded : value.toFixed(1)} %`
}

const percentageLabelsPlugin = {
  id: 'paymentPercentageLabels',
  afterDraw(chartInstance: Chart) {
    const meta = chartInstance.getDatasetMeta(0)
    if (!meta?.data?.length) return

    const { ctx } = chartInstance
    ctx.save()
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.font = 'bold 13px system-ui, -apple-system, sans-serif'

    meta.data.forEach((arc, index) => {
      const item = props.data[index]
      if (!item || item.percentage <= 0) return

      const element = arc as unknown as {
        x: number
        y: number
        innerRadius: number
        outerRadius: number
        startAngle: number
        endAngle: number
      }

      const angle = (element.startAngle + element.endAngle) / 2
      const radius = (element.innerRadius + element.outerRadius) / 2
      const x = element.x + Math.cos(angle) * radius
      const y = element.y + Math.sin(angle) * radius
      const label = formatSegmentPercentage(item.percentage)

      ctx.fillStyle = 'rgba(255, 255, 255, 0.95)'
      ctx.shadowColor = 'rgba(15, 23, 42, 0.35)'
      ctx.shadowBlur = 4
      ctx.fillText(label, x, y)
    })

    ctx.restore()
  },
}

function buildChart() {
  if (!canvasRef.value) return
  chart?.destroy()

  const dark = isDarkMode()
  const legendColor = dark ? '#cbd5e1' : '#64748b'

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
          labels: { boxWidth: 12, padding: 14, color: legendColor },
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
    plugins: [percentageLabelsPlugin],
  })
}

onMounted(() => {
  buildChart()
  window.addEventListener('mkd-theme-changed', buildChart)
})
watch(() => props.data, buildChart, { deep: true })
onUnmounted(() => {
  window.removeEventListener('mkd-theme-changed', buildChart)
  chart?.destroy()
})
</script>

<style scoped>
.dashboard-chart {
  position: relative;
  width: 100%;
}
</style>
