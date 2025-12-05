<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouveau bon de livraison</h1>
        <p class="text-muted mb-0">Enregistrez une nouvelle livraison fournisseur</p>
      </div>
      <Link
        :href="route('delivery-notes.index')"
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
                    @change="watchSupplierChange"
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
                    Bon de commande <span class="text-danger">*</span>
                  </label>
                  <div class="position-relative">
                    <input
                      type="text"
                      v-model="poSearchQuery"
                      @input="handlePOSearch"
                      @focus="showPOSuggestions = true"
                      placeholder="Rechercher un bon de commande..."
                      class="form-control"
                      :class="{ 'is-invalid': errors.purchase_order_id || clientErrors.purchase_order_id }"
                    />
                    
                    <!-- Suggestions -->
                    <div
                      v-if="showPOSuggestions && filteredPurchaseOrders.length > 0"
                      class="dropdown-menu show w-100"
                      style="position: absolute; top: 100%; z-index: 1000;"
                    >
                      <a
                        v-for="po in filteredPurchaseOrders"
                        :key="po.id"
                        @click="selectPurchaseOrder(po)"
                        class="dropdown-item"
                      >
                        {{ po.po_number }}
                      </a>
                    </div>
                    
                    <!-- Message si aucun résultat -->
                    <div
                      v-if="poSearchQuery && filteredPurchaseOrders.length === 0 && props.purchaseOrders.length > 0"
                      class="dropdown-menu show w-100"
                      style="position: absolute; top: 100%; z-index: 1000;"
                    >
                      <div class="dropdown-item text-muted">
                        Aucun bon de commande trouvé
                      </div>
                    </div>
                    
                    <!-- Message si aucun fournisseur sélectionné -->
                    <div
                      v-if="poSearchQuery && !form.supplier_id"
                      class="dropdown-menu show w-100"
                      style="position: absolute; top: 100%; z-index: 1000;"
                    >
                      <div class="dropdown-item text-muted">
                        Veuillez d'abord sélectionner un fournisseur
                      </div>
                    </div>
                  </div>
                  
                  <!-- Messages d'erreur -->
                  <div v-if="errors.purchase_order_id || clientErrors.purchase_order_id" class="mt-1">
                    <div v-if="errors.purchase_order_id" class="text-danger small">{{ errors.purchase_order_id }}</div>
                    <div v-if="clientErrors.purchase_order_id" class="text-danger small">{{ clientErrors.purchase_order_id }}</div>
                  </div>
                  
                  <!-- Afficher le BC sélectionné -->
                  <div v-if="selectedPurchaseOrder" class="mt-2">
                    <span class="badge bg-primary">
                      <i class="bi bi-check-circle me-1"></i>
                      {{ selectedPurchaseOrder.po_number }}
                    </span>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Date de livraison <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.delivery_date"
                    type="date"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.delivery_date || clientErrors.delivery_date }"
                  />
                  <div v-if="errors.delivery_date" class="invalid-feedback">{{ errors.delivery_date }}</div>
                  <div v-if="clientErrors.delivery_date" class="invalid-feedback">{{ clientErrors.delivery_date }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Numéro de facture fournisseur</label>
                  <input
                    v-model="form.invoice_number"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.invoice_number }"
                    placeholder="EX: FACT-2025-001"
                  />
                  <div v-if="errors.invoice_number" class="invalid-feedback">{{ errors.invoice_number }}</div>
                </div>

                <div class="col-12">
                  <label class="form-label">Notes</label>
                  <textarea
                    v-model="form.notes"
                    rows="3"
                    class="form-control"
                    placeholder="Ajoutez des notes sur cette livraison..."
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
              <h5 class="card-title mb-0">Articles livrés</h5>
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
                        :is-invalid="isProductDuplicate(index) || !!clientErrors[`items.${index}.product_id`]"
                        placeholder="Rechercher un produit..."
                        @selected="(product) => handleProductSelected(product, index)"
                      />
                      <div v-if="clientErrors[`items.${index}.product_id`]" class="invalid-feedback d-block">
                        {{ clientErrors[`items.${index}.product_id`] }}
                      </div>
                      <div v-if="isProductDuplicate(index)" class="invalid-feedback d-block">
                        Ce produit est déjà sélectionné dans ce bon de livraison.
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
                      <label class="form-label">Prix d'achat unitaire <span class="text-danger">*</span></label>
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

              <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                Le stock sera ajusté automatiquement après la validation du bon de livraison.
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
                  {{ processing ? 'Création...' : 'Créer le bon de livraison' }}
                </button>
                <Link
                  :href="route('delivery-notes.index')"
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
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

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

