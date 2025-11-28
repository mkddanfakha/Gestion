<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier le bon de commande</h1>
        <p class="text-muted mb-0">{{ purchaseOrder.po_number }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('purchase-orders.show', { id: purchaseOrder.id })"
          class="btn btn-outline-primary"
        >
          <i class="bi bi-eye me-1"></i>
          Voir le bon de commande
        </Link>
        <Link
          :href="route('purchase-orders.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
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
                    :class="{ 'is-invalid': errors.supplier_id }"
                  >
                    <option value="">Sélectionner un fournisseur</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                      {{ supplier.name }}
                    </option>
                  </select>
                  <div v-if="errors.supplier_id" class="invalid-feedback">{{ errors.supplier_id }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Statut <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.status"
                    required
                    class="form-select"
                    :class="{ 'is-invalid': errors.status }"
                  >
                    <option value="draft">Brouillon</option>
                    <option value="sent">Envoyé</option>
                    <option value="confirmed">Confirmé</option>
                    <option value="partially_received">Partiellement reçu</option>
                    <option value="received">Reçu</option>
                    <option value="cancelled">Annulé</option>
                  </select>
                  <div v-if="errors.status" class="invalid-feedback">{{ errors.status }}</div>
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
                    :class="{ 'is-invalid': errors.order_date }"
                  />
                  <div v-if="errors.order_date" class="invalid-feedback">{{ errors.order_date }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Date de livraison prévue</label>
                  <input
                    v-model="form.expected_delivery_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': errors.expected_delivery_date }"
                  />
                  <div v-if="errors.expected_delivery_date" class="invalid-feedback">{{ errors.expected_delivery_date }}</div>
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
                      <select
                        v-model="item.product_id"
                        required
                        class="form-select"
                        @change="updateItemPrice(index); validateProductSelection(index)"
                        @blur="validateProductSelection(index)"
                      >
                        <option value="0">Sélectionner un produit</option>
                        <option 
                          v-for="product in products" 
                          :key="product.id" 
                          :value="product.id"
                          :disabled="isProductAlreadySelected(product.id, index)"
                        >
                          {{ product.name }}
                          <span v-if="isProductAlreadySelected(product.id, index)"> - Déjà sélectionné</span>
                        </option>
                      </select>
                    </div>

                    <div class="col-md-2">
                      <label class="form-label">Quantité <span class="text-danger">*</span></label>
                      <input
                        v-model.number="item.quantity"
                        type="number"
                        min="1"
                        required
                        class="form-control"
                        @input="updateItemTotal(index)"
                      />
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
                        @input="updateItemTotal(index)"
                      />
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
                  {{ processing ? 'Modification...' : 'Modifier le bon de commande' }}
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

interface PurchaseOrder {
  id: number
  po_number: string
  supplier_id: number
  order_date: string
  expected_delivery_date?: string
  status: string
  notes?: string
  tax_amount: number
  discount_amount: number
  subtotal: number
  total_amount: number
  items: POItem[]
}

interface Props {
  purchaseOrder: PurchaseOrder
  suppliers: Supplier[]
  products: Product[]
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

// Fonction pour convertir une date au format YYYY-MM-DD
const formatDateForInput = (date: string | Date | null | undefined): string => {
  if (!date) return ''
  const d = new Date(date)
  if (isNaN(d.getTime())) return ''
  return d.toISOString().split('T')[0]
}

const form = useForm({
  supplier_id: props.purchaseOrder.supplier_id,
  order_date: formatDateForInput(props.purchaseOrder.order_date),
  expected_delivery_date: formatDateForInput(props.purchaseOrder.expected_delivery_date),
  status: props.purchaseOrder.status,
  notes: props.purchaseOrder.notes || '',
  tax_amount: Number(props.purchaseOrder.tax_amount) || 0,
  discount_amount: Number(props.purchaseOrder.discount_amount) || 0,
  subtotal: Number(props.purchaseOrder.subtotal) || 0,
  total_amount: Number(props.purchaseOrder.total_amount) || 0,
  items: (props.purchaseOrder.items || []).map(item => ({
    product_id: item.product_id,
    quantity: Number(item.quantity) || 1,
    unit_price: Number(item.unit_price) || 0,
    total_price: Number(item.total_price) || 0
  }))
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

const validateProductSelection = (index: number) => {
  const item = form.items[index]
  
  // Si un produit dupliqué est sélectionné, réinitialiser
  if (item.product_id && isProductAlreadySelected(item.product_id, index)) {
    // Réinitialiser le produit et le prix
    item.product_id = 0
    item.unit_price = 0
    item.total_price = 0
    updateTotals()
    
    // Afficher un message d'erreur
    error('Ce produit est déjà sélectionné dans un autre article.')
  }
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
  form.subtotal = subtotal.value
  form.total_amount = totalAmount.value

  form.put(route('purchase-orders.update', { id: props.purchaseOrder.id }), {
    onSuccess: () => {
      success('Bon de commande modifié avec succès !')
    },
    onError: () => {
      error('Erreur lors de la modification du bon de commande.')
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

