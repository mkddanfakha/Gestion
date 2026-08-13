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
