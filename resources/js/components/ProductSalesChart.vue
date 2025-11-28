<template>
  <div class="chart-container">
    <canvas ref="chartCanvas" width="800" height="400"></canvas>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue'

interface ProductData {
  id: number
  name: string
  sales_count: number
  total_quantity: number
}

interface Props {
  data: ProductData[]
}

const props = defineProps<Props>()
const chartCanvas = ref<HTMLCanvasElement | null>(null)
let chartInstance: any = null

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

const truncateName = (name: string, maxLength: number = 20): string => {
  if (name.length <= maxLength) return name
  return name.substring(0, maxLength) + '...'
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
    const labels = props.data.map(item => truncateName(item.name))
    const salesCounts = props.data.map(item => item.sales_count)
    const quantities = props.data.map(item => item.total_quantity)

    // Configuration du graphique
    const config = {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Nombre de ventes',
            data: salesCounts,
            backgroundColor: 'rgba(13, 110, 253, 0.8)',
            borderColor: '#0d6efd',
            borderWidth: 2,
            yAxisID: 'y',
          },
          {
            label: 'Quantité totale vendue',
            data: quantities,
            backgroundColor: 'rgba(25, 135, 84, 0.8)',
            borderColor: '#198754',
            borderWidth: 2,
            yAxisID: 'y1',
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
                const datasetLabel = context.dataset.label || ''
                const value = context.parsed.y
                if (datasetLabel === 'Nombre de ventes') {
                  return `${datasetLabel}: ${value} vente(s)`
                } else {
                  return `${datasetLabel}: ${value} unité(s)`
                }
              }
            }
          }
        },
        scales: {
          x: {
            display: true,
            title: {
              display: true,
              text: 'Produits',
              font: {
                size: 14,
                weight: '600'
              }
            },
            grid: {
              display: false
            },
            ticks: {
              font: {
                size: 11
              },
              maxRotation: 45,
              minRotation: 45
            }
          },
          y: {
            type: 'linear',
            display: true,
            position: 'left',
            title: {
              display: true,
              text: 'Nombre de ventes',
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
              stepSize: 1,
              beginAtZero: true
            }
          },
          y1: {
            type: 'linear',
            display: true,
            position: 'right',
            title: {
              display: true,
              text: 'Quantité totale vendue',
              font: {
                size: 14,
                weight: '600'
              }
            },
            grid: {
              drawOnChartArea: false,
            },
            ticks: {
              font: {
                size: 12
              },
              beginAtZero: true
            }
          }
        },
        interaction: {
          intersect: false,
          mode: 'index'
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

