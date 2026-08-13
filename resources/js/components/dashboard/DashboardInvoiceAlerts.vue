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
