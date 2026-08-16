<template>
  <div class="form-page form-page--sticky-actions form-page--wide sale-form-root delivery-note-form-root">
    <FormPageHeader
      :title="pageTitle"
      :subtitle="pageSubtitle"
      :back-href="route('delivery-notes.index')"
      back-label="Bons de livraison"
    >
      <template #meta>
        <DraftSaveStatus :status="draft.status" :last-saved-at="draft.lastSavedAt" />
      </template>
      <template v-if="mode === 'edit' && deliveryNote" #actions>
        <Link
          :href="route('delivery-notes.show', { id: deliveryNote.id })"
          class="btn btn-outline-primary"
        >
          <i class="bi bi-eye me-1"></i>
          Voir le bon de livraison
        </Link>
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
              <p class="mb-0">
                Aucun article ajouté. Sélectionnez un bon de commande ou cliquez sur « Ajouter un article ».
              </p>
            </div>

            <div v-else>
              <div class="d-md-none sale-items-mobile">
                <div
                  v-for="(item, index) in form.items"
                  :key="`mobile-item-${index}`"
                  class="sale-item-card"
                >
                  <div
                    v-if="showReceiptColumns && hasReceiptInfo(index)"
                    class="row g-2 small text-muted mb-3 pb-3 border-bottom"
                  >
                    <div class="col-6">
                      <span class="d-block">Commandé</span>
                      <strong class="text-dark">{{ item.ordered_quantity ?? 0 }}</strong>
                    </div>
                    <div class="col-6">
                      <span class="d-block">Déjà livré</span>
                      <strong class="text-dark">{{ item.delivered_quantity ?? 0 }}</strong>
                    </div>
                    <div class="col-6">
                      <span class="d-block">Restant</span>
                      <strong class="text-primary">{{ item.remaining_quantity ?? 0 }}</strong>
                    </div>
                    <div class="col-6">
                      <span class="d-block">À livrer</span>
                      <strong class="text-success">{{ item.quantity }}</strong>
                    </div>
                  </div>

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
                        :products="productsForSelection"
                        :exclude-product-ids="getExcludedProductIds(index)"
                        :is-invalid="isProductDuplicate(index) || !!getItemError(index, 'product_id')"
                        placeholder="Rechercher un produit..."
                        @selected="(product) => handleProductSelected(product, index)"
                      />
                      <div v-if="getItemError(index, 'product_id')" class="invalid-feedback d-block">
                        {{ getItemError(index, 'product_id') }}
                      </div>
                      <div v-else-if="isProductDuplicate(index)" class="invalid-feedback d-block">
                        Ce produit est déjà sélectionné dans ce bon de livraison.
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
                      <label class="form-label small mb-1">
                        {{ showReceiptColumns && hasReceiptInfo(index) ? 'À livrer' : 'Quantité' }}
                      </label>
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
                          :disabled="!canIncrementQuantity(index)"
                          @click="incrementQuantity(index)"
                        >
                          <i class="bi bi-plus"></i>
                        </button>
                      </div>
                      <div v-if="getItemError(index, 'quantity')" class="invalid-feedback d-block">
                        {{ getItemError(index, 'quantity') }}
                      </div>
                      <div v-else-if="item.product_id && getProductUnit(index)" class="form-text">
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
                          :class="{ 'is-invalid': !!getItemError(index, 'unit_price') }"
                          @input="updateItemTotal(index)"
                        />
                        <span class="input-group-text">FCFA</span>
                      </div>
                      <div v-if="getItemError(index, 'unit_price')" class="invalid-feedback d-block">
                        {{ getItemError(index, 'unit_price') }}
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
                      <template v-if="showReceiptColumns">
                        <th style="width: 90px" class="text-center">Commandé</th>
                        <th style="width: 90px" class="text-center">Déjà livré</th>
                        <th style="width: 90px" class="text-center">Restant</th>
                      </template>
                      <th :style="{ width: showReceiptColumns ? '130px' : '120px' }">
                        {{ showReceiptColumns ? 'À livrer' : 'Qté' }}
                      </th>
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
                              :products="productsForSelection"
                              :exclude-product-ids="getExcludedProductIds(index)"
                              :is-invalid="isProductDuplicate(index) || !!getItemError(index, 'product_id')"
                              placeholder="Rechercher un produit..."
                              @selected="(product) => handleProductSelected(product, index)"
                            />
                            <div v-if="getItemError(index, 'product_id')" class="invalid-feedback d-block">
                              {{ getItemError(index, 'product_id') }}
                            </div>
                            <div v-else-if="isProductDuplicate(index)" class="invalid-feedback d-block">
                              Ce produit est déjà sélectionné dans ce bon de livraison.
                            </div>
                          </div>
                        </div>
                      </td>
                      <template v-if="showReceiptColumns">
                        <td class="text-center text-muted">
                          {{ hasReceiptInfo(index) ? (item.ordered_quantity ?? '—') : '—' }}
                        </td>
                        <td class="text-center text-muted">
                          {{ hasReceiptInfo(index) ? (item.delivered_quantity ?? '—') : '—' }}
                        </td>
                        <td class="text-center">
                          <span v-if="hasReceiptInfo(index)" class="text-primary fw-medium">
                            {{ item.remaining_quantity ?? '—' }}
                          </span>
                          <span v-else class="text-muted">—</span>
                        </td>
                      </template>
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
                            :disabled="!canIncrementQuantity(index)"
                            @click="incrementQuantity(index)"
                          >
                            <i class="bi bi-plus"></i>
                          </button>
                        </div>
                        <div v-if="getItemError(index, 'quantity')" class="invalid-feedback d-block">
                          {{ getItemError(index, 'quantity') }}
                        </div>
                        <div v-else-if="getProductUnit(index)" class="text-muted small text-center mt-1">
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
                            :class="{ 'is-invalid': !!getItemError(index, 'unit_price') }"
                            @input="updateItemTotal(index)"
                          />
                          <span class="input-group-text">FCFA</span>
                        </div>
                        <div v-if="getItemError(index, 'unit_price')" class="invalid-feedback d-block">
                          {{ getItemError(index, 'unit_price') }}
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
              <button
                type="button"
                class="btn sale-add-item-btn flex-grow-1"
                :disabled="!canAddItem"
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

        <div class="row g-4">
          <div class="col-lg-6">
            <div class="card sale-card h-100">
              <div class="card-header sale-card-header">
                <i class="bi bi-truck me-2"></i>
                Fournisseur &amp; livraison
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label">
                    <i class="bi bi-building me-1 text-muted"></i>
                    Fournisseur
                  </label>
                  <select
                    v-model="form.supplier_id"
                    class="form-select"
                    :class="{ 'is-invalid': errors.supplier_id || clientErrors.supplier_id }"
                    @change="handleSupplierChange"
                  >
                    <option :value="null">Sélectionner un fournisseur</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                      {{ supplier.name }}
                    </option>
                  </select>
                  <div v-if="errors.supplier_id" class="invalid-feedback d-block">{{ errors.supplier_id }}</div>
                  <div v-if="clientErrors.supplier_id" class="invalid-feedback d-block">
                    {{ clientErrors.supplier_id }}
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">
                    <i class="bi bi-search me-1 text-muted"></i>
                    Bon de commande
                  </label>
                  <div class="position-relative po-search-dropdown">
                    <input
                      v-model="poSearchQuery"
                      type="text"
                      class="form-control"
                      placeholder="Rechercher un bon de commande..."
                      :class="{ 'is-invalid': errors.purchase_order_id || clientErrors.purchase_order_id }"
                      @input="handlePOSearch"
                      @focus="showPOSuggestions = true"
                    />

                    <div
                      v-if="showPOSuggestions && filteredPurchaseOrders.length > 0"
                      class="dropdown-menu show w-100"
                    >
                      <a
                        v-for="po in filteredPurchaseOrders"
                        :key="po.id"
                        class="dropdown-item"
                        href="#"
                        @click.prevent="selectPurchaseOrder(po)"
                      >
                        {{ po.po_number }}
                      </a>
                    </div>

                    <div
                      v-if="poSearchQuery && filteredPurchaseOrders.length === 0 && purchaseOrders.length > 0 && form.supplier_id"
                      class="dropdown-menu show w-100"
                    >
                      <div class="dropdown-item text-muted">Aucun bon de commande trouvé</div>
                    </div>

                    <div
                      v-if="poSearchQuery && !form.supplier_id"
                      class="dropdown-menu show w-100"
                    >
                      <div class="dropdown-item text-muted">
                        Veuillez d'abord sélectionner un fournisseur
                      </div>
                    </div>
                  </div>

                  <div v-if="errors.purchase_order_id" class="invalid-feedback d-block">
                    {{ errors.purchase_order_id }}
                  </div>
                  <div v-if="clientErrors.purchase_order_id" class="invalid-feedback d-block">
                    {{ clientErrors.purchase_order_id }}
                  </div>

                  <div v-if="selectedPurchaseOrder" class="mt-2">
                    <span class="badge bg-primary">
                      <i class="bi bi-check-circle me-1"></i>
                      {{ selectedPurchaseOrder.po_number }}
                    </span>
                  </div>
                </div>

                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label">Date de livraison</label>
                    <input
                      v-model="form.delivery_date"
                      type="date"
                      class="form-control"
                      :class="{ 'is-invalid': errors.delivery_date || clientErrors.delivery_date }"
                    />
                    <div v-if="errors.delivery_date" class="invalid-feedback">{{ errors.delivery_date }}</div>
                    <div v-if="clientErrors.delivery_date" class="invalid-feedback">
                      {{ clientErrors.delivery_date }}
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">N° facture fournisseur</label>
                    <input
                      v-model="form.invoice_number"
                      type="text"
                      class="form-control"
                      placeholder="EX: FACT-2025-001"
                      :class="{ 'is-invalid': errors.invoice_number }"
                    />
                    <div v-if="errors.invoice_number" class="invalid-feedback">{{ errors.invoice_number }}</div>
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
                    placeholder="Ajoutez des notes sur cette livraison..."
                    :class="{ 'is-invalid': errors.notes }"
                  ></textarea>
                  <div v-if="errors.notes" class="invalid-feedback">{{ errors.notes }}</div>
                </div>

                <div class="alert alert-info mb-0">
                  <i class="bi bi-info-circle me-1"></i>
                  Le stock sera ajusté automatiquement lors de la validation du bon de livraison.
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
                        id="dn-tax-enabled"
                        v-model="taxEnabled"
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                      />
                    </div>
                  </div>
                  <div v-show="taxEnabled">
                    <div class="btn-group w-100 mb-2" role="group">
                      <input id="dn-tax-mode-amount" v-model="taxMode" type="radio" class="btn-check" value="amount" />
                      <label class="btn btn-outline-secondary btn-sm" for="dn-tax-mode-amount">Montant</label>
                      <input id="dn-tax-mode-percent" v-model="taxMode" type="radio" class="btn-check" value="percent" />
                      <label class="btn btn-outline-secondary btn-sm" for="dn-tax-mode-percent">Pourcentage</label>
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
                    <div v-if="clientErrors.tax_percent" class="invalid-feedback d-block">
                      {{ clientErrors.tax_percent }}
                    </div>
                    <div v-if="errors.tax_amount" class="invalid-feedback d-block">{{ errors.tax_amount }}</div>
                    <div v-if="clientErrors.tax_amount" class="invalid-feedback d-block">
                      {{ clientErrors.tax_amount }}
                    </div>
                  </div>
                </div>

                <div class="sale-adjust-row mb-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0">Remise</label>
                    <div class="form-check form-switch mb-0">
                      <input
                        id="dn-discount-enabled"
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
                        id="dn-discount-mode-amount"
                        v-model="discountMode"
                        type="radio"
                        class="btn-check"
                        value="amount"
                      />
                      <label class="btn btn-outline-secondary btn-sm" for="dn-discount-mode-amount">Montant</label>
                      <input
                        id="dn-discount-mode-percent"
                        v-model="discountMode"
                        type="radio"
                        class="btn-check"
                        value="percent"
                      />
                      <label class="btn btn-outline-secondary btn-sm" for="dn-discount-mode-percent">Pourcentage</label>
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

        <div v-if="!isFormDisabled" class="card sale-card mb-4">
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
          <Link :href="route('delivery-notes.index')" class="btn btn-outline-secondary order-1 order-sm-1">
            <i class="bi bi-x-lg me-1"></i>
            Annuler
          </Link>
          <div class="d-flex flex-column flex-sm-row gap-2 order-2 order-sm-2 ms-sm-auto">
            <button
              v-if="canPreviewDeliveryNote"
              type="button"
              class="btn btn-outline-primary"
              :disabled="processing || isPreviewing || form.items.length === 0 || !form.supplier_id || !form.purchase_order_id"
              :aria-busy="isPreviewing"
              @click="previewDeliveryNote"
            >
              <span
                v-if="isPreviewing"
                class="spinner-border spinner-border-sm me-2"
                role="status"
                aria-hidden="true"
              ></span>
              <i v-else class="bi bi-eye me-1"></i>
              Aperçu
            </button>
            <button
              type="submit"
              class="btn btn-success"
              :disabled="processing || form.items.length === 0 || !form.supplier_id || !form.purchase_order_id"
              :aria-busy="processing"
            >
              <span
                v-if="processing"
                class="spinner-border spinner-border-sm me-2"
                role="status"
                aria-hidden="true"
              ></span>
              <i v-else :class="`${submitIcon} me-1`"></i>
              {{ processing ? submitLoadingLabel : submitLabel }}
            </button>
          </div>
        </FormActionsBar>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed, ref, toRef } from 'vue'
