<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouveau bon de commande</h1>
        <p class="text-muted mb-0">Créez un nouveau bon de commande</p>
      </div>
      <Link
        :href="route('purchase-orders.index')"
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
                  <label class="form-label">
                    Fournisseur <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.supplier_id"
                    required
                    class="form-select"
                    :class="{ 'is-invalid': errors.supplier_id || clientErrors.supplier_id }"
                    @blur="validateField('supplier_id', form.supplier_id)"
                    @change="validateField('supplier_id', form.supplier_id)"
                  >
                    <option value="">Sélectionner un fournisseur</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                      {{ supplier.name }}
                    </option>
                  </select>
                  <div v-if="errors.supplier_id" class="invalid-feedback">{{ errors.supplier_id }}</div>
                  <div v-if="clientErrors.supplier_id" class="invalid-feedback">{{ clientErrors.supplier_id }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Statut <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.status"
                    required
                    class="form-select"
                    :class="{ 'is-invalid': errors.status || clientErrors.status }"
                    @blur="validateField('status', form.status)"
                    @change="validateField('status', form.status)"
                  >
                    <option value="draft">Brouillon</option>
                    <option value="sent">Envoyé</option>
                    <option value="confirmed">Confirmé</option>
                    <option value="partially_received">Partiellement reçu</option>
                    <option value="received">Reçu</option>
                    <option value="cancelled">Annulé</option>
                  </select>
                  <div v-if="errors.status" class="invalid-feedback">{{ errors.status }}</div>
                  <div v-if="clientErrors.status" class="invalid-feedback">{{ clientErrors.status }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Date de commande <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.order_date"
                    type="date"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.order_date || clientErrors.order_date }"
                    @blur="validateField('order_date', form.order_date)"
                    @input="validateField('order_date', form.order_date)"
                  />
                  <div v-if="errors.order_date" class="invalid-feedback">{{ errors.order_date }}</div>
                  <div v-if="clientErrors.order_date" class="invalid-feedback">{{ clientErrors.order_date }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Date de livraison prévue</label>
                  <input
                    v-model="form.expected_delivery_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': errors.expected_delivery_date || clientErrors.expected_delivery_date }"
                    @blur="validateField('expected_delivery_date', form.expected_delivery_date)"
                    @input="validateField('expected_delivery_date', form.expected_delivery_date)"
                  />
                  <div v-if="errors.expected_delivery_date" class="invalid-feedback">{{ errors.expected_delivery_date }}</div>
                  <div v-if="clientErrors.expected_delivery_date" class="invalid-feedback">{{ clientErrors.expected_delivery_date }}</div>
                </div>

                <div class="col-12">
                  <label class="form-label">Notes</label>
                  <textarea
                    v-model="form.notes"
                    rows="3"
                    class="form-control"
                    placeholder="Ajoutez des notes sur ce bon de commande..."
                    :class="{ 'is-invalid': errors.notes }"
                  ></textarea>
                  <div v-if="errors.notes" class="invalid-feedback">{{ errors.notes }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Articles -->
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">Articles</h5>
              <button
                type="button"
                @click="addItem"
                class="btn btn-primary btn-sm"
              >
                <i class="bi bi-plus-circle me-1"></i>
                Ajouter un article
              </button>
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
                      <label class="form-label">Produit <span class="text-danger">*</span></label>
                      <ProductAutocomplete
                        v-model="item.product_id"
                        :products="products"
                        :exclude-product-ids="getExcludedProductIds(index)"
                        :is-invalid="!!clientErrors[`items.${index}.product_id`] || isProductDuplicate(index)"
                        placeholder="Rechercher un produit..."
                        @selected="(product) => handleProductSelected(product, index)"
                      />
                      <div v-if="clientErrors[`items.${index}.product_id`]" class="invalid-feedback d-block">
                        {{ clientErrors[`items.${index}.product_id`] }}
                      </div>
                      <div v-if="isProductDuplicate(index)" class="invalid-feedback d-block">
                        Ce produit est déjà sélectionné dans ce bon de commande.
                      </div>
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">Quantité <span class="text-danger">*</span></label>
                      <input
                        v-model.number="item.quantity"
                        type="number"
                        min="1"
                        required
                        class="form-control"
                        :class="{ 'is-invalid': clientErrors[`items.${index}.quantity`] }"
                        @input="updateItemTotal(index); validateItemField(index, 'quantity', item.quantity)"
                        @blur="validateItemField(index, 'quantity', item.quantity)"
                      />
                      <div v-if="clientErrors[`items.${index}.quantity`]" class="invalid-feedback">
                        {{ clientErrors[`items.${index}.quantity`] }}
                      </div>
                    </div>

                    <div class="col-md-3">
                      <label class="form-label">Prix unitaire <span class="text-danger">*</span></label>
                      <input
                        v-model.number="item.unit_price"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        class="form-control"
                        :class="{ 'is-invalid': clientErrors[`items.${index}.unit_price`] }"
                        @input="updateItemTotal(index); validateItemField(index, 'unit_price', item.unit_price)"
                        @blur="validateItemField(index, 'unit_price', item.unit_price)"
                      />
                      <div v-if="clientErrors[`items.${index}.unit_price`]" class="invalid-feedback">
                        {{ clientErrors[`items.${index}.unit_price`] }}
                      </div>
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">Total</label>
                      <input
                        :value="formatCurrency(item.total_price)"
                        type="text"
                        class="form-control"
                        readonly
                      />
                    </div>

                    <div class="col-12 text-end">
                      <button
                        type="button"
                        @click="removeItem(index)"
                        class="btn btn-sm btn-outline-danger"
                      >
                        <i class="bi bi-trash me-1"></i>
                        Supprimer
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
          <div class="card sticky-top" style="top: 20px;">
            <div class="card-header">
              <h5 class="card-title mb-0">Résumé</h5>
            </div>
            <div class="card-body">
              <div class="mb-3">
                <div class="d-flex justify-content-between mb-2">
                  <span>Articles:</span>
                  <span class="fw-medium">{{ form.items.length }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span>Sous-total:</span>
                  <span class="fw-medium">{{ formatCurrency(subtotal) }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span>Taxes:</span>
                  <input
                    v-model.number="form.tax_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    class="form-control form-control-sm"
                    style="width: 120px;"
                    @input="updateTotal"
                  />
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span>Remise:</span>
                  <input
                    v-model.number="form.discount_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    class="form-control form-control-sm"
                    style="width: 120px;"
                    @input="updateTotal"
                  />
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                  <span class="fw-bold">Total:</span>
                  <span class="fw-bold text-success fs-5">{{ formatCurrency(totalAmount) }}</span>
                </div>
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
                  {{ processing ? 'Création...' : 'Créer le bon de commande' }}
                </button>
                <Link
                  :href="route('purchase-orders.index')"
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

const { success, error } = useSweetAlert()

const clientErrors = ref<Record<string, string>>({})

const validateForm = () => {
  const errors: Record<string, string> = {}

  if (!form.supplier_id) {
    errors.supplier_id = 'Le fournisseur est requis.'
  }
  
  if (!form.order_date) {
    errors.order_date = 'La date de commande est requise.'
  }
  
  if (form.expected_delivery_date && form.order_date && new Date(form.expected_delivery_date) < new Date(form.order_date)) {
    errors.expected_delivery_date = 'La date de livraison ne peut pas être antérieure à la date de commande.'
  }
  
  if (!form.status) {
    errors.status = 'Le statut est requis.'
  }
  
  if (form.items.length === 0) {
    errors.items = 'Au moins un article est requis.'
  } else {
    form.items.forEach((item, index) => {
      if (!item.product_id || item.product_id === 0) {
        errors[`items.${index}.product_id`] = 'Le produit est requis.'
      } else if (isProductAlreadySelected(item.product_id, index)) {
        errors[`items.${index}.product_id`] = 'Ce produit est déjà sélectionné.'
      }
      
      if (item.quantity <= 0) {
        errors[`items.${index}.quantity`] = 'La quantité doit être supérieure à 0.'
      }
      
      if (item.unit_price <= 0) {
        errors[`items.${index}.unit_price`] = 'Le prix unitaire doit être supérieur à 0.'
      }
    })
  }

  return errors
}

const validateField = (fieldName: string, value: any) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'supplier_id':
      if (!value) errorMessage = 'Le fournisseur est requis.'
      break
    case 'order_date':
      if (!value) errorMessage = 'La date de commande est requise.'
      break
    case 'expected_delivery_date':
      if (value && form.order_date && new Date(value) < new Date(form.order_date)) {
        errorMessage = 'La date de livraison ne peut pas être antérieure à la date de commande.'
      }
      break
    case 'status':
      if (!value) errorMessage = 'Le statut est requis.'
      break
  }
  
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const validateItemField = (itemIndex: number, fieldName: string, value: any) => {
  const errorKey = `items.${itemIndex}.${fieldName}`
  
  if (clientErrors.value[errorKey]) {
    delete clientErrors.value[errorKey]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'product_id':
      if (!value || value === 0) {
        errorMessage = 'Le produit est requis.'
      } else if (isProductAlreadySelected(value, itemIndex)) {
        errorMessage = 'Ce produit est déjà sélectionné.'
      }
      break
    case 'quantity':
      if (value <= 0) {
        errorMessage = 'La quantité doit être supérieure à 0.'
      }
      break
    case 'unit_price':
      if (value <= 0) {
        errorMessage = 'Le prix unitaire doit être supérieur à 0.'
      }
      break
  }
  
  if (errorMessage) {
    clientErrors.value[errorKey] = errorMessage
  }
}

interface Supplier {
  id: number
  name: string
}

interface Product {
  id: number
  name: string
  cost_price?: number
  price: number
}

interface POItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

interface Props {
  suppliers: Supplier[]
  products: Product[]
}

const props = defineProps<Props>()

const form = useForm({
  supplier_id: '',
  order_date: new Date().toISOString().split('T')[0],
  expected_delivery_date: '',
  status: 'draft',
  notes: '',
  tax_amount: 0,
  discount_amount: 0,
  subtotal: 0,
  total_amount: 0,
  items: [] as POItem[]
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
  updateTotals()
}

const updateItemPrice = (index: number) => {
  const item = form.items[index]
  const product = props.products.find(p => p.id === item.product_id)
  if (product) {
    item.unit_price = product.cost_price || product.price
    updateItemTotal(index)
  }
}

const isProductAlreadySelected = (productId: number, currentIndex: number) => {
  return form.items.some((item, idx) => item.product_id === productId && idx !== currentIndex)
}

// Vérifier si l'item actuel est en doublon
const isProductDuplicate = (index: number): boolean => {
  const currentItem = form.items[index]
  if (!currentItem.product_id || currentItem.product_id === 0) return false
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
  item.unit_price = product.cost_price || product.price
  updateItemTotal(index)
  validateItemField(index, 'product_id', item.product_id)
}

const updateItemTotal = (index: number) => {
  const item = form.items[index]
  item.total_price = item.quantity * item.unit_price
  updateTotals()
}

const updateTotals = () => {
  form.subtotal = form.items.reduce((sum, item) => sum + item.total_price, 0)
  updateTotal()
}

const updateTotal = () => {
  form.total_amount = form.subtotal + form.tax_amount - form.discount_amount
}

const subtotal = computed(() => {
  return form.items.reduce((sum, item) => sum + item.total_price, 0)
})

const totalAmount = computed(() => {
  return subtotal.value + form.tax_amount - form.discount_amount
})

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const submit = () => {
  // Valider le formulaire avant soumission
  clientErrors.value = {}
  const validationErrors = validateForm()
  
  if (validationErrors && Object.keys(validationErrors).length > 0) {
    clientErrors.value = validationErrors
    if (validationErrors.items) {
      error('Au moins un article est requis pour créer un bon de commande.')
    } else {
      error('Veuillez corriger les erreurs dans le formulaire.')
    }
    return
  }

  form.subtotal = subtotal.value
  form.total_amount = totalAmount.value

  form.post(route('purchase-orders.store'), {
    onSuccess: () => {
      success('Bon de commande créé avec succès !')
    },
    onError: () => {
      error('Erreur lors de la création du bon de commande.')
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