interface PurchaseOrder {
  id: number
  po_number: string
  supplier_id: number
}

interface DNItem {
  product_id: number
  quantity: number
  unit_price: number
  total_price: number
}

interface Props {
  suppliers: Supplier[]
  products: Product[]
  purchaseOrders: PurchaseOrder[]
  purchaseOrder?: any
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

// Variables pour la recherche de bon de commande
const poSearchQuery = ref('')
const showPOSuggestions = ref(false)
const selectedPurchaseOrder = ref<PurchaseOrder | null>(null)

const form = useForm({
  supplier_id: props.purchaseOrder?.supplier_id || '',
  purchase_order_id: props.purchaseOrder?.id || '',
  delivery_date: new Date().toISOString().split('T')[0],
  status: 'pending',
  notes: '',
  invoice_number: '',
  tax_amount: 0,
  discount_amount: 0,
  subtotal: 0,
  total_amount: 0,
  items: props.purchaseOrder?.items?.map((item: any) => ({
    product_id: item.product_id || 0,
    quantity: Number(item.quantity) || 1,
    unit_price: Number(item.unit_price) || 0,
    total_price: Number(item.quantity || 1) * Number(item.unit_price || 0)
  })) || [] as DNItem[]
})

// Filtrer les bons de commande par fournisseur et par recherche
const filteredPurchaseOrders = computed(() => {
  if (!form.supplier_id) {
    return []
  }
  
  let filtered = props.purchaseOrders.filter(po => po.supplier_id === Number(form.supplier_id))
  
  // Recherche par numéro de BC
  if (poSearchQuery.value) {
    const query = poSearchQuery.value.toLowerCase()
    filtered = filtered.filter(po => 
      po.po_number.toLowerCase().includes(query)
    )
  }
  
  return filtered
})

const handlePOSearch = () => {
  showPOSuggestions.value = true
}

const selectPurchaseOrder = (po: PurchaseOrder) => {
  selectedPurchaseOrder.value = po
  form.purchase_order_id = po.id
  poSearchQuery.value = po.po_number
  showPOSuggestions.value = false
}

// Réinitialiser le bon de commande quand le fournisseur change
const watchSupplierChange = () => {
  form.purchase_order_id = ''
  form.items = []
  poSearchQuery.value = ''
  selectedPurchaseOrder.value = null
  showPOSuggestions.value = false
}

// Watcher pour surveiller les changements de fournisseur
watch(() => form.supplier_id, () => {
  if (form.supplier_id) {
    form.purchase_order_id = ''
    form.items = []
    poSearchQuery.value = ''
    selectedPurchaseOrder.value = null
    showPOSuggestions.value = false
  }
})

// Watcher pour recalculer les totaux quand les items changent
watch(() => form.items.map(item => ({ 
  quantity: item.quantity, 
  unit_price: item.unit_price, 
  total_price: item.total_price 
})), () => {
  // Recalculer tous les total_price des items avant de calculer le subtotal
  form.items.forEach((item, index) => {
    const quantity = Number(item.quantity) || 0
    const unitPrice = Number(item.unit_price) || 0
    item.total_price = quantity * unitPrice
  })
  updateTotals()
}, { deep: true })

// Watcher pour recalculer le total quand taxes ou remise changent
watch([() => form.tax_amount, () => form.discount_amount], () => {
  updateTotal()
})

const addItem = () => {
  form.items.push({
    product_id: 0,
    quantity: 1,
    unit_price: 0,
    total_price: 0
  })
  // Les totaux seront recalculés automatiquement quand l'utilisateur remplira les champs
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

const updateItemTotal = (index: number) => {
  const item = form.items[index]
  if (!item) return
  
  const quantity = Number(item.quantity) || 0
  const unitPrice = Number(item.unit_price) || 0
  item.total_price = quantity * unitPrice
  
  // Forcer la mise à jour en recalculant les totaux
  updateTotals()
}

const updateTotals = () => {
  const calculatedSubtotal = form.items.reduce((sum, item) => {
    const totalPrice = Number(item.total_price) || 0
    return sum + totalPrice
  }, 0)
  
  form.subtotal = calculatedSubtotal
  updateTotal()
}

const updateTotal = () => {
  const subtotalValue = Number(form.subtotal) || 0
  const taxAmount = Number(form.tax_amount) || 0
  const discountAmount = Number(form.discount_amount) || 0
  form.total_amount = subtotalValue + taxAmount - discountAmount
}

const subtotal = computed(() => {
  return form.items.reduce((sum, item) => {
    const totalPrice = Number(item.total_price) || 0
    return sum + totalPrice
  }, 0)
})

const totalAmount = computed(() => {
  const subtotalValue = subtotal.value
  const taxAmount = Number(form.tax_amount) || 0
  const discountAmount = Number(form.discount_amount) || 0
  return subtotalValue + taxAmount - discountAmount
})

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
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
  
  // Recalculer tous les total_price des items avant de soumettre
  form.items.forEach((item, index) => {
    const quantity = Number(item.quantity) || 0
    const unitPrice = Number(item.unit_price) || 0
    item.total_price = quantity * unitPrice
  })
  
  // Recalculer les totaux finaux
  updateTotals()
  
  form.post(route('delivery-notes.store'), {
    onSuccess: () => {
      success('Bon de livraison créé avec succès !')
      clientErrors.value = {}
    },
    onError: () => {
      error('Erreur lors de la création du bon de livraison.')
    }
  })
}

// Validation côté client
const clientErrors = ref<Record<string, string>>({})

const { errors, processing } = form

// Vérifier si un produit est déjà sélectionné dans d'autres items
const isProductAlreadySelected = (productId: number, currentIndex: number): boolean => {
  if (!productId || productId === 0) return false
  return form.items.some((item, index) => 
    index !== currentIndex && item.product_id === productId
  )
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
}

const validateForm = (): Record<string, string> | null => {
  const errors: Record<string, string> = {}
  
  if (!form.supplier_id) {
    errors.supplier_id = 'Le fournisseur est requis'
  }
  
  if (!form.purchase_order_id) {
    errors.purchase_order_id = 'Le bon de commande est requis'
  }
  
  if (!form.delivery_date) {
    errors.delivery_date = 'La date de livraison est requise'
  }
  
  if (form.items.length === 0) {
    errors.items = 'Au moins un article est requis'
  }
  
  // Valider les items
  form.items.forEach((item, index) => {
    if (!item.product_id || item.product_id === 0) {
      errors[`items.${index}.product_id`] = 'Le produit est requis'
    } else if (isProductDuplicate(index)) {
      errors[`items.${index}.product_id`] = 'Ce produit est déjà sélectionné'
    }
    
    if (!item.quantity || item.quantity < 1) {
      errors[`items.${index}.quantity`] = 'La quantité doit être supérieure à 0'
    }
    
    if (!item.unit_price || item.unit_price < 0) {
      errors[`items.${index}.unit_price`] = 'Le prix unitaire est requis et doit être positif'
    }
  })
  
  return Object.keys(errors).length > 0 ? errors : null
}

const validateField = (fieldName: string, value: any) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  // Logique de validation par champ si nécessaire
}

