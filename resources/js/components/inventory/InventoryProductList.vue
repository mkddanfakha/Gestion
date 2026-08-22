<template>
  <div class="inventory-product-list">
    <div class="row g-3 mb-3">
      <div class="col-12 col-lg-7">
        <label class="form-label" :for="searchInputId">Recherche</label>
        <input
          :id="searchInputId"
          :value="searchQuery"
          type="search"
          class="form-control"
          placeholder="Rechercher un produit ou scanner son code-barres…"
          @input="$emit('update:searchQuery', ($event.target as HTMLInputElement).value)"
          @focus="$emit('search-focus')"
          @blur="$emit('search-blur')"
        >
      </div>
      <div class="col-12 col-lg-5">
        <label class="form-label" :for="filterInputId">Filtre</label>
        <select
          :id="filterInputId"
          :value="filterValue"
          class="form-select"
          @change="$emit('update:filterValue', ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="option in filterOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="items.length === 0" class="text-center text-muted py-5">
      <i class="bi bi-inbox fs-1 d-block mb-2"></i>
      Aucun produit trouvé.
    </div>

    <div v-else class="d-none d-lg-block table-responsive">
      <table class="table table-hover align-middle mb-0 inventory-product-list__table">
        <thead>
          <tr>
            <th>Produit</th>
            <th>Code-barres</th>
            <th class="text-end">Stock initial</th>
            <th class="text-end">Compté</th>
            <th class="text-end">Écart</th>
            <th>Statut</th>
            <th v-if="showManualEdit" class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in items"
            :key="item.id"
            class="inventory-product-list__row"
            :class="rowClass(item)"
          >
            <td>
              <div class="d-flex align-items-center gap-2">
                <img
                  v-if="item.product.image_url"
                  :src="item.product.image_url"
                  :alt="item.product.name"
                  class="rounded inventory-product-list__thumb"
                >
                <span>{{ item.product.name }}</span>
              </div>
            </td>
            <td class="font-monospace small inventory-product-list__meta">{{ item.product.barcode ?? '—' }}</td>
            <td class="text-end">{{ item.stock_snapshot }}</td>
            <td class="text-end fw-semibold">{{ countedLabel(item.quantity_counted) }}</td>
            <td class="text-end">
              <span :class="presentation(item).textClass">{{ differenceLabel(item) }}</span>
            </td>
            <td>
              <span class="badge" :class="presentation(item).badgeClass">{{ presentation(item).label }}</span>
            </td>
            <td v-if="showManualEdit" class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary" @click="$emit('edit-quantity', item)">
                Quantité
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="d-lg-none">
      <div
        v-for="item in items"
        :key="`mobile-${item.id}`"
        class="inventory-product-list__card border rounded p-3 mb-3"
        :class="rowClass(item, true)"
      >
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div class="fw-semibold">{{ item.product.name }}</div>
          <span class="badge" :class="presentation(item).badgeClass">{{ presentation(item).label }}</span>
        </div>
        <div class="small inventory-product-list__meta mb-2 font-monospace">{{ item.product.barcode ?? '—' }}</div>
        <div class="row g-2 small">
          <div class="col-6">Stock initial : <strong>{{ item.stock_snapshot }}</strong></div>
          <div class="col-6">Compté : <strong>{{ countedLabel(item.quantity_counted) }}</strong></div>
          <div class="col-12">Écart : <strong :class="presentation(item).textClass">{{ differenceLabel(item) }}</strong></div>
        </div>
        <button
          v-if="showManualEdit"
          type="button"
          class="btn btn-sm btn-outline-primary w-100 mt-3"
          @click="$emit('edit-quantity', item)"
        >
          Modifier la quantité
        </button>
      </div>
    </div>

    <p v-if="showVarianceHint" class="small text-muted mt-3 mb-0">
      Écart calculé par rapport au stock enregistré au démarrage de l'inventaire.
    </p>
  </div>
</template>

<script setup lang="ts">
import {
  formatCountedQuantityLabel,
  getInventoryProductRowState,
  getVariancePresentation,
  type InventoryCountingItem,
} from '@/utils/inventoryCounting'

const props = defineProps<{
  items: InventoryCountingItem[]
  searchQuery: string
  filterValue: string
  filterOptions: Array<{ value: string; label: string }>
  highlightedItemId?: number | null
  showManualEdit?: boolean
  showVarianceHint?: boolean
  searchInputId?: string
  filterInputId?: string
}>()

defineEmits<{
  'update:searchQuery': [value: string]
  'update:filterValue': [value: string]
  'edit-quantity': [item: InventoryCountingItem]
  'search-focus': []
  'search-blur': []
}>()

function presentation(item: InventoryCountingItem) {
  const difference = item.difference ?? item.difference_from_snapshot
  return getVariancePresentation(item.variance_status, difference)
}

function differenceLabel(item: InventoryCountingItem): string {
  const difference = item.difference ?? item.difference_from_snapshot
  if (difference === null) return '—'
  if (difference > 0) return `+${difference}`
  if (difference === 0) return 'Conforme'
  return `${difference}`
}

function countedLabel(quantity: number | null): string {
  return formatCountedQuantityLabel(quantity)
}

function rowClass(item: InventoryCountingItem, mobile = false): Record<string, boolean> {
  const state = getInventoryProductRowState(item, props.highlightedItemId)

  return {
    'inventory-product-list__row--counted': state === 'counted',
    'inventory-product-list__row--highlight': state === 'highlight',
    'inventory-product-list__card--counted': mobile && state === 'counted',
    'inventory-product-list__card--highlight': mobile && state === 'highlight',
  }
}
</script>

<style scoped>
.inventory-product-list__thumb {
  width: 32px;
  height: 32px;
  object-fit: cover;
}
</style>
