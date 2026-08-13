<template>
  <DashboardPanel title="Alertes stock">
    <template #actions>
      <Link :href="route('products.index', { stock_status: 'low' })" class="btn btn-sm btn-outline-secondary">
        Voir tout
      </Link>
    </template>

    <div v-if="alerts.length === 0" class="dashboard-empty">
      <i class="bi bi-shield-check"></i>
      <p>Aucune alerte de stock.</p>
    </div>

    <ul v-else class="dashboard-alerts">
      <li v-for="alert in alerts" :key="`${alert.type}-${alert.id}`" class="dashboard-alerts__item">
        <Link :href="route('products.show', { id: alert.id })" class="dashboard-alerts__link">
          <div class="dashboard-alerts__thumb">
            <img v-if="alert.image_url" :src="alert.image_url" :alt="alert.name" />
            <i v-else class="bi bi-box-seam"></i>
          </div>
          <div class="dashboard-alerts__content">
            <span class="dashboard-alerts__name">{{ alert.name }}</span>
            <span class="dashboard-alerts__message">{{ alert.message }}</span>
          </div>
          <span class="dashboard-alerts__badge" :class="`dashboard-alerts__badge--${alert.severity}`">
            {{ severityLabel(alert.severity) }}
          </span>
        </Link>
      </li>
    </ul>
  </DashboardPanel>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue'
import type { DashboardStockAlert } from '@/types/dashboard'

const props = defineProps<{
  lowStock: DashboardStockAlert[]
  expiring: DashboardStockAlert[]
}>()

const alerts = computed(() => [...props.lowStock, ...props.expiring].slice(0, 6))

function severityLabel(severity: string): string {
  if (severity === 'critical') return 'Critique'
  if (severity === 'warning') return 'Attention'
  return 'Info'
}
</script>