import { route } from '@/lib/routes'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import FormActionsBar from '@/components/page/FormActionsBar.vue'
import DraftSaveStatus from '@/components/drafts/DraftSaveStatus.vue'
import DraftRestoreDialog from '@/components/drafts/DraftRestoreDialog.vue'
import ProductAutocomplete from '@/components/ProductAutocomplete.vue'
import AttachmentUploader from '@/components/attachments/AttachmentUploader.vue'
import { usePermissions } from '@/composables/usePermissions'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'
import { useDocumentPreview } from '@/composables/useDocumentPreview'
import type { AttachmentConfig, AttachmentRecord } from '@/types/attachment'
import { DEFAULT_ATTACHMENT_CONFIG } from '@/types/attachment'
import type { PurchaseOrderReceiptSummary } from '@/composables/usePurchaseOrderReceipt'
import {
  useDeliveryNoteForm,
  type DeliveryNoteFormDeliveryNote,
  type DeliveryNoteFormMode,
  type DeliveryNoteFormProduct,
  type DeliveryNoteFormPurchaseOrder,
  type DeliveryNoteFormSupplier,
} from '@/composables/useDeliveryNoteForm'

const props = defineProps<{
  mode: DeliveryNoteFormMode
  deliveryNote?: DeliveryNoteFormDeliveryNote
  suppliers: DeliveryNoteFormSupplier[]
  products: DeliveryNoteFormProduct[]
  purchaseOrders: DeliveryNoteFormPurchaseOrder[]
  purchaseOrder?: DeliveryNoteFormPurchaseOrder
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null
  initialPurchaseOrderId?: number | null
  attachmentConfig?: AttachmentConfig
}>()

