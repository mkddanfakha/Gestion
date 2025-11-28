<template>
  <div class="chart-container">
    <canvas ref="chartCanvas" width="800" height="400"></canvas>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue'

interface MonthlyData {
  month: string
  total: number
}

interface Props {
  data: MonthlyData[]
}

const props = defineProps<Props>()
const chartCanvas = ref<HTMLCanvasElement | null>(null)
let chartInstance: any = null

const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatMonth = (monthString: string): string => {
  const [year, month] = monthString.split('-')
  const date = new Date(parseInt(year), parseInt(month) - 1)
  return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' })
}

const loadChartJS = (): Promise<any> => {
  return new Promise((resolve, reject) => {
    if ((window as any).Chart) {
      resolve((window as any).Chart)
      return
    }

    const script = document.createElement('script')
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js'
    script.onload = () => {
      resolve((window as any).Chart)
    }
    script.onerror = () => {
      reject(new Error('Failed to load Chart.js'))
    }
    document.head.appendChild(script)
  })
}

const createChart = async () => {
  if (!chartCanvas.value) return

  try {
    const Chart = await loadChartJS()

    // Détruire le graphique existant s'il existe
    if (chartInstance) {
      chartInstance.destroy()
    }

    // Préparer les données
    const labels = props.data.map(item => formatMonth(item.month))
    const values = props.data.map(item => item.total)

    // Configuration du graphique
    const config = {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Chiffre d\'affaires',
            data: values,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#0d6efd',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 6,
            pointHoverRadius: 8,
            pointHoverBackgroundColor: '#0d6efd',
            pointHoverBorderColor: '#ffffff',
            pointHoverBorderWidth: 3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true,
            position: 'top',
            labels: {
              usePointStyle: true,
              padding: 20,
              font: {
                size: 14,
                weight: '500'
              }
            }
          },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            titleColor: '#ffffff',
            bodyColor: '#ffffff',
            borderColor: '#0d6efd',
            borderWidth: 1,
            cornerRadius: 8,
            displayColors: true,
            callbacks: {
              label: function(context: any) {
                return `Chiffre d'affaires: ${formatCurrency(context.parsed.y)}`
              }
            }
          }
        },
        scales: {
          x: {
            display: true,
            title: {
              display: true,
              text: 'Mois',
              font: {
                size: 14,
                weight: '600'
              }
            },
            grid: {
              display: true,
              color: 'rgba(0, 0, 0, 0.1)'
            },
            ticks: {
              font: {
                size: 12
              }
            }
          },
          y: {
            display: true,
            title: {
              display: true,
              text: 'Montant (Fcfa)',
              font: {
                size: 14,
                weight: '600'
              }
            },
            grid: {
              display: true,
              color: 'rgba(0, 0, 0, 0.1)'
            },
            ticks: {
              font: {
                size: 12
              },
              callback: function(value: any) {
                return formatCurrency(value)
              }
            },
            beginAtZero: true
          }
        },
        interaction: {
          intersect: false,
          mode: 'index'
        },
        elements: {
          point: {
            hoverBackgroundColor: '#0d6efd'
          }
        }
      }
    }

    chartInstance = new Chart(chartCanvas.value, config)
  } catch (error) {
    console.error('Erreur lors de la création du graphique:', error)
  }
}

// Créer le graphique au montage du composant
onMounted(async () => {
  await nextTick()
  createChart()
})

// Mettre à jour le graphique quand les données changent
watch(() => props.data, () => {
  createChart()
}, { deep: true })

// Nettoyer le graphique au démontage
onUnmounted(() => {
  if (chartInstance) {
    chartInstance.destroy()
  }
})
</script>

<style scoped>
.chart-container {
  position: relative;
  height: 400px;
  width: 100%;
}

canvas {
  max-height: 400px;
}
</style>
