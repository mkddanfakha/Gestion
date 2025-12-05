<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier la vente {{ sale.sale_number }}</h1>
        <p class="text-muted mb-0">Modifiez les informations de la vente</p>
      </div>
      <Link
        :href="route('sales.index')"
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
                  <CustomerAutocomplete
                    :customers="customers"
                    v-model="form.customer_id"
                    placeholder="Rechercher un client (optionnel)..."
                    :is-invalid="!!errors.customer_id"
                  />
                  <div v-if="errors.customer_id" class="invalid-feedback d-block">{{ errors.customer_id }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Mode de paiement <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.payment_method"
                    required
                    class="form-select"
                    :class="{ 'is-invalid': errors.payment_method || clientErrors.payment_method }"
                    @blur="validateField('payment_method', form.payment_method)"
                    @change="validateField('payment_method', form.payment_method)"
                  >
                    <option value="">Sélectionner un mode de paiement</option>
                    <option value="cash">Espèces</option>
                    <option value="card">Carte</option>
                    <option value="bank_transfer">Virement bancaire</option>
                    <option value="check">Chèque</option>
                    <option value="orange_money">Orange Money</option>
                    <option value="wave">Wave</option>
                  </select>
                  <div v-if="errors.payment_method" class="invalid-feedback">{{ errors.payment_method }}</div>
                  <div v-if="clientErrors.payment_method" class="invalid-feedback">{{ clientErrors.payment_method }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Date d'échéance (optionnel)</label>
                  <input
                    v-model="form.due_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': errors.due_date }"
                  />
                  <div v-if="errors.due_date" class="invalid-feedback">{{ errors.due_date }}</div>
                  <small class="form-text text-muted">Date limite de paiement</small>
                </div>
              </div>

              <div class="mt-3">
                <label class="form-label">Notes (optionnel)</label>
                <textarea
                  v-model="form.notes"
                  rows="3"
                  class="form-control"
                  placeholder="Ajoutez des notes sur cette vente..."
                  :class="{ 'is-invalid': errors.notes }"
                ></textarea>
                <div v-if="errors.notes" class="invalid-feedback">{{ errors.notes }}</div>
              </div>
            </div>
          </div>

          <!-- Articles de la vente -->
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
                    <div class="col-md-4">
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
                        Ce produit est déjà sélectionné dans cette vente.
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
                        :max="getMaxQuantity(index)"
                        required
                        class="form-control"
                        :class="{ 'is-invalid': isQuantityExceedsStock(index) }"
                        @input="updateItemTotal(index)"
                      />
                      <div v-if="isQuantityExceedsStock(index)" class="invalid-feedback">
                        Stock insuffisant. Disponible: {{ getAvailableStock(index) }} {{ getProductUnit(index) }}
                      </div>
                      <div v-else-if="item.product_id" class="form-text text-muted">
                        Stock disponible: {{ getAvailableStock(index) }} {{ getProductUnit(index) }}
                      </div>
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

                    <div class="col-md-2 d-flex align-items-end">
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
              <h5 class="card-title mb-0">Résumé de la vente</h5>
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
                    :class="{ 'is-invalid': errors.tax_amount || clientErrors.tax_amount }"
                  >
                  <span class="input-group-text">Fcfa</span>
                </div>
                <div v-if="errors.tax_amount" class="invalid-feedback">{{ errors.tax_amount }}</div>
                <div v-if="clientErrors.tax_amount" class="invalid-feedback">{{ clientErrors.tax_amount }}</div>
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
                    :class="{ 'is-invalid': errors.discount_amount || clientErrors.discount_amount }"
                  >
                  <span class="input-group-text">Fcfa</span>
                </div>
                <div v-if="errors.discount_amount" class="invalid-feedback">{{ errors.discount_amount }}</div>
                <div v-if="clientErrors.discount_amount" class="invalid-feedback">{{ clientErrors.discount_amount }}</div>
              </div>

              <!-- Champ Acompte -->
              <div class="mb-3">
                <label class="form-label">Acompte (Fcfa)</label>
                <div class="input-group">
                  <input
                    type="number"
                    v-model.number="form.down_payment_amount"
                    step="0.01"
                    min="0"
                    :max="totalAmount"
                    class="form-control"
                    placeholder="0.00"
                    :class="{ 'is-invalid': errors.down_payment_amount || clientErrors.down_payment_amount }"
                  >
                  <span class="input-group-text">Fcfa</span>
                </div>
                <div v-if="errors.down_payment_amount" class="invalid-feedback">{{ errors.down_payment_amount }}</div>
                <div v-if="clientErrors.down_payment_amount" class="invalid-feedback">{{ clientErrors.down_payment_amount }}</div>
                <div class="form-text text-muted">
                  Montant maximum: {{ formatCurrency(totalAmount) }}
                </div>
              </div>

              <hr>
              <div class="d-flex justify-content-between mb-2">
                <span class="fw-bold">Total:</span>
                <span class="fw-bold text-success fs-5">{{ formatCurrency(totalAmount) }}</span>
              </div>
              
              <div v-if="form.down_payment_amount > 0" class="d-flex justify-content-between mb-2">
                <span>Acompte:</span>
                <span class="fw-medium text-primary">{{ formatCurrency(form.down_payment_amount) }}</span>
              </div>
              
              <div v-if="form.down_payment_amount > 0" class="d-flex justify-content-between mb-3">
                <span class="fw-bold">Reste à payer:</span>
                <span class="fw-bold text-warning fs-5">{{ formatCurrency(remainingAmount) }}</span>
              </div>

              <div class="d-grid gap-2">
                <button
                  type="button"
                  @click="submit"
                  class="btn btn-success"
                  :disabled="processing || form.items.length === 0"
                >
                  <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                  <i v-else class="bi bi-check-circle me-1"></i>
                  {{ processing ? 'Modification...' : 'Modifier la vente' }}
                </button>
                
                <!-- Bouton pour marquer comme payé -->
                <button
                  v-if="form.payment_status !== 'paid'"
                  type="button"
                  @click="markAsPaid"
                  class="btn btn-primary"
                  :disabled="processing"
                >
                  <i class="bi bi-credit-card me-1"></i>
                  Marquer comme payé
                </button>
                
                <!-- Bouton pour marquer comme non payé -->
                <button
                  v-if="form.payment_status === 'paid'"
                  type="button"
                  @click="markAsUnpaid"
                  class="btn btn-warning"
                  :disabled="processing"
                >
                  <i class="bi bi-x-circle me-1"></i>
                  Marquer comme non payé
                </button>
                
                <Link
                  :href="route('sales.index')"
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
import { computed, ref, watch } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import ProductAutocomplete from '@/components/ProductAutocomplete.vue'
import CustomerAutocomplete from '@/components/CustomerAutocomplete.vue'

interface Customer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
}

interface Category {
  id: number
  name: string
  color: string
}

interface Product {
  id: number
  name: string
  price: number
  stock_quantity: number
  unit: string
  category?: Category
  image_url?: string | null
}

interface SaleItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

interface Sale {
  id: number
  sale_number: string
  customer_id?: number
  payment_method: string
  notes?: string
  due_date?: string
  tax_amount?: number
  discount_amount?: number
  down_payment_amount?: number
  remaining_amount?: number
  payment_status?: string
  saleItems: SaleItem[]
  items_count?: number  // Ajout du champ items_count
}

interface Props {
  sale: Sale
  customers: Customer[]
  products: Product[]
}

const props = defineProps<Props>()

// Données de la vente initialisées

const { success, error, confirmWithDetails } = useSweetAlert()

// État des erreurs de validation côté client
const clientErrors = ref<Record<string, string>>({})

const form = useForm({
  customer_id: props.sale.customer_id || '',
  payment_method: props.sale.payment_method,
  notes: props.sale.notes || '',
  due_date: props.sale.due_date ? new Date(props.sale.due_date).toISOString().split('T')[0] : '',
  tax_amount: parseFloat(String(props.sale.tax_amount)) || 0,
  discount_amount: parseFloat(String(props.sale.discount_amount)) || 0,
  down_payment_amount: parseFloat(String(props.sale.down_payment_amount)) || 0,
  payment_status: props.sale.payment_status || 'pending',
  items: props.sale.saleItems ? props.sale.saleItems.map(item => ({
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

const handleProductSelected = (product: Product, index: number) => {
  const item = form.items[index]
  item.product_id = product.id
  item.unit_price = product.price
  updateItemTotal(index)
}

const updateItemPrice = (index: number) => {
  const item = form.items[index]
  const product = props.products.find(p => p.id === item.product_id)
  if (product) {
    item.unit_price = product.price
    updateItemTotal(index)
  }
}

// Obtenir les IDs des produits à exclure pour un index donné
const getExcludedProductIds = (currentIndex: number): number[] => {
  return form.items
    .map((item, index) => index !== currentIndex ? item.product_id : null)
    .filter((id): id is number => id !== null && id > 0)
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

// Vérifier s'il y a des produits dupliqués
const hasDuplicateProducts = computed(() => {
  const productIds = form.items.map(item => item.product_id).filter(id => id > 0)
  return productIds.length !== new Set(productIds).size
})

// Fusionner les produits dupliqués
const mergeDuplicateProducts = () => {
  const mergedItems: SaleItem[] = []
  const productMap = new Map<number, SaleItem>()

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

// Vérifier si la quantité dépasse le stock disponible
const isQuantityExceedsStock = (index: number): boolean => {
  const item = form.items[index]
  if (!item.product_id || !item.quantity) return false
  const availableStock = getAvailableStock(index)
  return item.quantity > availableStock
}

// Obtenir le stock disponible pour un produit
const getAvailableStock = (index: number): number => {
  const item = form.items[index]
  if (!item.product_id) return 0
  const product = props.products.find(p => p.id === item.product_id)
  return product ? product.stock_quantity : 0
}

// Obtenir l'unité du produit
const getProductUnit = (index: number): string => {
  const item = form.items[index]
  if (!item.product_id) return ''
  const product = props.products.find(p => p.id === item.product_id)
  return product ? product.unit : ''
}

// Obtenir la quantité maximale autorisée
const getMaxQuantity = (index: number): number => {
  return getAvailableStock(index)
}

// Calculer la largeur adaptative pour les champs de prix
const getPriceFieldWidth = (price: number): string => {
  // Vérifier que price est un nombre valide
  if (!price || price === 0 || typeof price !== 'number' || isNaN(price)) {
    return '80px'
  }
  
  // Formater le prix avec 2 décimales pour avoir une estimation plus précise
  const formattedPrice = price.toFixed(2)
  const charCount = formattedPrice.length
  
  // Largeur de base + espace pour chaque caractère supplémentaire
  const baseWidth = 70 // Largeur minimale légèrement augmentée
  const charWidth = 9 // Largeur approximative par caractère
  const calculatedWidth = baseWidth + (charCount * charWidth)
  
  // Limiter la largeur entre 70px et 130px
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
    const quantity = parseFloat(String(item.quantity)) || 0
    const unitPrice = parseFloat(String(item.unit_price)) || 0
    const itemTotal = quantity * unitPrice
    return total + (isNaN(itemTotal) ? 0 : itemTotal)
  }, 0)
  
  return isNaN(total) ? 0 : total
})

const taxAmount = computed(() => {
  const value = parseFloat(String(form.tax_amount)) || 0
  return isNaN(value) ? 0 : value
})

const discountAmount = computed(() => {
  const value = parseFloat(String(form.discount_amount)) || 0
  return isNaN(value) ? 0 : value
})

const totalAmount = computed(() => {
  const subtotalValue = subtotal.value
  const taxValue = taxAmount.value
  const discountValue = discountAmount.value
  
  const total = subtotalValue + taxValue - discountValue
  
  return isNaN(total) ? 0 : total
})

const remainingAmount = computed(() => {
  return totalAmount.value - (form.down_payment_amount || 0)
})

// Mettre à jour automatiquement le statut de paiement basé sur l'acompte
watch([() => form.down_payment_amount, totalAmount], () => {
  const downPayment = form.down_payment_amount || 0
  const total = totalAmount.value || 0
  
  // Ne pas mettre à jour si le statut est explicitement "paid" (via le bouton "Marquer comme payé")
  // On vérifie si l'acompte a été défini manuellement ou via le bouton
  if (downPayment >= total && total > 0) {
    // Acompte égal ou supérieur au total = payé
    form.payment_status = 'paid'
  } else if (downPayment > 0 && (total - downPayment) > 0) {
    // Acompte partiel = paiement partiel
    form.payment_status = 'partial'
  } else if (downPayment === 0 && total > 0) {
    // Aucun acompte = en attente
    form.payment_status = 'pending'
  }
})

// Validation simple côté client
const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.payment_method) {
    errors.payment_method = 'Le mode de paiement est requis'
  }
  
  if (form.tax_amount < 0) {
    errors.tax_amount = 'Le montant de la taxe ne peut pas être négatif'
  }
  
  if (form.discount_amount < 0) {
    errors.discount_amount = 'Le montant de la remise ne peut pas être négatif'
  }
  
  if (form.down_payment_amount < 0) {
    errors.down_payment_amount = 'L\'acompte ne peut pas être négatif'
  }
  
  if (form.down_payment_amount > totalAmount.value) {
    errors.down_payment_amount = 'L\'acompte ne peut pas dépasser le montant total'
  }
  
  // Validation des items
  form.items.forEach((item, index) => {
    if (!item.product_id) {
      errors[`items.${index}.product_id`] = 'Le produit est requis'
    }
    
    if (!item.quantity || item.quantity < 1) {
      errors[`items.${index}.quantity`] = 'La quantité doit être au moins 1'
    }
    
    if (!item.unit_price || item.unit_price < 0) {
      errors[`items.${index}.unit_price`] = 'Le prix unitaire doit être positif'
    }
    
    if (isQuantityExceedsStock(index)) {
      errors[`items.${index}.quantity`] = 'La quantité dépasse le stock disponible'
    }
  })
  
  return Object.keys(errors).length === 0 ? null : errors
}

// Validation en temps réel pour un champ spécifique
const validateField = (fieldName: string, value: any) => {
  // Effacer l'erreur précédente pour ce champ
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'payment_method':
      if (!value) {
        errorMessage = 'Le mode de paiement est requis'
      }
      break
      
    case 'tax_amount':
      if (value < 0) {
        errorMessage = 'Le montant de la taxe ne peut pas être négatif'
      }
      break
      
    case 'discount_amount':
      if (value < 0) {
        errorMessage = 'Le montant de la remise ne peut pas être négatif'
      }
      break
      
    case 'down_payment_amount':
      if (value < 0) {
        errorMessage = 'L\'acompte ne peut pas être négatif'
      } else if (value > totalAmount.value) {
        errorMessage = 'L\'acompte ne peut pas dépasser le montant total'
      }
      break
  }
  
  // Ajouter l'erreur si elle existe
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const submit = () => {
  // Effacer les erreurs précédentes
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    // Afficher les erreurs dans le formulaire
    clientErrors.value = validationErrors
    return
  }
  
  if (form.items.length === 0) {
    error('Veuillez ajouter au moins un article.')
    return
  }

  // Vérifier s'il y a des quantités qui dépassent le stock
  const hasStockIssues = form.items.some((item, index) => isQuantityExceedsStock(index))
  if (hasStockIssues) {
    error('Veuillez corriger les quantités qui dépassent le stock disponible.')
    return
  }

  form.put(route('sales.update', { id: props.sale.id }), {
    onSuccess: () => {
      success('Vente modifiée avec succès !')
      clientErrors.value = {} // Clear client errors on success
    },
    onError: () => {
      error('Erreur lors de la modification de la vente.')
    }
  })
}

const markAsPaid = async () => {
  const confirmed = await confirmWithDetails(
    'Êtes-vous sûr de vouloir marquer cette vente comme payée ?',
    'Confirmer le paiement',
    'Cela va :<br>• Marquer le statut comme "payé"<br>• Définir l\'acompte égal au montant total<br>• Mettre le reste à payer à 0'
  )
  
  if (confirmed) {
    // Mettre à jour le statut de paiement localement
    form.payment_status = 'paid'
    form.down_payment_amount = totalAmount.value
    
    // Envoyer la requête de mise à jour
    form.put(route('sales.update', { id: props.sale.id }), {
      onSuccess: () => {
        success('Vente marquée comme payée avec succès !')
      },
      onError: () => {
        error('Erreur lors de la mise à jour du statut de paiement.')
        // Restaurer les valeurs précédentes
        form.payment_status = props.sale.payment_status || 'pending'
        form.down_payment_amount = parseFloat(String(props.sale.down_payment_amount)) || 0
      }
    })
  }
}

const markAsUnpaid = async () => {
  const confirmed = await confirmWithDetails(
    'Êtes-vous sûr de vouloir marquer cette vente comme non payée ?',
    'Confirmer le non-paiement',
    'Cela va :<br>• Marquer le statut comme "en attente"<br>• Remettre l\'acompte à 0<br>• Le reste à payer sera égal au montant total'
  )
  
  if (confirmed) {
    // Mettre à jour le statut de paiement localement
    form.payment_status = 'pending'
    form.down_payment_amount = 0
    
    // Envoyer la requête de mise à jour
    form.put(route('sales.update', { id: props.sale.id }), {
      onSuccess: () => {
        success('Vente marquée comme non payée avec succès !')
      },
      onError: () => {
        error('Erreur lors de la mise à jour du statut de paiement.')
        // Restaurer les valeurs précédentes
        form.payment_status = props.sale.payment_status || 'pending'
        form.down_payment_amount = parseFloat(String(props.sale.down_payment_amount)) || 0
      }
    })
  }
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