const resolvedAttachmentConfig = computed(() => props.attachmentConfig ?? DEFAULT_ATTACHMENT_CONFIG)
const pendingFiles = ref<File[]>([])
const existingAttachments = computed<AttachmentRecord[]>(() => props.deliveryNote?.attachments ?? [])

const { canAny } = usePermissions()
const canPreviewDeliveryNote = canAny('delivery-notes', ['print', 'create', 'update'])
const { openFromPayload } = useDocumentPdfPreview()
const { openAttachment } = useDocumentPreview()
const isPreviewing = ref(false)

const {
  mode,
  deliveryNote,
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
  itemsCount,
  subtotal,
  taxAmount,
  discountAmount,
  totalAmount,
  hasDuplicateProducts,
  receiptSummary,
  poSearchQuery,
  showPOSuggestions,
  selectedPurchaseOrder,
  filteredPurchaseOrders,
  productsForSelection,
  getProduct,
  getReceiptLineForItem,
  addItem,
  removeItem,
  handleProductSelected,
  getExcludedProductIds,
  updateItemTotal,
  canIncrementQuantity,
  decrementQuantity,
  incrementQuantity,
  isProductDuplicate,
  mergeDuplicateProducts,
  getProductUnit,
  formatCurrency,
  onTaxPercentInput,
  onDiscountPercentInput,
  handlePOSearch,
  selectPurchaseOrder,
  handleSupplierChange,
  preparePreview,
  submit,
  draft,
} = useDeliveryNoteForm({
  mode: props.mode,
  deliveryNote: props.deliveryNote,
  suppliers: props.suppliers,
  products: toRef(props, 'products'),
  purchaseOrders: toRef(props, 'purchaseOrders'),
  purchaseOrder: props.purchaseOrder,
  purchaseOrderReceipt: props.purchaseOrderReceipt,
  initialPurchaseOrderId: props.initialPurchaseOrderId,
  pendingFiles,
})

