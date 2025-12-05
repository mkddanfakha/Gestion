<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier le devis {{ quote.quote_number }}</h1>
        <p class="text-muted mb-0">Modifiez les informations du devis</p>
      </div>
      <Link
        :href="route('quotes.index')"
        class="btn btn-outline-secondary"
      >
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <form>
      <div class="row">
        <div class="col-lg-8">
          <!-- Informations générales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations générales</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Client</label>
                  <select
                    v-model="form.customer_id"
                    class="form-select"
                    :class="{ 'is-invalid': errors.customer_id }"
                  >
                    <option value="">Sélectionner un client (optionnel)</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                      {{ customer.name }}
                    </option>
                  </select>
                  <div v-if="errors.customer_id" class="invalid-feedback">{{ errors.customer_id }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Statut</label>
                  <select
                    v-model="form.status"
                    class="form-select"
                    :class="{ 'is-invalid': errors.status }"
                  >
                    <option value="draft">Brouillon</option>
                    <option value="sent">Envoyé</option>
                    <option value="accepted">Accepté</option>
                    <option value="rejected">Refusé</option>
                    <option value="expired">Expiré</option>
                  </select>
                  <div v-if="errors.status" class="invalid-feedback">{{ errors.status }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Date de validité (optionnel)</label>
                  <input
                    v-model="form.valid_until"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': errors.valid_until }"
                  />
                  <div v-if="errors.valid_until" class="invalid-feedback">{{ errors.valid_until }}</div>
                  <small class="form-text text-muted">Date limite de validité du devis</small>
                </div>
              </div>

              <div class="mt-3">
                <label class="form-label">Notes (optionnel)</label>
                <textarea
                  v-model="form.notes"
                  rows="3"
                  class="form-control"
                  placeholder="Ajoutez des notes sur ce devis..."
                  :class="{ 'is-invalid': errors.notes }"
                ></textarea>
                <div v-if="errors.notes" class="invalid-feedback">{{ errors.notes }}</div>
              </div>
            </div>
          </div>

          <!-- Articles du devis -->
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">Articles</h5>
              <div class="d-flex gap-2">
                <button
                  v-if="hasDuplicateProducts"
                  type="button"
                  @click="mergeDuplicateProducts"
                  class="btn btn-warning btn-sm"
                >
                  <i class="bi bi-arrow-down-up me-1"></i>
                  Fusionner les doublons
                </button>
                <button
                  type="button"
                  @click="addItem"
                  class="btn btn-primary btn-sm"
                >
                  <i class="bi bi-plus-circle me-1"></i>
                  Ajouter un article
                </button>
              </div>
            </div>
            <div class="card-body">
              <div v-if="form.items.length === 0" class="text-center py-4 text-muted">
                <i class="bi bi-cart-x fs-1 mb-3"></i>
                <p>Aucun article ajouté. Cliquez sur "Ajouter un article" pour commencer.</p>
              </div>

              <div v-else>
                <div
                  v-for="(item, index) in form.items"
                  :key="index"
                  class="border rounded p-3 mb-3"
                >
                  <div class="row g-3">
                    <div class="col-md-5">
                      <label class="form-label">
                        Produit <span class="text-danger">*</span>
                      </label>
                      <ProductAutocomplete
                        v-model="item.product_id"
                        :products="products"
                        :exclude-product-ids="getExcludedProductIds(index)"
                        :is-invalid="isProductDuplicate(index)"
                        placeholder="Rechercher un produit..."
                        @selected="(product) => handleProductSelected(product, index)"
                      />
                      <div v-if="isProductDuplicate(index)" class="invalid-feedback d-block">
                        Ce produit est déjà sélectionné dans ce devis.
                      </div>
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">
                        Quantité <span class="text-danger">*</span>
                      </label>
                      <input
                        v-model.number="item.quantity"
                        type="number"
                        min="1"
                        required
                        class="form-control"
                        @input="updateItemTotal(index)"
                      />
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">Prix unitaire</label>
                      <div class="input-group">
                        <input
                          v-model.number="item.unit_price"
                          type="number"
                          step="0.01"
                          min="0"
                          class="form-control"
                          :style="{ width: getPriceFieldWidth(item.unit_price) }"
                          @input="updateItemTotal(index)"
                        />
                        <span class="input-group-text">Fcfa</span>
                      </div>
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">Total</label>
                      <div class="input-group">
                        <input
                          v-model.number="item.total_price"
                          type="number"
                          step="0.01"
                          readonly
                          class="form-control"
                          :style="{ width: getPriceFieldWidth(item.total_price) }"
                        />
                        <span class="input-group-text">Fcfa</span>
                      </div>
                    </div>

                    <div class="col-md-1 d-flex align-items-end">
                      <button
                        type="button"
                        @click="removeItem(index)"
                        class="btn btn-outline-danger w-100"
                      >
                        <i class="bi bi-trash"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Résumé -->
        <div class="col-lg-4">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">Résumé du devis</h5>
            </div>
            <div class="card-body">
              <div class="d-flex justify-content-between mb-2">
                <span>Nombre d'articles:</span>
                <span class="fw-medium">{{ itemsCount }}</span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span>Sous-total:</span>
                <span class="fw-medium">{{ formatCurrency(subtotal) }}</span>
              </div>
              
              <!-- Champ Taxes -->
              <div class="mb-3">
                <label class="form-label">Taxes (Fcfa)</label>
                <div class="input-group">
                  <input
                    type="number"
                    v-model.number="form.tax_amount"
                    step="0.01"
                    min="0"
                    class="form-control"
                    placeholder="0.00"
                    :class="{ 'is-invalid': errors.tax_amount }"
                  >
                  <span class="input-group-text">Fcfa</span>
                </div>
                <div v-if="errors.tax_amount" class="invalid-feedback">{{ errors.tax_amount }}</div>
              </div>

              <!-- Champ Remise -->
              <div class="mb-3">
                <label class="form-label">Remise (Fcfa)</label>
                <div class="input-group">
                  <input
                    type="number"
                    v-model.number="form.discount_amount"
                    step="0.01"
                    min="0"
                    class="form-control"
                    placeholder="0.00"
                    :class="{ 'is-invalid': errors.discount_amount }"
                  >
                  <span class="input-group-text">Fcfa</span>
                </div>
                <div v-if="errors.discount_amount" class="invalid-feedback">{{ errors.discount_amount }}</div>
              </div>

              <hr>
              <div class="d-flex justify-content-between mb-3">
                <span class="fw-bold">Total:</span>
                <span class="fw-bold text-success fs-5">{{ formatCurrency(totalAmount) }}</span>
              </div>

              <div class="d-grid gap-2">
                <button
                  type="button"
                  @click="submit"
                  class="btn btn-primary"
                  :disabled="processing || form.items.length === 0"
                >
                  <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                  <i v-else class="bi bi-check-circle me-1"></i>
                  {{ processing ? 'Modification...' : 'Modifier le devis' }}
                </button>
                <Link
                  :href="route('quotes.index')"
                  class="btn btn-outline-secondary"
                >
                  Annuler
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import ProductAutocomplete from '@/components/ProductAutocomplete.vue'

interface Customer {
  id: number
  name: string
}

interface Product {
  id: number
  name: string
  price: number
}

interface QuoteItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

interface Quote {
  id: number
  quote_number: string
  customer_id?: number
  status: string
  notes?: string
  valid_until?: string
  tax_amount?: number
  discount_amount?: number
  quoteItems: QuoteItem[]
  items_count?: number
}

interface Props {
  quote: Quote
  customers: Customer[]
  products: Product[]
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

const form = useForm({
  customer_id: props.quote.customer_id || '',
  status: props.quote.status,
  notes: props.quote.notes || '',
  valid_until: props.quote.valid_until ? new Date(props.quote.valid_until).toISOString().split('T')[0] : '',
  tax_amount: parseFloat(String(props.quote.tax_amount)) || 0,
  discount_amount: parseFloat(String(props.quote.discount_amount)) || 0,
  items: props.quote.quoteItems ? props.quote.quoteItems.map(item => ({
    product_id: item.product_id || 0,
    quantity: parseInt(String(item.quantity)) || 1,
    unit_price: parseFloat(String(item.unit_price)) || 0,
    total_price: parseFloat(String(item.total_price)) || 0
  })) : []
})

const addItem = () => {
  form.items.push({
    product_id: 0,
    quantity: 1,
    unit_price: 0,
    total_price: 0
  })
}

const removeItem = (index: number) => {
  form.items.splice(index, 1)
}

const updateItemPrice = (index: number) => {
  const item = form.items[index]
  const product = props.products.find(p => p.id === item.product_id)
  if (product) {
    item.unit_price = product.price
    updateItemTotal(index)
  }
}

const updateItemTotal = (index: number) => {
  const item = form.items[index]
  const quantity = parseFloat(String(item.quantity)) || 0
  const unitPrice = parseFloat(String(item.unit_price)) || 0
  const total = quantity * unitPrice
  item.total_price = isNaN(total) ? 0 : total
}

// Vérifier si un produit est déjà sélectionné dans d'autres items
const isProductAlreadySelected = (productId: number, currentIndex: number): boolean => {
  if (!productId) return false
  return form.items.some((item, index) => 
    index !== currentIndex && item.product_id === productId
  )
}

// Vérifier si l'item actuel est en doublon
const isProductDuplicate = (index: number): boolean => {
  const currentItem = form.items[index]
  if (!currentItem.product_id) return false
  return isProductAlreadySelected(currentItem.product_id, index)
}

// Obtenir les IDs des produits à exclure pour un index donné
const getExcludedProductIds = (currentIndex: number): number[] => {
  return form.items
    .map((item, index) => index !== currentIndex ? item.product_id : null)
    .filter((id): id is number => id !== null && id > 0)
}

// Gérer la sélection d'un produit
const handleProductSelected = (product: Product, index: number) => {
  const item = form.items[index]
  item.product_id = product.id
  item.unit_price = product.price
  updateItemTotal(index)
}

// Vérifier s'il y a des produits dupliqués
const hasDuplicateProducts = computed(() => {
  const productIds = form.items.map(item => item.product_id).filter(id => id > 0)
  return productIds.length !== new Set(productIds).size
})

// Fusionner les produits dupliqués
const mergeDuplicateProducts = () => {
  const mergedItems: QuoteItem[] = []
  const productMap = new Map<number, QuoteItem>()

  form.items.forEach(item => {
    if (item.product_id > 0) {
      if (productMap.has(item.product_id)) {
        // Fusionner les quantités
        const existingItem = productMap.get(item.product_id)!
        existingItem.quantity += item.quantity
        existingItem.total_price = existingItem.quantity * existingItem.unit_price
      } else {
        // Ajouter le nouvel item
        productMap.set(item.product_id, { ...item })
      }
    }
  })

  form.items = Array.from(productMap.values())
}

// Calculer la largeur adaptative pour les champs de prix
const getPriceFieldWidth = (price: number): string => {
  if (!price || price === 0 || typeof price !== 'number' || isNaN(price)) {
    return '80px'
  }
  
  const formattedPrice = price.toFixed(2)
  const charCount = formattedPrice.length
  
  const baseWidth = 70
  const charWidth = 9
  const calculatedWidth = baseWidth + (charCount * charWidth)
  
  const minWidth = 70
  const maxWidth = 130
  const finalWidth = Math.max(minWidth, Math.min(maxWidth, calculatedWidth))
  
  return `${finalWidth}px`
}

const formatCurrency = (amount: number | string | null | undefined) => {
  if (amount === null || amount === undefined) {
    return '0 Fcfa'
  }
  
  const value = parseFloat(String(amount))
  if (isNaN(value)) {
    return '0 Fcfa'
  }
  
  return new Intl.NumberFormat('fr-FR').format(value) + ' Fcfa'
}

const itemsCount = computed(() => {
  return form.items.length
})

const subtotal = computed(() => {
  const total = form.items.reduce((total, item) => {
    const itemTotal = parseFloat(String(item.total_price)) || 0
    return total + itemTotal
  }, 0)
  return isNaN(total) ? 0 : total
})

const taxAmount = computed(() => {
  return parseFloat(String(form.tax_amount)) || 0
})

const discountAmount = computed(() => {
  return parseFloat(String(form.discount_amount)) || 0
})

const totalAmount = computed(() => {
  return subtotal.value + taxAmount.value - discountAmount.value
})

const submit = () => {
  if (form.items.length === 0) {
    error('Veuillez ajouter au moins un article.')
    return
  }

  // Transformer les données pour correspondre au contrôleur
  const formData = {
    customer_id: form.customer_id || null,
    status: form.status,
    notes: form.notes || null,
    valid_until: form.valid_until || null,
    tax_amount: form.tax_amount || 0,
    discount_amount: form.discount_amount || 0,
    items: form.items.map(item => ({
      product_id: item.product_id,
      quantity: item.quantity,
      unit_price: item.unit_price
    }))
  }

  form.transform(() => formData).put(route('quotes.update', { id: props.quote.id }), {
    onSuccess: () => {
      success('Devis modifié avec succès !')
    },
    onError: (errors) => {
      console.error('Erreurs de validation:', errors)
      error('Erreur lors de la modification du devis.')
    }
  })
}

const { errors, processing } = form
</script>

<style scoped>
/* Visibilité du placeholder */
.dark ::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

.dark input::placeholder,
.dark textarea::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

input::placeholder,
textarea::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}

.form-control::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}
</style>

