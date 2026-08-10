<template>
  <div class="sale-form-root">
    <div class="sale-create-header mb-4">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb sale-create-breadcrumb mb-2">
          <li class="breadcrumb-item">
            <Link :href="route('sales.index')">Ventes</Link>
          </li>
          <li class="breadcrumb-item active" aria-current="page">{{ breadcrumbLabel }}</li>
        </ol>
      </nav>
      <h1 class="h2 mb-0">{{ pageTitle }}</h1>
      <p v-if="mode === 'edit'" class="text-muted mb-0 mt-1">Modifiez les informations de la vente</p>
    </div>

    <form novalidate @submit.prevent="submit">
      <!-- Cartes résumé horizontales -->
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

      <!-- Table articles -->
      <div class="card sale-card mb-4">
        <div class="card-body p-0">
          <div v-if="form.items.length === 0" class="text-center py-5 text-muted">
            <i class="bi bi-cart-x fs-1 mb-3 d-block"></i>
            <p class="mb-0">Aucun article ajouté. Cliquez sur « Ajouter un article » pour commencer.</p>
          </div>

          <div v-else class="table-responsive">
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
                          Ce produit est déjà sélectionné dans cette vente.
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div
                      class="sale-qty-stepper"
                      :class="{ 'sale-qty-stepper--invalid': isQuantityExceedsStock(index) }"
                    >
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
                        :disabled="!canIncrementQuantity(index)"
                        @click="incrementQuantity(index)"
                      >
                        <i class="bi bi-plus"></i>
                      </button>
                    </div>
                    <div v-if="getProductUnit(index)" class="text-muted small text-center mt-1">
                      {{ getProductUnit(index) }}{{ item.quantity > 1 ? '(s)' : '' }}
                    </div>
                    <div v-if="isQuantityExceedsStock(index)" class="invalid-feedback d-block text-center">
                      Stock insuffisant. Disponible : {{ getAvailableStock(index) }} {{ getProductUnit(index) }}
                    </div>
                    <div v-else-if="item.product_id" class="form-text text-center">
                      Stock : {{ getAvailableStock(index) }}
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
                      <span class="input-group-text">Fcfa</span>
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

          <div class="p-3 border-top d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <button
              type="button"
              class="btn sale-add-item-btn flex-grow-1"
              @click="addItem"
            >
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

      <!-- Bas : Client & Ajustements -->
      <div class="row g-4">
        <div class="col-lg-6">
          <div class="card sale-card h-100">
            <div class="card-header sale-card-header">
              <i class="bi bi-person me-2"></i>
              Client &amp; paiement
            </div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label">
                  <i class="bi bi-search me-1 text-muted"></i>
                  Client
                </label>
                <CustomerAutocomplete
                  v-model="form.customer_id"
                  :customers="customers"
                  placeholder="Rechercher un client (optionnel)..."
                  :is-invalid="!!errors.customer_id"
                />
                <div v-if="errors.customer_id" class="invalid-feedback d-block">{{ errors.customer_id }}</div>
              </div>

              <div class="sale-payment-panel border rounded p-3 mb-3">
                <div class="d-flex align-items-center gap-2 mb-3">
                  <i class="bi bi-cash-coin text-success"></i>
                  <span class="fw-semibold">Paiement</span>
                </div>

                <div class="sale-payment-row mb-3">
                  <span class="sale-payment-row__label">Total de la vente</span>
                  <span class="sale-payment-row__value fw-semibold">{{ formatCurrency(totalAmount) }}</span>
                </div>

                <div class="mb-3">
                  <label class="form-label mb-1">
                    Mode de paiement
                    <span v-if="isPaymentMethodRequired" class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.payment_method"
                    class="form-select"
                    :required="isPaymentMethodRequired"
                    :class="{ 'is-invalid': errors.payment_method || clientErrors.payment_method }"
                    @blur="validateField('payment_method', form.payment_method)"
                    @change="validateField('payment_method', form.payment_method)"
                  >
                    <option value="">Aucun / non renseigné</option>
                    <option value="cash">Espèces</option>
                    <option value="card">Carte</option>
                    <option value="bank_transfer">Virement bancaire</option>
                    <option value="check">Chèque</option>
                    <option value="orange_money">Orange Money</option>
                    <option value="wave">Wave</option>
                  </select>
                  <div v-if="errors.payment_method" class="invalid-feedback">{{ errors.payment_method }}</div>
                  <div v-if="clientErrors.payment_method" class="invalid-feedback">{{ clientErrors.payment_method }}</div>
                  <small v-if="!isPaymentMethodRequired" class="form-text text-muted">
                    Facultatif tant qu'aucun paiement n'est saisi.
                  </small>
                </div>

                <div class="mb-3">
                  <label class="form-label mb-1">Montant payé</label>
                  <div class="input-group">
                    <input
                      v-model.number="form.down_payment_amount"
                      type="number"
                      step="0.01"
                      min="0"
                      :max="totalAmount"
                      class="form-control"
                      placeholder="0"
                      :class="{ 'is-invalid': errors.down_payment_amount || clientErrors.down_payment_amount }"
                      @input="onDownPaymentInput"
                    />
                    <span class="input-group-text">Fcfa</span>
                  </div>
                  <div v-if="errors.down_payment_amount" class="invalid-feedback d-block">{{ errors.down_payment_amount }}</div>
                  <div v-if="clientErrors.down_payment_amount" class="invalid-feedback d-block">{{ clientErrors.down_payment_amount }}</div>
                  <div class="form-text text-muted">Maximum : {{ formatCurrency(totalAmount) }}</div>
                </div>

                <div class="sale-payment-row mb-3">
                  <span class="sale-payment-row__label">Reste à payer</span>
                  <span
                    class="sale-payment-row__value fw-semibold"
                    :class="paymentState.remainingAmount > 0 ? 'text-warning' : 'text-success'"
                  >
                    {{ formatCurrency(paymentState.remainingAmount) }}
                  </span>
                </div>

                <div class="mb-3">
                  <label class="form-label mb-1" :class="{ 'text-muted': isSalePaid }">
                    Échéance
                    <i v-if="isSalePaid" class="bi bi-lock-fill ms-1 text-muted" title="Vente payée"></i>
                  </label>
                  <input
                    v-model="form.due_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': errors.due_date }"
                    :disabled="isSalePaid"
                    :min="mode === 'create' ? today : undefined"
                    :aria-disabled="isSalePaid"
                  />
                  <div v-if="errors.due_date" class="invalid-feedback">{{ errors.due_date }}</div>
                  <small v-if="isSalePaid" class="form-text text-muted">
                    Non modifiable — la vente est entièrement payée.
                  </small>
                  <small v-else class="form-text text-muted">Date limite de paiement (optionnel)</small>
                </div>

                <div class="sale-payment-row">
                  <span class="sale-payment-row__label">Statut</span>
                  <span :class="paymentStatusBadgeClass">
                    <i :class="paymentStatusIcon" class="me-1"></i>
                    {{ paymentStatusLabel }}
                  </span>
                </div>
              </div>

              <div v-if="form.payment_method === 'cash'" class="border rounded p-3 bg-light mb-3">
                <div class="mb-2">
                  <label class="form-label mb-1">Montant reçu (Fcfa)</label>
                  <div class="input-group">
                    <input
                      v-model.number="cashReceivedAmount"
                      type="number"
                      step="0.01"
                      min="0"
                      class="form-control"
                      placeholder="0.00"
                      :class="{ 'is-invalid': cashReceivedAmount > 0 && cashReceivedAmount < cashAmountExpected }"
                    />
                    <span class="input-group-text">Fcfa</span>
                  </div>
                  <div
                    v-if="cashReceivedAmount > 0 && cashReceivedAmount < cashAmountExpected"
                    class="invalid-feedback d-block"
                  >
                    Le montant reçu doit être au moins égal à {{ formatCurrency(cashAmountExpected) }}
                  </div>
                  <div class="form-text text-muted">
                    Montant attendu : {{ formatCurrency(cashAmountExpected) }}
                  </div>
                </div>
                <div
                  v-if="form.payment_method === 'cash' && cashReceivedAmount >= cashAmountExpected && cashAmountExpected > 0"
                  class="d-flex justify-content-between p-2 bg-white rounded border"
                >
                  <span class="fw-semibold">Monnaie à rendre :</span>
                  <span class="fw-bold text-info">{{ formatCurrency(changeAmount) }}</span>
                </div>
                <div v-if="effectiveDownPaymentAmount > (form.down_payment_amount || 0)" class="form-text text-muted mt-2">
                  Paiement enregistré : {{ formatCurrency(effectiveDownPaymentAmount) }}
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">
                  <i class="bi bi-journal-text me-1 text-muted"></i>
                  Notes
                </label>
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
        </div>

        <div class="col-lg-6">
          <div class="card sale-card h-100">
            <div class="card-header sale-card-header">
              <i class="bi bi-sliders me-2"></i>
              Ajustements
            </div>
            <div class="card-body">
              <!-- Taxes -->
              <div class="sale-adjust-row mb-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <label class="form-label mb-0 d-flex align-items-center gap-2">
                    Taxe
                    <i class="bi bi-info-circle text-muted" title="Appliquer une taxe sur le sous-total"></i>
                  </label>
                  <div class="form-check form-switch mb-0">
                    <input
                      id="tax-enabled"
                      v-model="taxEnabled"
                      class="form-check-input"
                      type="checkbox"
                      role="switch"
                    />
                  </div>
                </div>
                <div v-show="taxEnabled">
                  <div class="btn-group w-100 mb-2" role="group">
                    <input id="tax-mode-amount" v-model="taxMode" type="radio" class="btn-check" value="amount" />
                    <label class="btn btn-outline-secondary btn-sm" for="tax-mode-amount">Montant</label>
                    <input id="tax-mode-percent" v-model="taxMode" type="radio" class="btn-check" value="percent" />
                    <label class="btn btn-outline-secondary btn-sm" for="tax-mode-percent">Pourcentage</label>
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
                    <span class="input-group-text">Fcfa</span>
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

              <!-- Remise -->
              <div class="sale-adjust-row mb-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <label class="form-label mb-0">Remise</label>
                  <div class="form-check form-switch mb-0">
                    <input
                      id="discount-enabled"
                      v-model="discountEnabled"
                      class="form-check-input"
                      type="checkbox"
                      role="switch"
                    />
                  </div>
                </div>
                <div v-show="discountEnabled">
                  <div class="btn-group w-100 mb-2" role="group">
                    <input id="discount-mode-amount" v-model="discountMode" type="radio" class="btn-check" value="amount" />
                    <label class="btn btn-outline-secondary btn-sm" for="discount-mode-amount">Montant</label>
                    <input id="discount-mode-percent" v-model="discountMode" type="radio" class="btn-check" value="percent" />
                    <label class="btn btn-outline-secondary btn-sm" for="discount-mode-percent">Pourcentage</label>
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
                    <span class="input-group-text">Fcfa</span>
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
                  <div v-if="clientErrors.discount_percent" class="invalid-feedback d-block">{{ clientErrors.discount_percent }}</div>
                  <div v-if="errors.discount_amount" class="invalid-feedback d-block">{{ errors.discount_amount }}</div>
                  <div v-if="clientErrors.discount_amount" class="invalid-feedback d-block">{{ clientErrors.discount_amount }}</div>
                </div>
              </div>

              <!-- Récapitulatif -->
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
                  <span class="text-danger">{{ discountAmount > 0 ? `- ${formatCurrency(discountAmount)}` : formatCurrency(0) }}</span>
                </div>
                <div v-if="effectiveDownPaymentAmount > 0" class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Montant payé</span>
                  <span class="text-primary fw-semibold">{{ formatCurrency(effectiveDownPaymentAmount) }}</span>
                </div>
                <div v-if="paymentState.remainingAmount > 0" class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Reste à payer</span>
                  <span class="text-warning fw-semibold">{{ formatCurrency(paymentState.remainingAmount) }}</span>
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

      <div class="sale-form-actions border-top pt-4 mt-4">
        <div class="d-flex flex-column flex-sm-row gap-2 justify-content-sm-between align-items-stretch align-items-sm-center">
          <Link :href="route('sales.index')" class="btn btn-outline-secondary order-1 order-sm-1">
            <i class="bi bi-x-lg me-1"></i>
            Annuler
          </Link>
          <button
            type="submit"
            class="btn btn-success order-2 order-sm-2 ms-sm-auto"
            :disabled="processing || form.items.length === 0"
            :aria-busy="processing"
          >
            <span v-if="processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            <i v-else :class="`${submitIcon} me-1`"></i>
            {{ processing ? submitLoadingLabel : submitLabel }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { toRef } from 'vue'
import { route } from '@/lib/routes'
import ProductAutocomplete from '@/components/ProductAutocomplete.vue'
import CustomerAutocomplete from '@/components/CustomerAutocomplete.vue'
import {
  useSaleForm,
  type SaleFormCustomer,
  type SaleFormProduct,
  type SaleFormSale,
  type SaleFormMode,
} from '@/composables/useSaleForm'

const props = defineProps<{
  mode: SaleFormMode
  sale?: SaleFormSale
  customers: SaleFormCustomer[]
  products: SaleFormProduct[]
}>()

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
  cashReceivedAmount,
  pageTitle,
  breadcrumbLabel,
  submitLabel,
  submitLoadingLabel,
  submitIcon,
  today,
  itemsCount,
  subtotal,
  taxAmount,
  discountAmount,
  totalAmount,
  effectiveDownPaymentAmount,
  paymentState,
  paymentStatusLabel,
  paymentStatusBadgeClass,
  paymentStatusIcon,
  isSalePaid,
  isPaymentMethodRequired,
  cashAmountExpected,
  changeAmount,
  hasDuplicateProducts,
  getProduct,
  addItem,
  removeItem,
  handleProductSelected,
  getExcludedProductIds,
  updateItemTotal,
  updateTaxFromPercent,
  updateDiscountFromPercent,
  canIncrementQuantity,
  decrementQuantity,
  incrementQuantity,
  isProductDuplicate,
  mergeDuplicateProducts,
  isQuantityExceedsStock,
  getAvailableStock,
  getProductUnit,
  formatCurrency,
  onDownPaymentInput,
  onTaxPercentInput,
  onDiscountPercentInput,
  validateField,
  submit,
} = useSaleForm({
  mode: props.mode,
  sale: props.sale,
  products: toRef(props, 'products'),
})
</script>

