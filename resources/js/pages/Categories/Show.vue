<template>
  <AppLayout>
    <div class="min-vh-100 bg-light">
      <!-- Header -->
      <div class="bg-white shadow-sm">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center py-4">
            <div>
              <div class="d-flex align-items-center">
                <div
                  class="rounded-circle me-3"
                  style="width: 24px; height: 24px;"
                  :style="{ backgroundColor: category.color }"
                ></div>
                <h1 class="h2 mb-0 text-dark">{{ category.name }}</h1>
              </div>
              <p class="text-muted mb-0 mt-1">{{ category.products_count || 0 }} produit(s)</p>
            </div>
            <div class="d-flex gap-2">
              <Link
                :href="route('categories.index')"
                class="btn btn-outline-secondary"
              >
                Retour à la liste
              </Link>
            </div>
          </div>
        </div>
      </div>

      <div class="container-fluid py-4">
        <div class="row g-4">
          <!-- Informations de la catégorie -->
          <div class="col-lg-4">
            <div class="card h-100">
              <div class="card-body">
                <h5 class="card-title">Informations</h5>
                
                <dl class="row">
                  <dt class="col-sm-4 text-muted">Nom</dt>
                  <dd class="col-sm-8">{{ category.name }}</dd>
                  
                  <dt class="col-sm-4 text-muted">Description</dt>
                  <dd class="col-sm-8">
                    {{ category.description || 'Aucune description' }}
                  </dd>
                  
                  <dt class="col-sm-4 text-muted">Couleur</dt>
                  <dd class="col-sm-8 d-flex align-items-center">
                    <div
                      class="rounded-circle me-2"
                      style="width: 16px; height: 16px;"
                      :style="{ backgroundColor: category.color }"
                    ></div>
                    <code class="text-dark">{{ category.color }}</code>
                  </dd>
                  
                  <dt class="col-sm-4 text-muted">Nombre de produits</dt>
                  <dd class="col-sm-8">{{ category.products_count || 0 }}</dd>
                </dl>
                
                <!-- Boutons d'action -->
                <div class="mt-4 pt-3 border-top">
                  <div class="d-flex gap-2">
                    <Link
                      :href="route('categories.edit', { id: category.id })"
                      class="btn btn-primary flex-fill"
                    >
                      <i class="bi bi-pencil me-1"></i>
                      Modifier
                    </Link>
                    <button
                      @click="deleteCategory"
                      class="btn btn-danger flex-fill"
                    >
                      <i class="bi bi-trash me-1"></i>
                      Supprimer
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Liste des produits -->
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header">
                <h5 class="card-title mb-0">Produits de cette catégorie</h5>
              </div>
              
              <div v-if="category.products && category.products.length > 0" class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Produit</th>
                      <th>SKU</th>
                      <th>Prix</th>
                      <th>
                        <div class="d-flex align-items-center">
                          <span>Stock</span>
                          <small class="text-muted ms-1">(quantité)</small>
                        </div>
                      </th>
                      <th>
                        <div class="d-flex align-items-center">
                          <span>Statut</span>
                          <small class="text-muted ms-1">(actif/inactif)</small>
                        </div>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="product in category.products" :key="product.id">
                      <td class="align-middle">
                        <div class="d-flex align-items-center">
                          <div class="flex-shrink-0 me-3">
                            <div 
                              v-if="product.image_url"
                              class="bg-light rounded overflow-hidden d-flex align-items-center justify-content-center"
                              style="width: 60px; height: 60px;"
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
                              style="width: 60px; height: 60px;"
                            >
                              <i class="bi bi-box text-muted"></i>
                            </div>
                          </div>
                          <div>
                            <div class="fw-medium">
                              <Link
                                :href="route('products.show', { id: product.id })"
                                class="text-decoration-none"
                              >
                                {{ product.name }}
                              </Link>
                            </div>
                            <div class="text-muted small">
                              {{ product.description || 'Aucune description' }}
                            </div>
                          </div>
                        </div>
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
                          <span 
                            v-if="product.stock_quantity <= product.min_stock_level"
                            class="badge bg-warning text-dark ms-2"
                          >
                            ⚠️ Stock faible
                          </span>
                        </div>
                      </td>
                      <td class="align-middle">
                        <span
                          :class="[
                            'badge',
                            product.is_active ? 'bg-success' : 'bg-danger'
                          ]"
                        >
                          <span class="me-1">
                            {{ product.is_active ? '✓' : '✗' }}
                          </span>
                          {{ product.is_active ? 'Actif' : 'Inactif' }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              
              <div v-else class="text-center py-5">
                <div class="text-muted mb-3">
                  <i class="bi bi-box fs-1"></i>
                </div>
                <h5 class="text-dark mb-2">Aucun produit</h5>
                <p class="text-muted mb-4">Cette catégorie ne contient aucun produit pour le moment.</p>
                <Link
                  :href="route('products.create')"
                  class="btn btn-primary"
                >
                  Créer un produit
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { Package } from 'lucide-vue-next'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Product {
  id: number
  name: string
  description?: string
  sku: string
  price: number
  stock_quantity: number
  min_stock_level: number
  unit: string
  is_active: boolean
  image_url?: string | null
}

interface Category {
  id: number
  name: string
  description?: string
  color: string
  products_count?: number
  products?: Product[]
}

interface Props {
  category: Category
}

const props = defineProps<Props>()

const { success, error, confirm } = useSweetAlert()

const formatPrice = (price: number) => {
  return new Intl.NumberFormat('fr-FR').format(price) + ' Fcfa'
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

const deleteCategory = async () => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer la catégorie "${props.category.name}" ?`)
  
  if (confirmed) {
    router.delete(route('categories.destroy', { id: props.category.id }), {
      onSuccess: (page: any) => {
        if (page.props?.flash?.success) {
          success(page.props.flash.success)
        } else {
          // Message de succès par défaut si aucun message flash
          success(`Catégorie "${props.category.name}" supprimée avec succès !`)
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
          error('Erreur lors de la suppression de la catégorie.')
        }
      }
    })
  }
}
</script>
