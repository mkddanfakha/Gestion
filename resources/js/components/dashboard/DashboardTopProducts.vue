<template>
  <DashboardPanel title="Produits les plus vendus">
    <template #actions>
      <Link v-if="canViewProducts" :href="route('products.index')" class="btn btn-sm btn-outline-secondary">
        Voir tous
      </Link>
    </template>

    <div v-if="products.length === 0" class="dashboard-empty">
      <i class="bi bi-bar-chart"></i>
      <p>Aucune vente sur cette période.</p>
    </div>

    <ul v-else class="dashboard-top-products">
      <li v-for="(product, index) in products" :key="product.id" class="dashboard-top-products__item">
        <Link :href="route('products.show', { id: product.id })" class="dashboard-top-products__link">
          <span class="dashboard-top-products__rank">{{ index + 1 }}</span>
          <div class="dashboard-top-products__thumb">
            <img v-if="product.image_url" :src="product.image_url" :alt="product.name" />
            <i v-else class="bi bi-box-seam"></i>
          </div>
          <div class="dashboard-top-products__content">
            <span class="dashboard-top-products__name">{{ product.name }}</span>
            <span class="dashboard-top-products__meta">{{ product.total_quantity }} unités · {{ product.sales_count }} lignes</span>
          </div>
          <span class="dashboard-top-products__amount">{{ formatDashboardCurrency(product.price) }}</span>
        </Link>
      </li>
    </ul>
  </DashboardPanel>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue'
import type { DashboardTopProduct } from '@/types/dashboard'
import { formatDashboardCurrency } from '@/utils/dashboardFormatters'

defineProps<{
  products: DashboardTopProduct[]
  canViewProducts?: boolean
}>()
</script>

<style scoped>
.dashboard-top-products {
  list-style: none;
  margin: 0;
  padding: 0;
}

.dashboard-top-products__item + .dashboard-top-products__item {
  margin-top: 0.65rem;
}

.dashboard-top-products__link {
  display: grid;
  grid-template-columns: auto auto 1fr auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.75rem;
  border-radius: 0.85rem;
  text-decoration: none;
  color: inherit;
  transition: background-color 0.15s ease;
}

.dashboard-top-products__link:hover {
  background: #f8fafc;
}

.dashboard-top-products__rank {
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 999px;
  background: #ecfdf5;
  color: #059669;
  font-weight: 700;
  font-size: 0.82rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.dashboard-top-products__thumb {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.65rem;
  background: #f1f5f9;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.dashboard-top-products__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.dashboard-top-products__name {
  display: block;
  font-weight: 600;
  color: #0f172a;
}

.dashboard-top-products__meta {
  display: block;
  font-size: 0.82rem;
  color: #64748b;
}

.dashboard-top-products__amount {
  font-size: 0.88rem;
  font-weight: 600;
  color: #059669;
  white-space: nowrap;
}

.dashboard-empty {
  text-align: center;
  color: #64748b;
  padding: 2rem 1rem;
}

.dashboard-empty i {
  font-size: 2rem;
  display: block;
  margin-bottom: 0.5rem;
}

:global(.dark) .dashboard-top-products__link:hover { background: #1e293b; }
:global(.dark) .dashboard-top-products__name { color: #f8fafc; }
</style>
