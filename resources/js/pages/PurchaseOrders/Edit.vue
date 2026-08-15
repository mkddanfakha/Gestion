<template>
  <AppLayout>
    <FormPageLayout wide>
      <FormPageHeader
        title="Modifier le bon de commande"
        :subtitle="purchaseOrder.po_number"
        :back-href="route('purchase-orders.index')"
        back-label="Retour à la liste"
      >
        <template #meta>
          <DraftSaveStatus :status="draft.status" :last-saved-at="draft.lastSavedAt" />
        </template>
        <template #actions>
          <Link
            :href="route('purchase-orders.show', { id: purchaseOrder.id })"
            class="btn btn-outline-primary"
          >
            <i class="bi bi-eye me-1"></i>
            Voir le bon de commande
          </Link>
        </template>
      </FormPageHeader>

      <DraftRestoreDialog
        :visible="draft.showRestoreDialog"
        mode="edit"
        :config="draft.config"
        :draft="draft.pendingDraft"
        @restore="draft.restoreDraft()"
        @dismiss="draft.dismissDraft()"
      />

      <form>
        <div class="form-page__body">
          <div class="row g-3">
            <div class="col-12 col-lg-8 d-flex flex-column gap-3">
              <FormSection title="Informations générales">
                <div class="row g-3">
                  <div class="col-12 col-md-6">
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

                <div class="col-12 col-md-6">
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

                <div class="col-12 col-md-6">
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

                <div class="col-12 col-md-6">
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
              </FormSection>

              <FormSection title="Articles">
                <template #actions>
                  <button
                    type="button"
                    @click="addItem"
                    class="btn btn-primary btn-sm"
                  >
                    <i class="bi bi-plus-circle me-1"></i>
                    Ajouter un article
                  </button>
                </template>

                <div v-if="form.items.length === 0" class="text-center py-4 text-muted">
                <i class="bi bi-cart-x fs-1 mb-3"></i>
                <p>Aucun article ajouté. Cliquez sur "Ajouter un article" pour commencer.</p>
              </div>

              <div v-else>
                <div
                  v-for="(item, index) in form.items"
                  :key="index"
                  class="border rounded p-3 mb-3 form-line-item-card"
                >
                  <div class="row g-3">
                    <div class="col-12 col-md-5">
                      <label class="form-label">Produit <span class="text-danger">*</span></label>
                      <ProductAutocomplete
                        v-model="item.product_id"
                        :products="products"
                        :exclude-product-ids="getExcludedProductIds(index)"
                        :is-invalid="isProductDuplicate(index)"
                        placeholder="Rechercher un produit..."
                        @selected="(product) => handleProductSelected(product, index)"
                      />
                      <div v-if="isProductDuplicate(index)" class="invalid-feedback d-block">
                        Ce produit est déjà sélectionné dans ce bon de commande.
                      </div>
                    </div>

                    <div class="col-12 col-md-2">
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

                    <div class="col-12 col-md-3">
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

                    <div class="col-12 col-md-2">
                      <label class="form-label">Total</label>
                      <input
                        :value="formatCurrency(item.total_price)"
                        type="text"
                        class="form-control"
                        readonly
                      />
                    </div>
                  </div>
                  <div class="form-line-item-card__toolbar">
                    <button
                      type="button"
                      @click="removeItem(index)"
                      class="btn btn-outline-danger w-100"
                    >
                      <i class="bi bi-trash me-1"></i>
                      Supprimer
                    </button>
                  </div>
                </div>
              </div>
              </FormSection>
            </div>

            <div class="col-12 col-lg-4">
              <FormSection title="Résumé">
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
              </FormSection>
            </div>
          </div>

          <FormActionsBar class="form-actions-bar--split">
            <Link
              :href="route('purchase-orders.index')"
              class="btn btn-outline-secondary"
            >
              Annuler
            </Link>
            <div class="d-flex flex-wrap gap-2">
              <button
                v-if="canPreviewPurchaseOrder"
                type="button"
                class="btn btn-outline-primary"
                :disabled="processing || isPreviewing || form.items.length === 0 || !form.supplier_id"
                @click="previewPurchaseOrder"
              >
                <span v-if="isPreviewing" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="bi bi-eye me-1"></i>
                Aperçu
              </button>
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
            </div>
          </FormActionsBar>
        </div>
      </form>
    </FormPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import FormPageLayout from '@/components/page/FormPageLayout.vue'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import FormSection from '@/components/page/FormSection.vue'
import FormActionsBar from '@/components/page/FormActionsBar.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'
import { usePermissions } from '@/composables/usePermissions'
import ProductAutocomplete from '@/components/ProductAutocomplete.vue'
import { formatCurrency } from '@/utils/currencyFormatter'
import { useFormDraft } from '@/composables/useFormDraft'
import DraftSaveStatus from '@/components/drafts/DraftSaveStatus.vue'
import DraftRestoreDialog from '@/components/drafts/DraftRestoreDialog.vue'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'

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
const { openFromPayload } = useDocumentPdfPreview()
const { canAny } = usePermissions()
const canPreviewPurchaseOrder = canAny('purchase-orders', ['print', 'create', 'update'])
const isPreviewing = ref(false)

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

const poEditBaseline = { ...form.data() } as Record<string, unknown>

const draft = useFormDraft({
  formType: 'purchase_order',
  mode: 'edit',
  entityId: props.purchaseOrder.id,
  watchSource: form,
  getData: () => form.data() as Record<string, unknown>,
  restoreData: (data) => restoreInertiaFormData(form as unknown as Record<string, unknown>, data),
  getBaseline: () => poEditBaseline,
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
  validateProductSelection(index)
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

const buildPurchaseOrderPreviewPayload = () => {
  form.subtotal = subtotal.value
  form.total_amount = totalAmount.value

  return {
    purchase_order_id: props.purchaseOrder.id,
    supplier_id: form.supplier_id,
    order_date: form.order_date,
    expected_delivery_date: form.expected_delivery_date || null,
    status: form.status,
    subtotal: form.subtotal,
    tax_amount: form.tax_amount || 0,
    discount_amount: form.discount_amount || 0,
    total_amount: form.total_amount,
    notes: form.notes || null,
    items: form.items.map((item) => ({
      product_id: item.product_id,
      quantity: item.quantity,
      unit_price: item.unit_price,
      total_price: item.total_price,
    })),
  }
}

const previewPurchaseOrder = async () => {
  if (form.items.length === 0 || !form.supplier_id) {
    error('Veuillez compléter le formulaire avant l\'aperçu.')
    return
  }

  isPreviewing.value = true
  try {
    await openFromPayload(
      'purchase-orders.preview',
      buildPurchaseOrderPreviewPayload(),
      `BC_${props.purchaseOrder.po_number}.pdf`,
    )
  } finally {
    isPreviewing.value = false
  }
}

const submit = () => {
  form.subtotal = subtotal.value
  form.total_amount = totalAmount.value

  form.put(route('purchase-orders.update', { id: props.purchaseOrder.id }), {
    onSuccess: async () => {
      await draft.markSubmitted()
      success('Bon de commande modifié avec succès !')
    },
    onError: () => {
      error('Erreur lors de la modification du bon de commande.')
    }
  })
}

const { errors, processing } = form
</script>


