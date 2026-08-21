<script setup lang="ts">
import BarcodeInput from '@/components/BarcodeInput.vue'
import { useProductBarcodeLookup } from '@/composables/useProductBarcodeLookup'
import { route } from '@/lib/routes'
import type { ScannableProduct } from '@/types/product'
import { ProductBarcodeLookupError } from '@/types/product'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, ref } from 'vue'
import { dashboard } from '@/routes'

const props = defineProps<{
  canAdjustStock: boolean
}>()

const { lookupProductByBarcode } = useProductBarcodeLookup()

const barcodeInputRef = ref<InstanceType<typeof BarcodeInput> | null>(null)
const selectedProduct = ref<ScannableProduct | null>(null)
const countedQuantity = ref<number | null>(null)
const previousStock = ref<number | null>(null)
const lastDelta = ref<number | null>(null)
const feedbackState = ref<'idle' | 'loading' | 'success' | 'not-found' | 'error'>('idle')
const feedbackMessage = ref('')
const saving = ref(false)
const scanning = ref(false)

const canValidate = computed(() =>
  props.canAdjustStock
  && selectedProduct.value != null
  && countedQuantity.value != null
  && countedQuantity.value >= 0
  && !saving.value,
)

function resetFeedback(): void {
  feedbackState.value = 'idle'
  feedbackMessage.value = ''
}

async function handleBarcodeScanned(barcode: string): Promise<void> {
  if (scanning.value || saving.value) {
    return
  }

  scanning.value = true
  resetFeedback()
  feedbackState.value = 'loading'
  feedbackMessage.value = 'Recherche du produit…'
  selectedProduct.value = null
  countedQuantity.value = null
  previousStock.value = null
  lastDelta.value = null

  try {
    const product = await lookupProductByBarcode(barcode)

    if (!product) {
      feedbackState.value = 'not-found'
      feedbackMessage.value = `⚠ Produit non trouvé (${barcode})`
      await nextTick()
      barcodeInputRef.value?.focus()
      return
    }

    selectedProduct.value = product
    previousStock.value = product.stock_quantity
    countedQuantity.value = product.stock_quantity
    feedbackState.value = 'success'
    feedbackMessage.value = `✓ Produit trouvé — ${product.name}`

    await nextTick()
    const qtyInput = document.getElementById('inventory-counted-qty') as HTMLInputElement | null
    qtyInput?.focus()
    qtyInput?.select()
  } catch (error) {
    feedbackState.value = 'error'
    feedbackMessage.value = error instanceof ProductBarcodeLookupError
      ? error.message
      : 'Impossible de rechercher le produit.'
    await nextTick()
    barcodeInputRef.value?.focus()
  } finally {
    scanning.value = false
  }
}

async function validateCount(): Promise<void> {
  if (!canValidate.value || !selectedProduct.value) {
    return
  }

  saving.value = true
  resetFeedback()
  feedbackState.value = 'loading'
  feedbackMessage.value = 'Enregistrement…'

  try {
    const response = await fetch(route('stock-inventory.count'), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        barcode: selectedProduct.value.barcode ?? '',
        counted_quantity: countedQuantity.value,
      }),
    })

    const payload = await response.json() as {
      message?: string
      product?: ScannableProduct
      previous_stock?: number
      counted_quantity?: number
      delta?: number
    }

    if (!response.ok) {
      feedbackState.value = 'error'
      feedbackMessage.value = payload.message ?? 'Enregistrement impossible.'
      return
    }

    if (payload.product) {
      selectedProduct.value = payload.product
    }

    previousStock.value = payload.previous_stock ?? previousStock.value
    lastDelta.value = payload.delta ?? null
    feedbackState.value = 'success'
    feedbackMessage.value = `✓ Stock enregistré (${payload.previous_stock ?? '—'} → ${payload.counted_quantity ?? countedQuantity.value})`

    selectedProduct.value = null
    countedQuantity.value = null

    await nextTick()
    barcodeInputRef.value?.focus()
  } catch {
    feedbackState.value = 'error'
    feedbackMessage.value = 'Erreur réseau lors de l\'enregistrement.'
  } finally {
    saving.value = false
  }
}

function cancelSelection(): void {
  selectedProduct.value = null
  countedQuantity.value = null
  previousStock.value = null
  resetFeedback()
  barcodeInputRef.value?.focus()
}
</script>

<template>
  <Head title="Inventaire par scan" />

  <div class="form-page form-page--wide">
    <div class="form-page__header mb-4">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <h1 class="h3 mb-1">Inventaire par scan</h1>
          <p class="text-muted mb-0">
            Douchette USB/Bluetooth (mode clavier) — scan rapide et saisie de la quantité comptée.
          </p>
        </div>
        <div class="d-flex gap-2">
          <Link :href="route('products.index')" class="btn btn-sm btn-outline-secondary">Produits</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Tableau de bord</Link>
        </div>
      </div>
    </div>

    <div v-if="!canAdjustStock" class="alert alert-warning">
      Vous n'avez pas les droits pour ajuster le stock. Consultation du scan uniquement.
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <BarcodeInput
          ref="barcodeInputRef"
          :loading="scanning || saving"
          :disabled="!canAdjustStock && scanning"
          :feedback-state="feedbackState"
          :feedback-message="feedbackMessage"
          hint="Placez le focus ici, scannez avec la douchette, puis saisissez la quantité comptée."
          @scanned="handleBarcodeScanned"
        />
      </div>
    </div>

    <div v-if="selectedProduct" class="card mb-4">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h2 class="h5 mb-1">{{ selectedProduct.name }}</h2>
            <p class="text-muted mb-0 font-monospace">{{ selectedProduct.barcode }}</p>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="cancelSelection">
            Annuler
          </button>
        </div>

        <dl class="row mb-4">
          <div class="col-6 col-md-3">
            <dt class="text-muted small">Stock actuel</dt>
            <dd class="fs-4 mb-0">{{ previousStock ?? selectedProduct.stock_quantity }}</dd>
          </div>
          <div class="col-6 col-md-3">
            <dt class="text-muted small">Unité</dt>
            <dd class="mb-0">{{ selectedProduct.unit }}</dd>
          </div>
          <div class="col-6 col-md-3">
            <dt class="text-muted small">Prix</dt>
            <dd class="mb-0">{{ selectedProduct.price }}</dd>
          </div>
        </dl>

        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label for="inventory-counted-qty" class="form-label">Quantité comptée</label>
            <input
              id="inventory-counted-qty"
              v-model.number="countedQuantity"
              type="number"
              min="0"
              class="form-control form-control-lg"
              :disabled="!canAdjustStock || saving"
              @keydown.enter.prevent="validateCount"
            />
          </div>
          <div class="col-md-4">
            <button
              type="button"
              class="btn btn-primary btn-lg w-100"
              :disabled="!canValidate"
              @click="validateCount"
            >
              Valider l'inventaire
            </button>
          </div>
        </div>
      </div>
    </div>

    <p v-if="lastDelta != null" class="text-muted small mb-0">
      Dernier ajustement : {{ lastDelta > 0 ? '+' : '' }}{{ lastDelta }} unité(s).
    </p>
  </div>
</template>