const openAttachmentPreview = (attachment: AttachmentRecord) => {
  void openAttachment(attachment, `Aperçu — ${attachment.original_name}`)
}

const showReceiptColumns = computed(() => !!form.purchase_order_id && !!receiptSummary.value)

const hasReceiptInfo = (index: number): boolean => {
  const item = form.items[index]
  return item?.ordered_quantity !== undefined || getReceiptLineForItem(index) !== null
}

const getItemError = (index: number, field: string): string | undefined =>
  clientErrors.value[`items.${index}.${field}`]

const canAddItem = computed(() => {
  if (!form.purchase_order_id || !receiptSummary.value) {
    return true
  }

  const selectedIds = new Set(form.items.map((item) => item.product_id).filter((id) => id > 0))
  return receiptSummary.value.items.some(
    (line) => line.available_quantity > 0 && !selectedIds.has(line.product_id),
  )
})

const previewDeliveryNote = async () => {
  const payload = preparePreview()
  if (!payload) {
    return
  }

  isPreviewing.value = true
  try {
    const filename =
      props.mode === 'edit' && props.deliveryNote
        ? `BL_${props.deliveryNote.delivery_number}.pdf`
        : 'Apercu_BL.pdf'
    await openFromPayload('delivery-notes.preview', payload, filename)
  } finally {
    isPreviewing.value = false
  }
}
</script>

<style scoped>
.po-search-dropdown .dropdown-menu.show {
  max-height: 200px;
  overflow-y: auto;
  position: absolute;
  top: 100%;
  z-index: 1000;
  width: 100%;
  margin-top: 0.25rem;
  padding: 0.5rem 0;
}

.po-search-dropdown .form-control {
  z-index: 1;
}
</style>
