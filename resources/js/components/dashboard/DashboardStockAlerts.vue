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

<style scoped>
.dashboard-alerts {
  list-style: none;
  margin: 0;
  padding: 0;
}

.dashboard-alerts__item + .dashboard-alerts__item {
  margin-top: 0.65rem;
}

.dashboard-alerts__link {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.75rem;
  border-radius: 0.85rem;
  text-decoration: none;
  color: inherit;
}

.dashboard-alerts__link:hover {
  background: #f8fafc;
}

.dashboard-alerts__thumb {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.65rem;
  background: #f1f5f9;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.dashboard-alerts__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.dashboard-alerts__name {
  display: block;
  font-weight: 600;
}

.dashboard-alerts__message {
  display: block;
  font-size: 0.82rem;
  color: #64748b;
}

.dashboard-alerts__badge {
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.25rem 0.55rem;
  border-radius: 999px;
  white-space: nowrap;
}

.dashboard-alerts__badge--critical { background: #fef2f2; color: #dc2626; }
.dashboard-alerts__badge--warning { background: #fffbeb; color: #d97706; }
.dashboard-alerts__badge--info { background: #eff6ff; color: #2563eb; }

.dashboard-empty {
  text-align: center;
  color: #64748b;
  padding: 2rem 1rem;
}
</style>
