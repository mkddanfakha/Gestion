<template>
  <div
    v-if="show && item"
    ref="modalRootRef"
    class="modal fade show d-block"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
  >
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form @submit.prevent="submit">
          <div class="modal-header">
            <h5 class="modal-title">Quantité comptée</h5>
            <button type="button" class="btn-close" aria-label="Fermer" @click="requestClose"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2 fw-semibold">{{ item.product.name }}</div>
            <div class="small text-muted mb-3">Stock au démarrage : {{ item.stock_snapshot }}</div>
            <label class="form-label" for="inventory-quantity-input">Quantité comptée</label>
            <input
              id="inventory-quantity-input"
              ref="inputRef"
              v-model.number="quantity"
              type="number"
              min="0"
              step="1"
              class="form-control form-control-lg"
              required
            >
            <div class="form-text">0 est une quantité valide. Laissez vide uniquement via annulation.</div>
            <div v-if="errorMessage" class="alert alert-danger mt-3 mb-0">{{ errorMessage }}</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="requestClose">Annuler</button>
            <button type="submit" class="btn btn-primary" :disabled="saving">
              <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
              Enregistrer
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div v-if="show && item" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { releaseInventoryModalFocus } from '@/utils/inventoryModalFocus'
import type { InventoryCountingItem } from '@/utils/inventoryCounting'

const props = defineProps<{
  show: boolean
  item: InventoryCountingItem | null
  saving?: boolean
  errorMessage?: string
}>()

const emit = defineEmits<{
  close: []
  save: [quantity: number]
}>()

const quantity = ref<number>(0)
const inputRef = ref<HTMLInputElement | null>(null)
const modalRootRef = ref<HTMLElement | null>(null)

watch(
  () => props.show,
  (visible) => {
    if (!visible) {
      releaseInventoryModalFocus(modalRootRef.value)
    }
  },
  { flush: 'sync' },
)

watch(
  () => [props.show, props.item] as const,
  async ([show, item]) => {
    if (!show || !item) {
      return
    }

    quantity.value = item.quantity_counted ?? 0
    await nextTick()
    requestAnimationFrame(() => {
      inputRef.value?.focus()
      inputRef.value?.select()
    })
  },
)

function requestClose(): void {
  releaseInventoryModalFocus(modalRootRef.value)
  emit('close')
}

function submit(): void {
  if (quantity.value < 0 || !Number.isInteger(quantity.value)) {
    return
  }

  releaseInventoryModalFocus(modalRootRef.value)
  emit('save', quantity.value)
}
</script>
