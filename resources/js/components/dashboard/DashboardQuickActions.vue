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