<style scoped>
.sale-create-breadcrumb {
  --bs-breadcrumb-divider: '/';
  font-size: 0.875rem;
}

.sale-create-breadcrumb .breadcrumb-item a {
  color: #059669;
  text-decoration: none;
}

.sale-create-breadcrumb .breadcrumb-item.active {
  color: #64748b;
}

.sale-stat-card {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 1rem 1.1rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
}

.sale-stat-card--accent {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.sale-stat-card--total {
  background: #ecfdf5;
  border-color: #6ee7b7;
}

.sale-stat-card__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.65rem;
  background: #f8fafc;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #64748b;
  font-size: 1.1rem;
  flex-shrink: 0;
}

.sale-stat-card--accent .sale-stat-card__icon,
.sale-stat-card--total .sale-stat-card__icon {
  background: rgba(255, 255, 255, 0.7);
  color: #059669;
}

.sale-stat-card__value {
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1.2;
  color: #0f172a;
}

.sale-stat-card__value--sm {
  font-size: 1rem;
}

.sale-stat-card__value--total {
  font-size: 1.15rem;
  color: #059669;
}

.sale-stat-card__label {
  font-size: 0.78rem;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.sale-card {
  border: 1px solid #e5e7eb;
  border-radius: 0.85rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.sale-card-header {
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  font-weight: 600;
  padding: 0.9rem 1.15rem;
}

.sale-items-table thead th {
  background: #f8fafc;
  border-bottom: 1px solid #e5e7eb;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #64748b;
  padding: 0.85rem 1rem;
}

.sale-items-table tbody td {
  padding: 1rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}

.sale-product-thumb {
  width: 44px;
  height: 44px;
  border-radius: 0.5rem;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  color: #94a3b8;
}

.sale-product-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.sale-qty-stepper {
  display: inline-flex;
  align-items: stretch;
  border: 1px solid #d1d5db;
  border-radius: 0.5rem;
  overflow: hidden;
  background: #fff;
}

.sale-qty-stepper--invalid {
  border-color: #dc3545;
}

.sale-qty-stepper__btn {
  width: 2.25rem;
  border: 0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  line-height: 1;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.sale-qty-stepper__btn:not(:disabled):hover {
  background: #f8fafc;
  color: #0f172a;
}

.sale-qty-stepper__btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.sale-qty-stepper__btn + .sale-qty-stepper__value,
.sale-qty-stepper__value + .sale-qty-stepper__btn {
  border-left: 1px solid #e5e7eb;
}

.sale-qty-stepper__value {
  min-width: 2.5rem;
  padding: 0.45rem 0.5rem;
  text-align: center;
  font-weight: 600;
  color: #0f172a;
  user-select: none;
}

.sale-add-item-btn {
  border: 2px dashed #cbd5e1;
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
  padding: 0.75rem 1rem;
  border-radius: 0.65rem;
}

.sale-add-item-btn:hover {
  background: #f1f5f9;
  border-color: #94a3b8;
  color: #0f172a;
}

.sale-summary-breakdown {
  background: #f8fafc;
  border-radius: 0.65rem;
  padding: 1rem 1.1rem;
}

.sale-adjust-row .form-check-input:checked {
  background-color: #059669;
  border-color: #059669;
}

.min-w-0 {
  min-width: 0;
}

.dark ::placeholder,
.dark input::placeholder,
.dark textarea::placeholder {
  color: #94a3b8 !important;
  opacity: 1 !important;
}

input::placeholder,
textarea::placeholder,
.form-control::placeholder {
  color: #6c757d !important;
  opacity: 1 !important;
}

.sale-payment-panel {
  background: #f8fafc;
}

.sale-payment-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.sale-payment-row__label {
  color: #64748b;
  font-size: 0.9rem;
}

.sale-payment-row__value {
  text-align: right;
}

.sale-payment-status {
  display: inline-flex;
  align-items: center;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  font-size: 0.875rem;
  font-weight: 600;
}

.sale-payment-status--pending {
  background: #fef2f2;
  color: #dc2626;
}

.sale-payment-status--partial {
  background: #fffbeb;
  color: #d97706;
}

.sale-payment-status--paid {
  background: #ecfdf5;
  color: #059669;
}

.form-control:disabled {
  background-color: #f1f5f9;
  color: #64748b;
  opacity: 1;
  cursor: not-allowed;
}

.dark .form-control:disabled {
  background-color: #1e293b;
  color: #94a3b8;
  border-color: #334155;
}

.sale-form-actions .btn {
  min-height: 2.75rem;
}

@media (max-width: 575.98px) {
  .sale-form-actions .btn {
    width: 100%;
  }
}
</style>
