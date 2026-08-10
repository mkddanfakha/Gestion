<template>
  <AppLayout>
    <div class="dashboard-page">
      <DashboardHeader
        :user-name="userName"
        :period="filters.period"
        :period-label="filters.label"
        :refreshed-at="refreshed_at"
        :loading="loading"
        @refresh="refreshDashboard"
        @update:period="changePeriod"
      />

      <div class="dashboard-kpi-grid">
        <DashboardKpiCard
          v-for="kpi in kpis"
          :key="kpi.key"
          :kpi="kpi"
          :period-label="filters.label"
        />
      </div>

      <div class="dashboard-grid dashboard-grid--main">
        <DashboardPanel class="dashboard-grid__span-2" title="Évolution des ventes">
          <div v-if="sales_chart.length === 0" class="dashboard-empty">
            <i class="bi bi-graph-up"></i>
            <p>Aucune vente sur cette période.</p>
          </div>
          <DashboardSalesChart v-else :data="sales_chart" />
        </DashboardPanel>
      </div>

      <div class="dashboard-grid dashboard-grid--split">
        <DashboardPanel title="Modes de paiement">
          <div v-if="payment_methods.length === 0" class="dashboard-empty">
            <i class="bi bi-pie-chart"></i>
            <p>Aucun paiement enregistré sur cette période.</p>
          </div>
          <DashboardPaymentChart v-else :data="payment_methods" />
        </DashboardPanel>

        <DashboardTopProducts :products="top_products" :can-view-products="canViewProducts" />
      </div>

      <div class="dashboard-grid dashboard-grid--split">
        <DashboardStockAlerts
          :low-stock="stock_alerts.low_stock"
          :expiring="stock_alerts.expiring"
        />
        <DashboardInvoiceAlerts
          :due-today="invoice_alerts.due_today"
          :overdue="invoice_alerts.overdue"
        />
      </div>

      <div class="dashboard-grid dashboard-grid--split">
        <DashboardRecentActivity :activities="recent_activity" />

        <DashboardRecentList
          v-if="canViewSales"
          title="Ventes récentes"
          :items="recentSalesItems"
          empty-text="Aucune vente récente."
          empty-icon="bi-cart-x"
          amount-class="text-success"
        />
      </div>

      <div v-if="canViewFinancials" class="dashboard-grid dashboard-grid--split">
        <DashboardRecentList
          title="Dépenses récentes"
          :items="recentExpensesItems"
          empty-text="Aucune dépense récente."
          empty-icon="bi-receipt"
          amount-class="text-danger"
        />
      </div>

      <DashboardQuickActions v-if="hasQuickActions" />
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import DashboardHeader from '@/components/dashboard/DashboardHeader.vue'
import DashboardKpiCard from '@/components/dashboard/DashboardKpiCard.vue'
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue'
import DashboardSalesChart from '@/components/dashboard/DashboardSalesChart.vue'
import DashboardPaymentChart from '@/components/dashboard/DashboardPaymentChart.vue'
import DashboardTopProducts from '@/components/dashboard/DashboardTopProducts.vue'
import DashboardStockAlerts from '@/components/dashboard/DashboardStockAlerts.vue'
import DashboardInvoiceAlerts from '@/components/dashboard/DashboardInvoiceAlerts.vue'
import DashboardRecentActivity from '@/components/dashboard/DashboardRecentActivity.vue'
import DashboardRecentList from '@/components/dashboard/DashboardRecentList.vue'
import DashboardQuickActions from '@/components/dashboard/DashboardQuickActions.vue'
import { usePermissions } from '@/composables/usePermissions'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { route } from '@/lib/routes'
import type { DashboardPageProps } from '@/types/dashboard'
import { formatDashboardCurrency, getPaymentMethodLabel } from '@/utils/dashboardFormatters'

const props = defineProps<DashboardPageProps>()

const page = usePage()
const { canCreate, canView } = usePermissions()
const { success, error } = useSweetAlert()

const userName = computed(() => page.props.auth?.user?.name ?? 'Utilisateur')
const loading = computed(() => page.props.processing === true)

const canViewSales = computed(() => canView('sales'))
const canViewProducts = computed(() => canView('products'))
const canViewFinancials = computed(() => props.can_view_financials)

const hasQuickActions = computed(() =>
  ['sales', 'products', 'customers', 'expenses', 'quotes'].some((resource) => canCreate(resource)),
)

const recentSalesItems = computed(() =>
  props.recent_sales.map((sale) => ({
    id: sale.id,
    title: sale.sale_number,
    meta: `${sale.customer ?? 'Client anonyme'} · ${getPaymentMethodLabel(sale.payment_method)}`,
    amount: formatDashboardCurrency(sale.total_amount),
    href: route('sales.show', { id: sale.id }),
  })),
)

const recentExpensesItems = computed(() =>
  props.recent_expenses.map((expense) => ({
    id: expense.id,
    title: expense.title,
    meta: `${expense.category_label} · ${expense.created_at}`,
    amount: formatDashboardCurrency(expense.amount),
    href: route('expenses.show', { id: expense.id }),
  })),
)

function changePeriod(period: string) {
  router.get(
    route('dashboard'),
    { period },
    { preserveScroll: true, preserveState: false, only: [] },
  )
}

function refreshDashboard() {
  router.get(
    route('dashboard'),
    { period: props.filters.period },
    { preserveScroll: true, preserveState: false, only: [] },
  )
}

watch(
  () => page.props.flash,
  (flash) => {
    if (flash?.success) success(flash.success)
    if (flash?.error) error(flash.error)
  },
  { immediate: true, deep: true },
)
</script>

<style scoped>
.dashboard-page {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.dashboard-kpi-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
}

.dashboard-grid {
  display: grid;
  gap: 1rem;
}

.dashboard-grid--main {
  grid-template-columns: 1fr;
}

.dashboard-grid--split {
  grid-template-columns: 1fr;
}

.dashboard-grid__span-2 {
  grid-column: 1 / -1;
}

.dashboard-empty {
  text-align: center;
  color: #64748b;
  padding: 2.5rem 1rem;
}

.dashboard-empty i {
  font-size: 2rem;
  display: block;
  margin-bottom: 0.5rem;
}

@media (min-width: 768px) {
  .dashboard-kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
  }

  .dashboard-grid--split {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 1200px) {
  .dashboard-kpi-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>
