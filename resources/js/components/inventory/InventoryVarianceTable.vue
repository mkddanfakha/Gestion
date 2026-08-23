<template>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 inventory-history-table">
      <thead>
        <tr>
          <th>Produit</th>
          <th>Code-barres</th>
          <th class="text-end">Stock théorique</th>
          <th class="text-end">Compté</th>
          <th class="text-end">Écart</th>
          <th>Type</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="rows.length === 0">
          <td colspan="6" class="text-center inventory-history-table__empty">Aucun écart enregistré.</td>
        </tr>
        <tr v-for="(row, index) in rows" :key="`${row.product_name}-${index}`">
          <td>{{ row.product_name }}</td>
          <td class="font-monospace small">{{ row.barcode ?? '—' }}</td>
          <td class="text-end">{{ row.stock_snapshot }}</td>
          <td class="text-end">{{ row.quantity_counted ?? '—' }}</td>
          <td class="text-end inventory-history-table__variance">{{ row.difference_label ?? formatInventorySignedQuantity(row.difference) }}</td>
          <td>
            <span class="badge inventory-variance-badge" :class="varianceBadgeClass(row.variance_type)">
              {{ row.variance_label ?? formatInventoryVarianceType(row.variance_type) }}
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import {
  formatInventorySignedQuantity,
  formatInventoryVarianceType,
  type InventoryVarianceRow,
} from '@/utils/inventoryHistory'

defineProps<{
  rows: InventoryVarianceRow[]
}>()

function varianceBadgeClass(type?: string | null): string {
  switch (type) {
    case 'surplus':
      return 'inventory-variance-surplus'
    case 'manque':
      return 'inventory-variance-manque'
    default:
      return 'inventory-variance-conforme'
  }
}
</script>

<style scoped>
.inventory-history-table {
  color: var(--color-text-primary);
}

.inventory-history-table thead th {
  color: var(--color-text-muted);
  border-bottom-color: var(--color-border-subtle);
}

.inventory-history-table td {
  border-bottom-color: var(--color-border-subtle);
}

.inventory-history-table__empty {
  color: var(--color-text-muted);
}

.inventory-history-table__variance {
  font-weight: 600;
}
</style>
