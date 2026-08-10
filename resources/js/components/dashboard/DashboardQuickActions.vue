<template>
  <DashboardPanel title="Actions rapides">
    <div class="dashboard-quick-actions">
      <Link
        v-for="action in visibleActions"
        :key="action.key"
        :href="action.href"
        class="dashboard-quick-actions__btn"
      >
        <i :class="['bi', action.icon]"></i>
        <span>{{ action.label }}</span>
      </Link>
    </div>
  </DashboardPanel>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { usePermissions } from '@/composables/usePermissions'
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue'

const { canCreate } = usePermissions()

const actions = [
  { key: 'sale', label: 'Nouvelle vente', icon: 'bi-cart-plus', href: route('sales.create'), resource: 'sales' },
  { key: 'product', label: 'Nouveau produit', icon: 'bi-box-seam', href: route('products.create'), resource: 'products' },
  { key: 'customer', label: 'Nouveau client', icon: 'bi-person-plus', href: route('customers.create'), resource: 'customers' },
  { key: 'expense', label: 'Nouvelle dépense', icon: 'bi-receipt', href: route('expenses.create'), resource: 'expenses' },
  { key: 'quote', label: 'Nouveau devis', icon: 'bi-file-earmark-text', href: route('quotes.create'), resource: 'quotes' },
]

const visibleActions = computed(() =>
  actions.filter((action) => canCreate(action.resource)),
)
</script>

<style scoped>
.dashboard-quick-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.dashboard-quick-actions__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 2.85rem;
  padding: 0.75rem 1rem;
  border-radius: 0.85rem;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #0f172a;
  text-decoration: none;
  font-weight: 600;
  transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
}

.dashboard-quick-actions__btn:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
  transform: translateY(-1px);
}

@media (min-width: 768px) {
  .dashboard-quick-actions {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (min-width: 1200px) {
  .dashboard-quick-actions {
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }
}

:global(.dark) .dashboard-quick-actions__btn {
  background: #0f172a;
  border-color: #334155;
  color: #f8fafc;
}
</style>