const validateItemField = (index: number, fieldName: string, value: any) => {
  const key = `items.${index}.${fieldName}`
  if (clientErrors.value[key]) {
    delete clientErrors.value[key]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'product_id':
      if (!value || value === 0) {
        errorMessage = 'Le produit est requis'
      } else if (isProductDuplicate(index)) {
        errorMessage = 'Ce produit est déjà sélectionné'
      }
      break
      
    case 'quantity':
      if (!value || value < 1) {
        errorMessage = 'La quantité doit être supérieure à 0'
      }
      break
      
    case 'unit_price':
      if (!value || value < 0) {
        errorMessage = 'Le prix unitaire est requis et doit être positif'
      }
      break
  }
  
  if (errorMessage) {
    clientErrors.value[key] = errorMessage
  }
}

// Initialiser le bon de commande sélectionné si fourni
onMounted(() => {
  if (props.purchaseOrder) {
    selectedPurchaseOrder.value = props.purchaseOrder
    poSearchQuery.value = props.purchaseOrder.po_number
  }
  
  // Initialiser les totaux si des items sont déjà présents
  if (form.items.length > 0) {
    updateTotals()
  }
  
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  if (!target.closest('.position-relative')) {
    showPOSuggestions.value = false
  }
}
</script>

<style scoped>
.dropdown-menu.show {
  max-height: 200px;
  overflow-y: auto;
  position: absolute;
  width: 100%;
  margin-top: 0.25rem;
  padding: 0.5rem 0;
}

.dropdown-item {
  cursor: pointer;
  padding: 0.5rem 1rem;
  color: #212529;
  background-color: white;
  transition: background-color 0.15s ease-in-out;
}

.dropdown-item:hover {
  background-color: #e9ecef;
  color: #212529;
}

.form-control {
  z-index: 1;
}

/* Dark mode support */
:deep(.dark) .dropdown-menu {
  background-color: #1e293b !important;
  border-color: #334155 !important;
}

:deep(.dark) .dropdown-item {
  color: #e2e8f0 !important;
  background-color: #1e293b !important;
}

:deep(.dark) .dropdown-item:hover {
  background-color: #334155 !important;
  color: white !important;
}

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

