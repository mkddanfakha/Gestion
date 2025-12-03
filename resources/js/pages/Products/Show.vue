<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">{{ product.name }}</h1>
        <p class="text-muted mb-0">{{ product.sku }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('products.index')"
          class="btn btn-primary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <div class="row g-4">
      <!-- Informations principales -->
      <div class="col-lg-8">
        <!-- Image du produit -->
        <div v-if="product.media && product.media.length > 0" class="card mb-4">
          <div class="card-body">
            <div class="row g-3">
              <div
                v-for="media in product.media"
                :key="media.id"
                class="col-md-6"
              >
                <img
                  :src="media.url"
                  :alt="product.name"
                  class="img-fluid rounded"
                  style="max-height: 400px; object-fit: contain;"
                />
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations du produit</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label text-muted">Nom</label>
                <p class="mb-0">{{ product.name }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">SKU</label>
                <p class="mb-0 font-monospace">{{ product.sku }}</p>
              </div>
              
              <div v-if="product.barcode" class="col-md-6">
                <label class="form-label text-muted">Code-barres</label>
                <p class="mb-0 font-monospace">{{ product.barcode }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Catégorie</label>
                <p class="mb-0">
                  <span
                    v-if="product.category"
                    class="badge"
                    :style="{ backgroundColor: product.category.color + '20', color: product.category.color }"
                  >
                    {{ product.category.name }}
                  </span>
                  <span v-else class="text-muted">Non définie</span>
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Prix de vente</label>
                <p class="mb-0 fw-bold text-success">{{ formatPrice(product.price) }}</p>
              </div>
              
              <div v-if="product.cost_price" class="col-md-6">
                <label class="form-label text-muted">Prix de revient</label>
                <p class="mb-0">{{ formatPrice(product.cost_price) }}</p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Unité</label>
                <p class="mb-0">{{ product.unit }}</p>
              </div>
              
              <div v-if="product.location" class="col-md-6">
                <label class="form-label text-muted">Emplacement</label>
                <p class="mb-0">
                  <i class="bi bi-geo-alt me-1 text-primary"></i>
                  {{ product.location }}
                </p>
              </div>
              
              <div class="col-md-6">
                <label class="form-label text-muted">Statut</label>
                <p class="mb-0">
                  <span 
                    :class="[
                      'badge',
                      product.is_active ? 'bg-success' : 'bg-danger'
                    ]"
                  >
                    <i :class="['me-1', product.is_active ? 'bi bi-check-circle' : 'bi bi-x-circle']"></i>
                    {{ product.is_active ? 'Actif' : 'Inactif' }}
                  </span>
                </p>
              </div>
              
              <div v-if="product.expiration_date" class="col-md-6">
                <label class="form-label text-muted">Date d'expiration</label>
                <p class="mb-0">
                  <span :class="getExpirationBadgeClass(product.days_until_expiration)">
                    <i :class="getExpirationIconClass(product.days_until_expiration)" class="me-1"></i>
                    {{ formatDate(product.expiration_date) }}
                  </span>
                  <span 
                    v-if="product.days_until_expiration !== null && product.days_until_expiration !== undefined"
                    class="badge ms-2"
                    :class="getExpirationStatusBadgeClass(product.days_until_expiration)"
                  >
                    {{ getExpirationStatusText(product.days_until_expiration) }}
                  </span>
                </p>
              </div>
              
              <div v-if="product.expiration_date && (product.alert_threshold_value || product.alert_threshold_unit)" class="col-md-6">
                <label class="form-label text-muted">Seuil d'alerte</label>
                <p class="mb-0">
                  <span class="badge bg-info">
                    {{ product.alert_threshold_value }} 
                    {{ getAlertUnitLabel(product.alert_threshold_unit) }}
                    avant l'expiration
                  </span>
                </p>
              </div>
            </div>
            
            <div v-if="product.description" class="mt-4">
              <label class="form-label text-muted">Description</label>
              <p class="mb-0">{{ product.description }}</p>
            </div>
          </div>
        </div>

        <!-- Historique des ventes -->
        <div v-if="product.saleItems && product.saleItems.length > 0" class="card mt-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Historique des ventes</h5>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date de vente</th>
                    <th>Numéro de vente</th>
                    <th>Client</th>
                    <th>Quantité</th>
                    <th>Prix unitaire</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in product.saleItems" :key="item.id">
                    <td>{{ formatDate(item.sale.created_at) }}</td>
                    <td>
                      <code class="text-dark">{{ item.sale.sale_number }}</code>
                    </td>
                    <td>{{ item.sale.customer?.name || 'Client anonyme' }}</td>
                    <td>{{ item.quantity }} {{ product.unit }}</td>
                    <td>{{ formatPrice(item.unit_price) }}</td>
                    <td class="fw-medium text-success">{{ formatPrice(item.total_price) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="col-lg-4">
        <!-- Informations de stock -->
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Stock</h5>
          </div>
          <div class="card-body">
            <div class="text-center mb-3">
              <div class="display-4 fw-bold" :class="getStockClass(product.stock_quantity, product.min_stock_level)">
                {{ product.stock_quantity }}
              </div>
              <p class="text-muted mb-0">{{ product.unit }}</p>
            </div>
            
            <div class="mb-3">
              <label class="form-label text-muted">Stock minimum</label>
              <p class="mb-0">{{ product.min_stock_level }} {{ product.unit }}</p>
            </div>
            
            <div class="alert" :class="getStockAlertClass(product.stock_quantity, product.min_stock_level)" role="alert">
              <i :class="getStockIconClass(product.stock_quantity, product.min_stock_level)" class="me-2"></i>
              {{ getStockMessage(product.stock_quantity, product.min_stock_level) }}
            </div>
          </div>
        </div>

        <!-- Statistiques -->
        <div class="card mt-3">
          <div class="card-header">
            <h5 class="card-title mb-0">Statistiques</h5>
          </div>
          <div class="card-body">
            <div class="row g-3 text-center">
              <div class="col-6">
                <div class="p-2 bg-light rounded">
                  <div class="fw-bold text-primary">{{ product.sale_items_count || 0 }}</div>
                  <div class="small text-muted">Ventes</div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-2 bg-light rounded">
                  <div class="fw-bold text-success">{{ formatCurrency(product.total_sales || 0) }}</div>
                  <div class="small text-muted">Chiffre d'affaires</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Informations d'expiration -->
        <div v-if="product.expiration_date" class="card mt-3">
          <div class="card-header">
            <h5 class="card-title mb-0">
              <i class="bi bi-calendar-event me-2"></i>
              Expiration
            </h5>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label text-muted">Date d'expiration</label>
              <p class="mb-2 fw-bold">{{ formatDate(product.expiration_date) }}</p>
              <div v-if="product.days_until_expiration !== null && product.days_until_expiration !== undefined">
                <span 
                  class="badge"
                  :class="getExpirationStatusBadgeClass(product.days_until_expiration)"
                >
                  <i :class="getExpirationIconClass(product.days_until_expiration)" class="me-1"></i>
                  {{ getExpirationStatusText(product.days_until_expiration) }}
                </span>
              </div>
            </div>
            
            <div v-if="product.alert_threshold_value && product.alert_threshold_unit" class="mb-3">
              <label class="form-label text-muted">Configuration de l'alerte</label>
              <p class="mb-0">
                Alerte activée 
                <strong>{{ product.alert_threshold_value }} {{ getAlertUnitLabel(product.alert_threshold_unit) }}</strong>
                avant l'expiration
              </p>
            </div>
            
            <div v-if="product.days_until_expiration !== null && product.days_until_expiration !== undefined" 
                 class="alert" 
                 :class="getExpirationAlertClass(product.days_until_expiration)" 
                 role="alert">
              <i :class="getExpirationIconClass(product.days_until_expiration)" class="me-2"></i>
              {{ getExpirationAlertMessage(product.days_until_expiration) }}
            </div>
          </div>
        </div>

        <!-- Actions rapides -->
        <div class="card mt-3">
          <div class="card-header">
            <h5 class="card-title mb-0">Actions rapides</h5>
          </div>
          <div class="card-body">
            <div class="d-grid gap-2">
              <Link
                v-if="canEdit('products')"
                :href="route('products.edit', { id: product.id })"
                class="btn btn-outline-primary"
              >
                <i class="bi bi-pencil me-1"></i>
                Modifier le produit
              </Link>
              <button
                v-if="canDelete('products')"
                @click="deleteProduct"
                class="btn btn-outline-danger"
              >
                <i class="bi bi-trash me-1"></i>
                Supprimer le produit
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { usePermissions } from '@/composables/usePermissions'

const { canEdit, canDelete } = usePermissions()

interface Category {
  id: number
  name: string
  color?: string
}

interface Sale {
  id: number
  created_at: string
  sale_number: string
  customer?: {
    name: string
  }
}

interface SaleItem {
  id: number
  quantity: number
  unit_price: number
  total_price: number
  sale: Sale
}

interface Media {
  id: number
  url: string
  name: string
}

interface Product {
  location?: string
  id: number
  name: string
  description?: string
  sku: string
  barcode?: string
  price: number
  cost_price?: number
  stock_quantity: number
  min_stock_level: number
  unit: string
  is_active: boolean
  expiration_date?: string
  alert_threshold_value?: number
  alert_threshold_unit?: 'days' | 'weeks' | 'months'
  days_until_expiration?: number | null
  category?: Category
  saleItems?: SaleItem[]
  sale_items_count?: number
  total_sales?: number
  media?: Media[]
}

interface Props {
  product: Product
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()
const page = usePage()

// Afficher les messages flash au chargement de la page
watch(() => (page.props as any)?.flash, (flash: any) => {
  if (flash?.success) {
    success(flash.success)
  }
  if (flash?.error) {
    error(flash.error)
  }
}, { immediate: true, deep: true })

const formatPrice = (price: number) => {
  return new Intl.NumberFormat('fr-FR').format(price) + ' Fcfa'
}

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const getStockClass = (stockQuantity: number, minStockLevel: number) => {
  if (stockQuantity === 0) {
    return 'text-danger'
  } else if (stockQuantity <= minStockLevel) {
    return 'text-warning'
  } else {
    return 'text-success'
  }
}

const getStockAlertClass = (stockQuantity: number, minStockLevel: number) => {
  if (stockQuantity === 0) {
    return 'alert-danger'
  } else if (stockQuantity <= minStockLevel) {
    return 'alert-warning'
  } else {
    return 'alert-success'
  }
}

const getStockIconClass = (stockQuantity: number, minStockLevel: number) => {
  if (stockQuantity === 0) {
    return 'bi bi-exclamation-triangle'
  } else if (stockQuantity <= minStockLevel) {
    return 'bi bi-exclamation-triangle'
  } else {
    return 'bi bi-check-circle'
  }
}

const getStockMessage = (stockQuantity: number, minStockLevel: number) => {
  if (stockQuantity === 0) {
    return 'Rupture de stock'
  } else if (stockQuantity <= minStockLevel) {
    return `Stock faible (minimum: ${minStockLevel})`
  } else {
    return 'Stock suffisant'
  }
}

const getExpirationBadgeClass = (daysUntilExpiration: number | null | undefined) => {
  if (daysUntilExpiration === null || daysUntilExpiration === undefined) {
    return 'text-muted'
  }
  if (daysUntilExpiration < 0) {
    return 'text-danger'
  } else if (daysUntilExpiration === 0) {
    return 'text-danger'
  } else if (daysUntilExpiration <= 7) {
    return 'text-warning'
  } else {
    return 'text-info'
  }
}

const getExpirationStatusBadgeClass = (daysUntilExpiration: number | null | undefined) => {
  if (daysUntilExpiration === null || daysUntilExpiration === undefined) {
    return 'bg-secondary'
  }
  if (daysUntilExpiration < 0) {
    return 'bg-danger'
  } else if (daysUntilExpiration === 0) {
    return 'bg-danger'
  } else if (daysUntilExpiration <= 7) {
    return 'bg-warning text-dark'
  } else {
    return 'bg-info'
  }
}

const getExpirationIconClass = (daysUntilExpiration: number | null | undefined) => {
  if (daysUntilExpiration === null || daysUntilExpiration === undefined) {
    return 'bi bi-calendar'
  }
  if (daysUntilExpiration < 0) {
    return 'bi bi-exclamation-triangle-fill'
  } else if (daysUntilExpiration === 0) {
    return 'bi bi-exclamation-triangle-fill'
  } else if (daysUntilExpiration <= 7) {
    return 'bi bi-exclamation-triangle'
  } else {
    return 'bi bi-calendar-check'
  }
}

const getExpirationStatusText = (daysUntilExpiration: number | null | undefined) => {
  if (daysUntilExpiration === null || daysUntilExpiration === undefined) {
    return 'Non défini'
  }
  if (daysUntilExpiration < 0) {
    return `Expiré depuis ${Math.abs(daysUntilExpiration)} jour(s)`
  } else if (daysUntilExpiration === 0) {
    return 'Expire aujourd\'hui'
  } else {
    return `Expire dans ${daysUntilExpiration} jour(s)`
  }
}

const getExpirationAlertClass = (daysUntilExpiration: number | null | undefined) => {
  if (daysUntilExpiration === null || daysUntilExpiration === undefined) {
    return 'alert-secondary'
  }
  if (daysUntilExpiration < 0) {
    return 'alert-danger'
  } else if (daysUntilExpiration === 0) {
    return 'alert-danger'
  } else if (daysUntilExpiration <= 7) {
    return 'alert-warning'
  } else {
    return 'alert-info'
  }
}

const getExpirationAlertMessage = (daysUntilExpiration: number | null | undefined) => {
  if (daysUntilExpiration === null || daysUntilExpiration === undefined) {
    return 'Date d\'expiration non définie'
  }
  if (daysUntilExpiration < 0) {
    return `⚠️ Ce produit a expiré il y a ${Math.abs(daysUntilExpiration)} jour(s). Veuillez le retirer de la vente.`
  } else if (daysUntilExpiration === 0) {
    return '⚠️ Ce produit expire aujourd\'hui. Action requise immédiatement.'
  } else if (daysUntilExpiration <= 7) {
    return `⚠️ Ce produit expire dans ${daysUntilExpiration} jour(s). Pensez à le vendre en priorité.`
  } else {
    return `ℹ️ Ce produit expire dans ${daysUntilExpiration} jour(s).`
  }
}

const getAlertUnitLabel = (unit: string | undefined) => {
  if (!unit) return ''
  const labels: Record<string, string> = {
    'days': 'jour(s)',
    'weeks': 'semaine(s)',
    'months': 'mois'
  }
  return labels[unit] || unit
}

const getTotalSales = () => {
  if (!props.product.saleItems) return 0
  return props.product.saleItems.reduce((total, item) => total + item.total_price, 0)
}

const deleteProduct = async () => {
  const confirmed = await confirm('Êtes-vous sûr de vouloir supprimer ce produit ?')
  
  if (confirmed) {
    router.delete(route('products.destroy', { id: props.product.id }), {
      onSuccess: (page: any) => {
        if (page.props?.flash?.success) {
          success(page.props.flash.success)
        } else {
          // Message de succès par défaut si aucun message flash
          success(`Produit "${props.product.name}" supprimé avec succès !`)
        }
      },
      onError: (errors) => {
        // Capturer les erreurs de validation depuis la session
        if (errors && typeof errors === 'object') {
          const errorMessage = Object.values(errors)[0] as string
          if (errorMessage) {
            error(errorMessage)
          }
        } else {
          error('Erreur lors de la suppression du produit.')
        }
      }
    })
  }
}
</script>
