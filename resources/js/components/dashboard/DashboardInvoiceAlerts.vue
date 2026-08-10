<template>
  <DashboardPanel title="Échéances factures">
    <template #actions>
      <Link :href="route('sales.index')" class="btn btn-sm btn-outline-secondary">Voir les ventes</Link>
    </template>

    <div v-if="items.length === 0" class="dashboard-empty">
      <i class="bi bi-calendar-check"></i>
      <p>Aucune échéance urgente.</p>
    </div>

    <ul v-else class="dashboard-invoices">
      <li v-for="item in items" :key="item.id" class="dashboard-invoices__item">
        <Link :href="route('sales.show', { id: item.id })" class="dashboard-invoices__link">
          <div>
            <span class="dashboard-invoices__title">{{ item.sale_number }}</span>
            <span class="dashboard-invoices__meta">{{ item.customer }}</span>
          </div>
          <div class="dashboard-invoices__side">
            <span class="dashboard-invoices__amount">{{ formatDashboardCurrency(item.remaining_amount) }}</span>
            <span class="dashboard-invoices__badge" :class="item.days_overdue > 0 ? 'dashboard-invoices__badge--overdue' : 'dashboard-invoices__badge--today'">
              {{ item.days_overdue > 0 ? `${item.days_overdue} j de retard` : "Aujourd'hui" }}
            </span>
          </div>
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
import type { DashboardInvoiceAlert } from '@/types/dashboard'
import { formatDashboardCurrency } from '@/utils/dashboardFormatters'

const props = defineProps<{
  dueToday: DashboardInvoiceAlert[]
  overdue: DashboardInvoiceAlert[]
}>()

const items = computed(() => [...props.overdue, ...props.dueToday].slice(0, 6))
</script>

<style scoped>
.dashboard-invoices {
  list-style: none;
  margin: 0;
  padding: 0;
}

.dashboard-invoices__item + .dashboard-invoices__item {
  margin-top: 0.65rem;
}

.dashboard-invoices__link {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem;
  border-radius: 0.85rem;
  text-decoration: none;
  color: inherit;
}

.dashboard-invoices__link:hover {
  background: #f8fafc;
}

.dashboard-invoices__title {
  display: block;
  font-weight: 600;
}

.dashboard-invoices__meta {
  display: block;
  font-size: 0.82rem;
  color: #64748b;
}

.dashboard-invoices__side {
  text-align: right;
}

.dashboard-invoices__amount {
  display: block;
  font-weight: 700;
  color: #d97706;
}

.dashboard-invoices__badge {
  display: inline-block;
  margin-top: 0.25rem;
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
}

.dashboard-invoices__badge--overdue { background: #fef2f2; color: #dc2626; }
.dashboard-invoices__badge--today { background: #eff6ff; color: #2563eb; }

.dashboard-empty {
  text-align: center;
  color: #64748b;
  padding: 2rem 1rem;
}
</style>
