<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Tableau de bord</h1>
        <p class="text-muted mb-0">Vue d'ensemble de votre gestion de stock et de vente</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('products.create')"
          class="btn btn-primary"
        >
          <i class="bi bi-plus-circle me-1"></i>
          Nouveau produit
        </Link>
        <Link
          :href="route('sales.create')"
          class="btn btn-success"
        >
          <i class="bi bi-cart-plus me-1"></i>
          Nouvelle vente
        </Link>
        <Link
          :href="route('expenses.create')"
          class="btn btn-warning"
        >
          <i class="bi bi-receipt me-1"></i>
          Nouvelle dépense
        </Link>
      </div>
    </div>

    <!-- Alerte pour les ventes avec date d'échéance aujourd'hui -->
    <div v-if="salesDueToday && salesDueToday.length > 0" class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
      <div class="d-flex align-items-start">
        <div class="me-3">
          <i class="bi bi-exclamation-triangle-fill fs-4"></i>
        </div>
        <div class="flex-grow-1">
          <h5 class="alert-heading mb-2">
            <i class="bi bi-calendar-event me-2"></i>
            Ventes avec échéance aujourd'hui
          </h5>
          <p class="mb-2">
            <strong>{{ salesDueToday.length }}</strong> vente(s) {{ salesDueToday.length > 1 ? 'ont' : 'a' }} une date d'échéance aujourd'hui et {{ salesDueToday.length > 1 ? 'ne sont' : 'n\'est' }} pas encore {{ salesDueToday.length > 1 ? 'payées' : 'payée' }}.
          </p>
          <ul class="mb-2">
            <li v-for="sale in salesDueToday.slice(0, 5)" :key="sale.id" class="small">
              <Link :href="route('sales.show', { id: sale.id })" class="text-decoration-none fw-medium">
                Vente {{ sale.sale_number }}
              </Link>
              - {{ sale.customer }} - 
              <span class="fw-bold">Reste à payer: {{ formatCurrency(sale.remaining_amount) }}</span>
            </li>
          </ul>
          <div class="d-flex gap-2">
            <Link :href="route('sales.index', { due_date: new Date().toISOString().split('T')[0] })" class="btn btn-sm btn-warning">
              Voir toutes les ventes avec échéance aujourd'hui
            </Link>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    </div>

    <!-- Alertes d'expiration -->
    <div v-if="props.expiringProducts && props.expiringProducts.length > 0" class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
      <div class="d-flex align-items-start">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div class="flex-grow-1">
          <strong>Alertes d'expiration :</strong> {{ props.expiringProductsTotal || props.expiringProducts.length }} produit(s) expiré(s) ou proche(s) de l'expiration.
          <ul class="mb-2 mt-2">
            <li v-for="product in props.expiringProducts.slice(0, 5)" :key="product.id" class="small d-flex align-items-center mb-2">
              <!-- Image du produit -->
              <div class="me-2 flex-shrink-0">
                <img
                  v-if="product.image_url"
                  :src="product.image_url"
                  :alt="product.name"
                  class="rounded"
                  style="width: 40px; height: 40px; object-fit: contain;"
                />
                <i v-else class="bi bi-box-seam text-muted" style="font-size: 40px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;"></i>
              </div>
              
              <div class="flex-grow-1">
                <Link :href="route('products.show', { id: product.id })" class="text-decoration-none fw-medium">
                  <strong>{{ product.name }}</strong>
                </Link>
                <span v-if="product.expiration_date">
                  - Expire le {{ formatDate(product.expiration_date) }}
                  <span v-if="product.days_until_expiration !== null">
                    <span v-if="product.days_until_expiration < 0" class="badge bg-danger ms-1">
                      Expiré depuis {{ Math.abs(product.days_until_expiration) }} jour(s)
                    </span>
                    <span v-else-if="product.days_until_expiration === 0" class="badge bg-danger ms-1">
                      Expire aujourd'hui
                    </span>
                    <span v-else class="badge bg-warning ms-1">
                      Expire dans {{ product.days_until_expiration }} jour(s)
                    </span>
                  </span>
                </span>
              </div>
            </li>
          </ul>
          <div class="d-flex gap-2">
            <Link :href="route('products.index', { expiration_alert: true })" class="btn btn-sm btn-danger">
              <i class="bi bi-box-arrow-right me-1"></i>
              Voir tous les produits expirés ou proches de l'expiration
            </Link>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    </div>

    <!-- Alertes de stock faible -->
    <div v-if="lowStockProducts.length > 0" class="alert alert-info alert-dismissible fade show mb-4" role="alert">
      <div class="d-flex align-items-start">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div class="flex-grow-1">
          <strong>Attention :</strong> {{ props.lowStockProductsTotal ?? stats.low_stock_products }} produit(s) en rupture de stock ou niveau faible.
          <ul class="mb-2 mt-2">
            <li v-for="product in lowStockProducts.slice(0, 3)" :key="product.id" class="small d-flex align-items-center mb-2">
              <!-- Image du produit -->
              <div class="me-2 flex-shrink-0">
                <img
                  v-if="product.image_url"
                  :src="product.image_url"
                  :alt="product.name"
                  class="rounded"
                  style="width: 40px; height: 40px; object-fit: contain;"
                />
                <i v-else class="bi bi-box-seam text-muted" style="font-size: 40px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;"></i>
              </div>
              
              <div class="flex-grow-1">
                <Link :href="route('products.show', { id: product.id })" class="text-decoration-none fw-medium">
                  <strong>{{ product.name }}</strong>
                </Link>
                <span v-if="product.category">
                  - {{ product.category.name }}
                </span>
                <span class="badge bg-info text-white ms-2">
                  Stock: {{ product.stock_quantity }} {{ product.unit }}
                  <span v-if="product.stock_quantity === 0" class="badge bg-danger ms-1">Rupture</span>
                </span>
              </div>
            </li>
          </ul>
          <div class="d-flex gap-2">
            <Link :href="route('products.index', { stock_status: 'low' })" class="btn btn-sm btn-info">
              <i class="bi bi-box-arrow-right me-1"></i>
              Voir tous les produits en rupture de stock ou niveau faible
            </Link>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    </div>

    <!-- Statistiques -->
    <div class="row g-4 mb-4">
      <div class="col-md-6 col-lg-3">
        <Link :href="route('products.index')" class="card h-100 text-decoration-none hover-card">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-primary bg-opacity-10 rounded me-3">
                <i class="bi bi-box text-primary fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Produits</p>
                <h4 class="mb-0 fw-bold">{{ stats.total_products }}</h4>
              </div>
            </div>
          </div>
        </Link>
      </div>

      <div class="col-md-6 col-lg-3">
        <Link :href="route('customers.index')" class="card h-100 text-decoration-none hover-card">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-success bg-opacity-10 rounded me-3">
                <i class="bi bi-people text-success fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Clients</p>
                <h4 class="mb-0 fw-bold">{{ stats.total_customers }}</h4>
              </div>
            </div>
          </div>
        </Link>
      </div>

      <div class="col-md-6 col-lg-3">
        <Link :href="route('sales.index')" class="card h-100 text-decoration-none hover-card">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-info bg-opacity-10 rounded me-3">
                <i class="bi bi-cart text-info fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Ventes</p>
                <h4 class="mb-0 fw-bold">{{ stats.total_sales }}</h4>
              </div>
            </div>
          </div>
        </Link>
      </div>

      <div class="col-md-6 col-lg-3">
        <Link :href="route('expenses.index')" class="card h-100 text-decoration-none hover-card">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-warning bg-opacity-10 rounded me-3">
                <i class="bi bi-receipt text-warning fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Dépenses</p>
                <h4 class="mb-0 fw-bold">{{ stats.total_expenses }}</h4>
              </div>
            </div>
          </div>
        </Link>
      </div>
    </div>

    <!-- Statistiques financières -->
    <div class="row g-4 mb-4">
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-success bg-opacity-10 rounded me-3">
                <i class="bi bi-currency-euro text-success fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Chiffre d'affaires</p>
                <h4 class="mb-0 fw-bold">{{ formatCurrency(stats.total_revenue) }}</h4>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-danger bg-opacity-10 rounded me-3">
                <i class="bi bi-receipt text-danger fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Total dépenses</p>
                <h4 class="mb-0 fw-bold">{{ formatCurrency(stats.total_expenses_amount) }}</h4>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="p-2 bg-primary bg-opacity-10 rounded me-3">
                <i class="bi bi-graph-up text-primary fs-4"></i>
              </div>
              <div>
                <p class="text-muted mb-0 small">Bénéfice net</p>
                <h4 class="mb-0 fw-bold" :class="stats.net_profit >= 0 ? 'text-success' : 'text-danger'">
                  {{ formatCurrency(stats.net_profit) }}
                </h4>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Produit le plus vendu -->
    <div v-if="topProducts.length > 0" class="card mb-4">
      <div class="card-header">
        <h5 class="card-title mb-0">
          <i class="bi bi-trophy text-warning me-2"></i>
          Produit le plus vendu
        </h5>
      </div>
      <div class="card-body">
        <div class="row align-items-center">
          <div class="col-md-8">
            <div class="d-flex align-items-center">
              <div 
                v-if="topProducts[0].image_url"
                class="flex-shrink-0 me-3"
              >
                <div class="bg-light rounded overflow-hidden d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                  <img
                    :src="topProducts[0].image_url"
                    :alt="topProducts[0].name"
                    class="img-fluid"
                    style="max-width: 100%; max-height: 100%; object-fit: contain;"
                  />
                </div>
              </div>
              <div
                v-else
                class="p-3 bg-warning bg-opacity-10 rounded me-3 flex-shrink-0"
                style="width: 80px; height: 80px; display: flex; align-items: center; justify-content: center;"
              >
                <i class="bi bi-box text-warning fs-2"></i>
              </div>
              <div>
                <h4 class="mb-1">{{ topProducts[0].name }}</h4>
                <p class="text-muted mb-2">
                  <span 
                    v-if="topProducts[0].category"
                    class="badge" 
                    :style="{ backgroundColor: topProducts[0].category.color + '20', color: topProducts[0].category.color }"
                  >
                    {{ topProducts[0].category.name }}
                  </span>
                  <span v-else class="badge bg-secondary">
                    Catégorie supprimée
                  </span>
                </p>
                <div class="row g-3">
                  <div class="col-sm-6">
                    <div class="d-flex align-items-center">
                      <i class="bi bi-cart-check text-success me-2"></i>
                      <div>
                        <p class="text-muted small mb-0">Ventes</p>
                        <p class="fw-bold mb-0 text-success">{{ topProducts[0].sale_items_count }}</p>
                      </div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="d-flex align-items-center">
                      <i class="bi bi-currency-euro text-primary me-2"></i>
                      <div>
                        <p class="text-muted small mb-0">Prix</p>
                        <p class="fw-bold mb-0 text-primary">{{ formatCurrency(topProducts[0].price) }}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-4 text-md-end">
            <Link
              :href="route('products.show', { id: topProducts[0].id })"
              class="btn btn-outline-primary"
            >
              <i class="bi bi-eye me-1"></i>
              Voir le produit
            </Link>
          </div>
        </div>
      </div>
    </div>

    <!-- Graphique des produits les plus vendus -->
    <div class="card mb-4">
      <div class="card-header">
        <h5 class="card-title mb-0">
          <i class="bi bi-bar-chart me-2"></i>
          Top 10 produits les plus vendus
        </h5>
      </div>
      <div class="card-body">
        <div v-if="topProductsForChart.length === 0" class="text-center text-muted py-5">
          <i class="bi bi-bar-chart fs-1 mb-3"></i>
          <p>Aucune donnée de vente disponible</p>
        </div>
        <div v-else>
          <ProductSalesChart :data="topProductsForChart" />
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <!-- Produits en rupture de stock -->
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0">Produits en rupture de stock</h5>
          </div>
          <div class="card-body">
            <div v-if="lowStockProducts.length === 0" class="text-center text-muted py-4">
              <i class="bi bi-check-circle fs-1 mb-3"></i>
              <p>Aucun produit en rupture de stock</p>
            </div>
            <div v-else>
              <div
                v-for="product in lowStockProducts"
                :key="product.id"
                class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-2 hover-item"
              >
                <Link :href="route('products.show', { id: product.id })" class="text-decoration-none flex-grow-1">
                  <div>
                    <p class="fw-medium mb-1 text-dark">{{ product.name }}</p>
                    <p class="text-muted small mb-0">{{ product.category.name }}</p>
                  </div>
                </Link>
                <div class="text-end">
                  <p class="text-danger fw-medium mb-1">{{ product.stock_quantity }} {{ product.unit }}</p>
                  <p class="text-muted small mb-0">Min: {{ product.min_stock_level }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Ventes récentes -->
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0">Ventes récentes</h5>
          </div>
          <div class="card-body">
            <div v-if="recentSales.length === 0" class="text-center text-muted py-4">
              <i class="bi bi-cart-x fs-1 mb-3"></i>
              <p>Aucune vente récente</p>
            </div>
            <div v-else>
              <div
                v-for="sale in recentSales"
                :key="sale.id"
                class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-2 hover-item"
              >
                <Link :href="route('sales.show', { id: sale.id })" class="text-decoration-none flex-grow-1">
                  <div>
                    <p class="fw-medium mb-1 text-dark">{{ sale.sale_number }}</p>
                    <p class="text-muted small mb-0">
                      {{ sale.customer?.name || 'Client anonyme' }} - {{ formatDate(sale.created_at) }}
                    </p>
                  </div>
                </Link>
                <div class="text-end">
                  <p class="text-success fw-medium mb-1">{{ formatCurrency(sale.total_amount) }}</p>
                  <p class="text-muted small mb-0">{{ sale.payment_method }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Dépenses récentes -->
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0">Dépenses récentes</h5>
          </div>
          <div class="card-body">
            <div v-if="recentExpenses.length === 0" class="text-center text-muted py-4">
              <i class="bi bi-receipt fs-1 mb-3"></i>
              <p>Aucune dépense récente</p>
            </div>
            <div v-else>
              <div
                v-for="expense in recentExpenses"
                :key="expense.id"
                class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-2 hover-item"
              >
                <Link :href="route('expenses.show', { id: expense.id })" class="text-decoration-none flex-grow-1">
                  <div>
                    <p class="fw-medium mb-1 text-dark">{{ expense.title }}</p>
                    <p class="text-muted small mb-0">
                      {{ expense.category_label }} - {{ formatDate(expense.created_at) }}
                    </p>
                  </div>
                </Link>
                <div class="text-end">
                  <p class="text-danger fw-medium mb-1">{{ formatCurrency(expense.amount) }}</p>
                  <p class="text-muted small mb-0">{{ expense.payment_method_label }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { withDefaults, watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import ProductSalesChart from '@/components/ProductSalesChart.vue'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Props {
  stats: {
    total_products: number
    total_customers: number
    total_sales: number
    total_categories: number
    total_expenses: number
    low_stock_products: number
    total_revenue: number
    paid_sales_amount: number
    down_payments_amount: number
    validated_delivery_notes_amount: number
    total_expenses_amount: number
    total_costs: number
    net_profit: number
  }
  lowStockProducts: Array<{
    id: number
    name: string
    stock_quantity: number
    min_stock_level: number
    unit: string
    image_url?: string | null
    category: {
      name: string
    }
  }>
  lowStockProductsTotal?: number // Total réel de tous les produits en stock faible
  recentSales: Array<{
    id: number
    sale_number: string
    total_amount: number
    payment_method: string
    created_at: string
    customer?: {
      name: string
    }
  }>
  recentExpenses: Array<{
    id: number
    title: string
    amount: number
    category_label: string
    payment_method_label: string
    created_at: string
  }>
  topProductsForChart: Array<{
    id: number
    name: string
    sales_count: number
    total_quantity: number
  }>
  topProducts: Array<{
    id: number
    name: string
    price: number
    sale_items_count: number
    image_url?: string | null
    category: {
      name: string
      color?: string
    }
  }>
  expiringProducts?: Array<{
    id: number
    name: string
    expiration_date: string
    days_until_expiration: number | null
    image_url?: string | null
    category?: {
      name: string
    }
  }>
  expiringProductsTotal?: number // Total réel de tous les produits expirés
  salesDueToday?: Array<{
    id: number
    sale_number: string
    customer: string
    total_amount: number
    remaining_amount: number
    due_date: string
    payment_status: string
  }>
}

const props = withDefaults(defineProps<Props>(), {
  expiringProducts: () => [],
  salesDueToday: () => []
})

const { success, error } = useSweetAlert()
const page = usePage()

// Afficher les messages flash au chargement de la page
watch(() => page.props.flash, (flash) => {
  if (flash?.success) {
    success(flash.success)
  }
  if (flash?.error) {
    error(flash.error)
  }
}, { immediate: true, deep: true })

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const formatMonth = (month: string) => {
  const [year, monthNum] = month.split('-')
  const date = new Date(parseInt(year), parseInt(monthNum) - 1)
  return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' })
}
</script>

<style scoped>
.hover-card {
  transition: all 0.3s ease;
  cursor: pointer;
}

.hover-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.hover-card:hover .card-body {
  background-color: rgba(0, 0, 0, 0.02);
}

.hover-item {
  transition: all 0.2s ease;
  cursor: pointer;
}

.hover-item:hover {
  background-color: rgba(0, 0, 0, 0.05) !important;
  transform: translateX(2px);
}
</style>