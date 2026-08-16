<template>
  <div class="form-page form-page--sticky-actions form-page--wide sale-form-root">
    <FormPageHeader
      :title="pageTitle"
      :subtitle="pageSubtitle"
      :back-href="route('quotes.index')"
      back-label="Devis"
    >
      <template #meta>
        <DraftSaveStatus :status="draft.status" :last-saved-at="draft.lastSavedAt" />
      </template>
    </FormPageHeader>

    <DraftRestoreDialog
      :visible="draft.showRestoreDialog"
      :mode="mode"
      :config="draft.config"
      :draft="draft.pendingDraft"
      @restore="draft.restoreDraft()"
      @dismiss="draft.dismissDraft()"
    />

    <form novalidate @submit.prevent="submit">
      <div class="form-page__body">
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="sale-stat-card h-100">
              <div class="sale-stat-card__icon">
                <i class="bi bi-bag"></i>
              </div>
              <div>
                <div class="sale-stat-card__value">{{ itemsCount }}</div>
                <div class="sale-stat-card__label">article{{ itemsCount > 1 ? 's' : '' }}</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="sale-stat-card h-100">
              <div class="sale-stat-card__icon">
                <i class="bi bi-tag"></i>
              </div>
              <div>
                <div class="sale-stat-card__value sale-stat-card__value--sm">{{ formatCurrency(subtotal) }}</div>
                <div class="sale-stat-card__label">Sous-total</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="sale-stat-card sale-stat-card--accent h-100">
              <div class="sale-stat-card__icon">
                <i class="bi bi-percent"></i>
              </div>
              <div>
                <div class="sale-stat-card__value sale-stat-card__value--sm">
                  {{ discountAmount > 0 ? `- ${formatCurrency(discountAmount)}` : formatCurrency(0) }}
                </div>
                <div class="sale-stat-card__label">Remise</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="sale-stat-card sale-stat-card--total h-100">
              <div class="sale-stat-card__icon">
                <i class="bi bi-wallet2"></i>
              </div>
              <div>
                <div class="sale-stat-card__value sale-stat-card__value--total">{{ formatCurrency(totalAmount) }}</div>
                <div class="sale-stat-card__label">TOTAL</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card sale-card mb-4">
          <div class="card-body p-0">
            <div v-if="form.items.length === 0" class="text-center py-5 text-muted">
              <i class="bi bi-cart-x fs-1 mb-3 d-block"></i>
              <p class="mb-0">Aucun article ajouté. Cliquez sur « Ajouter un article » pour commencer.</p>
            </div>

            <div v-else>
              <div class="d-md-none sale-items-mobile">
                <div
                  v-for="(item, index) in form.items"
                  :key="`mobile-item-${index}`"
                  class="sale-item-card"
                >
                  <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="sale-product-thumb flex-shrink-0">
                      <img
                        v-if="getProduct(item.product_id)?.image_url"
                        :src="getProduct(item.product_id)!.image_url!"
                        :alt="getProduct(item.product_id)?.name ?? 'Produit'"
                      />
                      <i v-else class="bi bi-box-seam"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                      <label class="form-label small mb-1">Produit</label>
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
                    <button
                      type="button"
                      class="btn btn-link text-danger p-0 flex-shrink-0"
                      title="Supprimer"
                      @click="removeItem(index)"
                    >
                      <i class="bi bi-trash fs-5"></i>
                    </button>
                  </div>

                  <div class="row g-3 align-items-end">
                    <div class="col-6">
                      <label class="form-label small mb-1">Quantité</label>
                      <div class="sale-qty-stepper w-100">
                        <button
                          type="button"
                          class="sale-qty-stepper__btn"
                          title="Diminuer"
                          :disabled="item.quantity <= 1"
                          @click="decrementQuantity(index)"
                        >
                          <i class="bi bi-dash"></i>
                        </button>
                        <span class="sale-qty-stepper__value">{{ item.quantity }}</span>
                        <button
                          type="button"
                          class="sale-qty-stepper__btn"
                          title="Augmenter"
                          @click="incrementQuantity(index)"
                        >
                          <i class="bi bi-plus"></i>
                        </button>
                      </div>
                      <div v-if="item.product_id && getProductUnit(index)" class="form-text">
                        Unité : {{ getProductUnit(index) }}
                      </div>
                    </div>
                    <div class="col-6">
                      <label class="form-label small mb-1">Prix unit.</label>
                      <div class="input-group input-group-sm">
                        <input
                          v-model.number="item.unit_price"
                          type="number"
                          step="0.01"
                          min="0"
                          class="form-control"
                          @input="updateItemTotal(index)"
                        />
                        <span class="input-group-text">FCFA</span>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="d-flex justify-content-between align-items-center sale-item-card__total">
                        <span class="text-muted small">Total ligne</span>
                        <span class="fw-semibold">{{ formatCurrency(item.total_price || 0) }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="table-responsive d-none d-md-block">
                <table class="table sale-items-table mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>Produit</th>
                      <th style="width: 120px">Qté</th>
                      <th style="width: 180px">Prix unit.</th>
                      <th style="width: 160px">Total</th>
                      <th style="width: 56px" class="text-center"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, index) in form.items" :key="index">
                      <td>
                        <div class="d-flex align-items-center gap-3">
                          <div class="sale-product-thumb flex-shrink-0">
                            <img
                              v-if="getProduct(item.product_id)?.image_url"
                              :src="getProduct(item.product_id)!.image_url!"
                              :alt="getProduct(item.product_id)?.name ?? 'Produit'"
                            />
                            <i v-else class="bi bi-box-seam"></i>
                          </div>
                          <div class="flex-grow-1 min-w-0">
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
                        </div>
                      </td>
                      <td>
                        <div class="sale-qty-stepper">
                          <button
                            type="button"
                            class="sale-qty-stepper__btn"
                            title="Diminuer"
                            :disabled="item.quantity <= 1"
                            @click="decrementQuantity(index)"
                          >
                            <i class="bi bi-dash"></i>
                          </button>
                          <span class="sale-qty-stepper__value">{{ item.quantity }}</span>
                          <button
                            type="button"
                            class="sale-qty-stepper__btn"
                            title="Augmenter"
                            @click="incrementQuantity(index)"
                          >
                            <i class="bi bi-plus"></i>
                          </button>
                        </div>
                        <div v-if="getProductUnit(index)" class="text-muted small text-center mt-1">
                          {{ getProductUnit(index) }}{{ item.quantity > 1 ? '(s)' : '' }}
                        </div>
                      </td>
                      <td>
                        <div class="input-group input-group-sm">
                          <input
                            v-model.number="item.unit_price"
                            type="number"
                            step="0.01"
                            min="0"
                            class="form-control"
                            @input="updateItemTotal(index)"
                          />
                          <span class="input-group-text">FCFA</span>
                        </div>
                      </td>
                      <td>
                        <span class="fw-semibold">{{ formatCurrency(item.total_price || 0) }}</span>
                      </td>
                      <td class="text-center">
                        <button
                          type="button"
                          class="btn btn-link text-danger p-0"
                          title="Supprimer"
                          @click="removeItem(index)"
                        >
                          <i class="bi bi-trash fs-5"></i>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="p-3 border-top d-flex flex-wrap gap-2 justify-content-between align-items-center">
              <button type="button" class="btn sale-add-item-btn flex-grow-1" @click="addItem">
                <i class="bi bi-plus-lg me-2"></i>
                Ajouter un article
              </button>
              <button
                v-if="hasDuplicateProducts"
                type="button"
                class="btn btn-warning btn-sm"
                @click="mergeDuplicateProducts"
              >
                <i class="bi bi-arrow-down-up me-1"></i>
                Fusionner les doublons
              </button>
            </div>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-lg-6">
            <div class="card sale-card h-100">
              <div class="card-header sale-card-header">
                <i class="bi bi-person me-2"></i>
                Client &amp; devis
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label">
                    <i class="bi bi-search me-1 text-muted"></i>
                    Client
                  </label>
                  <CustomerAutocomplete
                    ref="customerAutocompleteRef"
                    v-model="form.customer_id"
                    :customers="localCustomers"
                    placeholder="Rechercher un client (optionnel)..."
                    :is-invalid="!!errors.customer_id"
                    :allow-create="canCreateCustomer"
                    @create-request="openCustomerCreateModal"
                  />
                  <div v-if="form.customer_id && canView('customers')" class="mt-2">
                    <Link
                      :href="route('customers.show', { id: form.customer_id })"
                      class="customer-profile-link"
                    >
                      <i class="bi bi-person-lines-fill me-1"></i>
                      Voir la fiche client
                    </Link>
                  </div>
                  <div v-if="errors.customer_id" class="invalid-feedback d-block">{{ errors.customer_id }}</div>
                </div>

                <div class="row g-3 mb-3">
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
                    <label class="form-label">Date de validité</label>
                    <input
                      v-model="form.valid_until"
                      type="date"
                      class="form-control"
                      :class="{ 'is-invalid': errors.valid_until }"
                      :min="mode === 'create' ? today : undefined"
                    />
                    <div v-if="errors.valid_until" class="invalid-feedback">{{ errors.valid_until }}</div>
                    <small class="form-text text-muted">Date limite de validité (optionnel)</small>
                  </div>
                </div>

                <div class="mb-0">
                  <label class="form-label">
                    <i class="bi bi-journal-text me-1 text-muted"></i>
                    Notes
                  </label>
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
          </div>

          <div class="col-lg-6">
            <div class="card sale-card h-100">
              <div class="card-header sale-card-header">
                <i class="bi bi-sliders me-2"></i>
                Ajustements
              </div>
              <div class="card-body">
                <div class="sale-adjust-row mb-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0 d-flex align-items-center gap-2">
                      Taxe
                      <i class="bi bi-info-circle text-muted" title="Appliquer une taxe sur le sous-total"></i>
                    </label>
                    <div class="form-check form-switch mb-0">
                      <input
                        id="quote-tax-enabled"
                        v-model="taxEnabled"
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                      />
                    </div>
                  </div>
                  <div v-show="taxEnabled">
                    <div class="btn-group w-100 mb-2" role="group">
                      <input id="quote-tax-mode-amount" v-model="taxMode" type="radio" class="btn-check" value="amount" />
                      <label class="btn btn-outline-secondary btn-sm" for="quote-tax-mode-amount">Montant</label>
                      <input id="quote-tax-mode-percent" v-model="taxMode" type="radio" class="btn-check" value="percent" />
                      <label class="btn btn-outline-secondary btn-sm" for="quote-tax-mode-percent">Pourcentage</label>
                    </div>
                    <div v-if="taxMode === 'amount'" class="input-group input-group-sm">
                      <input
                        v-model.number="form.tax_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="form-control"
                        placeholder="0"
                        :class="{ 'is-invalid': errors.tax_amount || clientErrors.tax_amount }"
                      />
                      <span class="input-group-text">FCFA</span>
                    </div>
                    <div v-else class="input-group input-group-sm">
                      <input
                        v-model.number="taxPercent"
                        type="number"
                        step="any"
                        min="0"
                        max="100"
                        class="form-control"
                        placeholder="0"
                        :class="{ 'is-invalid': clientErrors.tax_percent }"
                        @input="onTaxPercentInput"
                      />
                      <span class="input-group-text">%</span>
                    </div>
                    <div v-if="taxMode === 'percent' && taxPercent > 0" class="small text-muted mt-1">
                      = {{ formatCurrency(taxAmount) }}
                    </div>
                    <div v-if="clientErrors.tax_percent" class="invalid-feedback d-block">{{ clientErrors.tax_percent }}</div>
                    <div v-if="errors.tax_amount" class="invalid-feedback d-block">{{ errors.tax_amount }}</div>
                    <div v-if="clientErrors.tax_amount" class="invalid-feedback d-block">{{ clientErrors.tax_amount }}</div>
                  </div>
                </div>

                <div class="sale-adjust-row mb-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0">Remise</label>
                    <div class="form-check form-switch mb-0">
                      <input
                        id="quote-discount-enabled"
                        v-model="discountEnabled"
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                      />
                    </div>
                  </div>
                  <div v-show="discountEnabled">
                    <div class="btn-group w-100 mb-2" role="group">
                      <input
                        id="quote-discount-mode-amount"
                        v-model="discountMode"
                        type="radio"
                        class="btn-check"
                        value="amount"
                      />
                      <label class="btn btn-outline-secondary btn-sm" for="quote-discount-mode-amount">Montant</label>
                      <input
                        id="quote-discount-mode-percent"
                        v-model="discountMode"
                        type="radio"
                        class="btn-check"
                        value="percent"
                      />
                      <label class="btn btn-outline-secondary btn-sm" for="quote-discount-mode-percent">Pourcentage</label>
                    </div>
                    <div v-if="discountMode === 'amount'" class="input-group input-group-sm">
                      <input
                        v-model.number="form.discount_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="form-control"
                        placeholder="0"
                        :class="{ 'is-invalid': errors.discount_amount || clientErrors.discount_amount }"
                      />
                      <span class="input-group-text">FCFA</span>
                    </div>
                    <div v-else class="input-group input-group-sm">
                      <input
                        v-model.number="discountPercent"
                        type="number"
                        step="any"
                        min="0"
                        max="100"
                        class="form-control"
                        placeholder="0"
                        :class="{ 'is-invalid': clientErrors.discount_percent }"
                        @input="onDiscountPercentInput"
                      />
                      <span class="input-group-text">%</span>
                    </div>
                    <div v-if="discountMode === 'percent' && discountPercent > 0" class="small text-muted mt-1">
                      = {{ formatCurrency(discountAmount) }}
                    </div>
                    <div v-if="clientErrors.discount_percent" class="invalid-feedback d-block">
                      {{ clientErrors.discount_percent }}
                    </div>
                    <div v-if="errors.discount_amount" class="invalid-feedback d-block">{{ errors.discount_amount }}</div>
                    <div v-if="clientErrors.discount_amount" class="invalid-feedback d-block">
                      {{ clientErrors.discount_amount }}
                    </div>
                  </div>
                </div>

                <div class="sale-summary-breakdown">
                  <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Sous-total</span>
                    <span>{{ formatCurrency(subtotal) }}</span>
                  </div>
                  <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Taxes</span>
                    <span :class="taxAmount > 0 ? 'text-success' : ''">{{ formatCurrency(taxAmount) }}</span>
                  </div>
                  <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Remise</span>
                    <span class="text-danger">
                      {{ discountAmount > 0 ? `- ${formatCurrency(discountAmount)}` : formatCurrency(0) }}
                    </span>
                  </div>
                  <hr class="my-3" />
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold fs-5">TOTAL</span>
                    <span class="fw-bold fs-4 text-success">{{ formatCurrency(totalAmount) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card sale-card mb-4">
          <div class="card-header sale-card-header d-flex justify-content-between align-items-center">
            <span>
              <i class="bi bi-paperclip me-2"></i>
              Pièces jointes
            </span>
            <span v-if="existingAttachments.length" class="badge bg-secondary">
              {{ existingAttachments.length }}
            </span>
          </div>
          <div class="card-body">
            <AttachmentUploader
              v-model="pendingFiles"
              :attachments="existingAttachments"
              :max-files="resolvedAttachmentConfig.maxFiles"
              :max-size-kb="resolvedAttachmentConfig.maxSizeKb"
              :accept="resolvedAttachmentConfig.accept"
              hint="Les nouveaux fichiers seront ajoutés à l'enregistrement. Ils ne sont pas sauvegardés dans le brouillon."
              @preview="openAttachmentPreview"
            />
            <div v-if="errors.attachments" class="text-danger small mt-2">
              {{ errors.attachments }}
            </div>
          </div>
        </div>

        <FormActionsBar class="form-actions-bar--split">
          <Link :href="route('quotes.index')" class="btn btn-outline-secondary order-1 order-sm-1">
            <i class="bi bi-x-lg me-1"></i>
            Annuler
          </Link>
          <div class="d-flex flex-column flex-sm-row gap-2 order-2 order-sm-2 ms-sm-auto">
            <button
              v-if="canPreviewQuote"
              type="button"
              class="btn btn-outline-primary"
              :disabled="processing || isPreviewing || form.items.length === 0"
              :aria-busy="isPreviewing"
              @click="previewQuote"
            >
              <span v-if="isPreviewing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              <i v-else class="bi bi-eye me-1"></i>
              Aperçu
            </button>
            <button
              type="submit"
              class="btn btn-success"
              :disabled="processing || form.items.length === 0"
              :aria-busy="processing"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              <i v-else :class="`${submitIcon} me-1`"></i>
              {{ processing ? submitLoadingLabel : submitLabel }}
            </button>
          </div>
        </FormActionsBar>
      </div>
    </form>

    <CustomerQuickCreateModal
      v-model:open="showCustomerCreateModal"
      :initial-name="customerCreateInitialName"
      @created="handleCustomerCreated"
    />
  </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { onMounted, ref, toRef, computed } from 'vue'
import { route } from '@/lib/routes'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import FormActionsBar from '@/components/page/FormActionsBar.vue'
import DraftSaveStatus from '@/components/drafts/DraftSaveStatus.vue'
import DraftRestoreDialog from '@/components/drafts/DraftRestoreDialog.vue'
import ProductAutocomplete from '@/components/ProductAutocomplete.vue'
import CustomerAutocomplete from '@/components/CustomerAutocomplete.vue'
import CustomerQuickCreateModal from '@/components/customers/CustomerQuickCreateModal.vue'
import AttachmentUploader from '@/components/attachments/AttachmentUploader.vue'
import type { CreatedCustomer } from '@/components/customers/CustomerQuickCreateModal.vue'
import { usePermissions } from '@/composables/usePermissions'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'
import { useDocumentPreview } from '@/composables/useDocumentPreview'
import { useNotificationStore } from '@/modules/NotificationCenter/stores/NotificationStore'
import type { AttachmentConfig, AttachmentRecord } from '@/types/attachment'
import { DEFAULT_ATTACHMENT_CONFIG } from '@/types/attachment'
import {
  useQuoteForm,
  type QuoteFormCustomer,
  type QuoteFormProduct,
  type QuoteFormQuote,
  type QuoteFormMode,
} from '@/composables/useQuoteForm'

const props = defineProps<{
  mode: QuoteFormMode
  quote?: QuoteFormQuote
  customers: QuoteFormCustomer[]
  products: QuoteFormProduct[]
  initialCustomerId?: number | null
  attachmentConfig?: AttachmentConfig
}>()

const resolvedAttachmentConfig = computed(() => props.attachmentConfig ?? DEFAULT_ATTACHMENT_CONFIG)
const pendingFiles = ref<File[]>([])
const existingAttachments = computed<AttachmentRecord[]>(() => props.quote?.attachments ?? [])

const { canCreate, canAny, canView } = usePermissions()
const canCreateCustomer = canCreate('customers')
const canPreviewQuote = canAny('quotes', ['print', 'create', 'update'])
const { openFromPayload } = useDocumentPdfPreview()
const { openAttachment } = useDocumentPreview()
const isPreviewing = ref(false)
const notificationStore = useNotificationStore()

const localCustomers = ref<QuoteFormCustomer[]>([...props.customers])
const customerAutocompleteRef = ref<InstanceType<typeof CustomerAutocomplete> | null>(null)
const showCustomerCreateModal = ref(false)
const customerCreateInitialName = ref('')

const openCustomerCreateModal = (payload?: { initialName?: string }) => {
  customerCreateInitialName.value = payload?.initialName ?? ''
  showCustomerCreateModal.value = true
}

const {
  mode,
  form,
  errors,
  processing,
  clientErrors,
  taxMode,
  discountMode,
  taxPercent,
  discountPercent,
  taxEnabled,
  discountEnabled,
  pageTitle,
  pageSubtitle,
  submitLabel,
  submitLoadingLabel,
  submitIcon,
  today,
  itemsCount,
  subtotal,
  taxAmount,
  discountAmount,
  totalAmount,
  hasDuplicateProducts,
  getProduct,
  addItem,
  removeItem,
  handleProductSelected,
  getExcludedProductIds,
  updateItemTotal,
  decrementQuantity,
  incrementQuantity,
  isProductDuplicate,
  mergeDuplicateProducts,
  getProductUnit,
  formatCurrency,
  onTaxPercentInput,
  onDiscountPercentInput,
  preparePreview,
  submit,
  draft,
} = useQuoteForm({
  mode: props.mode,
  quote: props.quote,
  products: toRef(props, 'products'),
  pendingFiles,
})

const openAttachmentPreview = (attachment: AttachmentRecord) => {
  void openAttachment(attachment, `Aperçu — ${attachment.original_name}`)
}

const previewQuote = async () => {
  const payload = preparePreview()
  if (!payload) {
    return
  }

  isPreviewing.value = true
  try {
    const filename =
      props.mode === 'edit' && props.quote
        ? `Devis_${props.quote.quote_number}.pdf`
        : 'Apercu_Devis.pdf'
    await openFromPayload('quotes.preview', payload, filename)
  } finally {
    isPreviewing.value = false
  }
}

const handleCustomerCreated = (customer: CreatedCustomer) => {
  const normalizedCustomer: QuoteFormCustomer = {
    id: customer.id,
    name: customer.name,
    email: customer.email ?? null,
    phone: customer.phone ?? null,
  }

  const exists = localCustomers.value.some((item) => item.id === normalizedCustomer.id)
  if (!exists) {
    localCustomers.value = [...localCustomers.value, normalizedCustomer].sort((left, right) =>
      left.name.localeCompare(right.name, 'fr'),
    )
  }

  form.customer_id = normalizedCustomer.id
  customerAutocompleteRef.value?.selectCustomer(normalizedCustomer)

  notificationStore.pushToast({
    id: `customer-created-${customer.id}-${Date.now()}`,
    title: 'Client créé avec succès',
    description: `${customer.name} a été ajouté et sélectionné pour ce devis.`,
    priority: 'info',
    duration: 6000,
  })
}

const applyInitialCustomer = () => {
  if (props.mode !== 'create' || !props.initialCustomerId) {
    return
  }

  const customer = localCustomers.value.find((item) => item.id === props.initialCustomerId)
  if (!customer) {
    return
  }

  form.customer_id = customer.id
  customerAutocompleteRef.value?.selectCustomer(customer)
}

onMounted(() => {
  applyInitialCustomer()
})
</script>
