<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-box me-2"></i>
          Produits
        </h1>
        <p class="text-muted mb-0">Gérez votre inventaire de produits</p>
      </div>
      <Link
        v-if="canCreate('products')"
        :href="route('products.create')"
        class="btn btn-primary"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Ajouter un produit
      </Link>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Recherche</label>
            <input
              v-model="filters.search"
              type="text"
              placeholder="Nom, SKU ou code-barres..."
              class="form-control"
              @input="search"
            />
          </div>
          <div class="col-md-3">
            <label class="form-label">Catégorie</label>
            <select
              v-model="filters.category_id"
              class="form-select"
              @change="search"
            >
              <option value="">Toutes les catégories</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">
                {{ category.name }}
              </option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Statut du stock</label>
            <select
              v-model="filters.stock_status"
              class="form-select"
              @change="search"
            >
              <option value="">Tous</option>
              <option value="low">Stock faible</option>
              <option value="out">Rupture de stock</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Expiration</label>
            <select
              v-model="filters.expiration_alert"
              class="form-select"
              @change="search"
            >
              <option value="">Tous</option>
              <option :value="true">Expirés/Proches</option>
            </select>
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button
              @click="clearFilters"
              class="btn btn-outline-secondary w-100"
            >
              <i class="bi bi-x-circle me-1"></i>
              Effacer les filtres
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tableau des produits -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Produit</th>
              <th>Catégorie</th>
              <th>SKU</th>
              <th>Prix</th>
              <th>Stock</th>
              <th>Expiration</th>
              <th>Statut</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="product in products.data" :key="product.id">
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-3">
                    <div 
                      v-if="product.image_url"
                      class="bg-light rounded overflow-hidden d-flex align-items-center justify-content-center"
                      style="width: 110px; height: 110px;"
                    >
                      <img
                        :src="product.image_url"
                        :alt="product.name"
                        class="img-fluid"
                        style="width: 100%; height: 100%; object-fit: cover;"
                      />
                    </div>
                    <div
                      v-else
                      class="bg-light rounded d-flex align-items-center justify-content-center"
                      style="width: 110px; height: 110px;"
                    >
                      <i class="bi bi-box text-muted fs-5"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ product.name }}</div>
                    <div class="text-muted small">{{ product.description || 'Aucune description' }}</div>
                  </div>
                </div>
              </td>
              <td class="align-middle">
                <span
                  v-if="product.category"
                  class="badge"
                  :style="{ backgroundColor: product.category.color + '20', color: product.category.color }"
                >
                  {{ product.category.name }}
                </span>
                <span v-else class="badge bg-secondary">
                  Catégorie supprimée
                </span>
              </td>
              <td class="align-middle">
                <code class="text-dark">{{ product.sku }}</code>
              </td>
              <td class="align-middle">{{ formatPrice(product.price) }}</td>
              <td class="align-middle">
                <div class="d-flex align-items-center">
                  <span 
                    :class="[
                      'fw-medium',
                      getStockClass(product.stock_quantity, product.min_stock_level)
                    ]"
                  >
                    {{ product.stock_quantity }}
                  </span>
                  <span class="text-muted ms-1">{{ product.unit }}</span>
                </div>
              </td>
              <td class="align-middle">
                <div v-if="product.expiration_date">
                  <div class="small">{{ formatDate(product.expiration_date) }}</div>
                  <span 
                    v-if="product.days_until_expiration !== null && product.days_until_expiration !== undefined"
                    :class="[
                      'badge',
                      (product.days_until_expiration ?? 0) < 0 ? 'bg-danger' : 
                      (product.days_until_expiration ?? 0) === 0 ? 'bg-danger' :
                      (product.days_until_expiration ?? 0) <= 7 ? 'bg-warning text-dark' :
                      'bg-info'
                    ]"
                  >
                    <span v-if="(product.days_until_expiration ?? 0) < 0">
                      Expiré ({{ Math.abs(product.days_until_expiration ?? 0) }}j)
                    </span>
                    <span v-else-if="(product.days_until_expiration ?? 0) === 0">
                      Expire aujourd'hui
                    </span>
                    <span v-else>
                      {{ product.days_until_expiration }} jour(s)
                    </span>
                  </span>
                </div>
                <span v-else class="text-muted small">-</span>
              </td>
              <td class="align-middle">
                <span
                  v-if="product.stock_quantity === 0"
                  class="badge bg-danger"
                >
                  Rupture
                </span>
                <span
                  v-else-if="product.stock_quantity <= product.min_stock_level"
                  class="badge bg-warning text-dark"
                >
                  Stock faible
                </span>
                <span
                  v-else
                  class="badge bg-success"
                >
                  En stock
                </span>
              </td>
              <td class="text-end align-middle">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('products.show', { id: product.id })"
                    class="btn btn-sm btn-outline-primary"
                    title="Voir"
                  >
                    <i class="bi bi-eye"></i>
                  </Link>
                  <Link
                    v-if="canEdit('products')"
                    :href="route('products.edit', { id: product.id })"
                    class="btn btn-sm btn-outline-secondary"
                    title="Modifier"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    v-if="canDelete('products')"
                    @click="deleteProduct(product)"
                    class="btn btn-sm btn-outline-danger"
                    title="Supprimer"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="products.links" class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-none d-md-block">
            <p class="text-muted mb-0">
              Affichage de
              <span class="fw-medium">{{ products.from }}</span>
              à
              <span class="fw-medium">{{ products.to }}</span>
              sur
              <span class="fw-medium">{{ products.total }}</span>
              résultats
            </p>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <template v-for="link in products.links" :key="link.label">
                <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                  <Link
                    v-if="link.url"
                    :href="link.url"
                    class="page-link"
                    v-html="link.label"
                  />
                  <span
                    v-else
                    class="page-link"
                    v-html="link.label"
                  />
                </li>
              </template>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { usePermissions } from '@/composables/usePermissions'

const { canCreate, canEdit, canDelete } = usePermissions()

interface Product {
  id: number
  name: string
  description?: string
  sku: string
  price: number
  stock_quantity: number
  min_stock_level: number
  unit: string
  expiration_date?: string
  days_until_expiration?: number | null
  image_url?: string | null
  category: {
    id: number
    name: string
    color: string
  }
}

interface PaginatedProducts {
  data: Product[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number
  to: number
  prev_page_url: string | null
  next_page_url: string | null
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
}

interface Category {
  id: number
  name: string
}

interface Props {
  products: PaginatedProducts
  categories: Category[]
  filters: {
    category_id?: number
    search?: string
    stock_status?: string
    expiration_alert?: boolean
  }
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const formatPrice = (price: number) => {
  return new Intl.NumberFormat('fr-FR').format(price) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR')
}

const filters = ref({ ...props.filters })

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
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

const search = () => {
  router.get(route('products.index'), filters.value, {
    preserveState: true,
    replace: true
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const deleteProduct = async (product: Product) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer le produit "${product.name}" ?`)
  
  if (confirmed) {
    router.delete(route('products.destroy', { id: product.id }), {
      onSuccess: (page: any) => {
        if (page.props?.flash?.success) {
          success(page.props.flash.success)
        } else {
          // Message de succès par défaut si aucun message flash
          success(`Produit "${product.name}" supprimé avec succès !`)
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

<style scoped>
/* Visibilité du placeholder */
.dark ::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

.dark input::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

input::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}

.form-control::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}
</style>
