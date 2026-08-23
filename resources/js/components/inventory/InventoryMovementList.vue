<template>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 inventory-history-table">
      <thead>
        <tr>
          <th>Produit</th>
          <th>Type</th>
          <th class="text-end">Quantité</th>
          <th>Date</th>
          <th>Utilisateur</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="movements.length === 0">
          <td colspan="5" class="text-center inventory-history-table__empty">Aucun mouvement généré.</td>
        </tr>
        <tr v-for="(movement, index) in movements" :key="`${movement.product_name}-${index}`">
          <td>{{ movement.product_name }}</td>
          <td>{{ movement.type_label ?? 'Ajustement inventaire' }}</td>
          <td class="text-end">{{ movement.quantity_label ?? formatInventorySignedQuantity(movement.quantity) }}</td>
          <td>{{ formatMovementDate(movement.created_at) }}</td>
          <td>{{ movement.user_name ?? '—' }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import {
  formatInventorySignedQuantity,
  type InventoryMovementRow,
} from '@/utils/inventoryHistory'
import { formatInventoryShortDate } from '@/utils/inventoryUi'

defineProps<{
  movements: InventoryMovementRow[]
}>()

function formatMovementDate(value?: string | null): string {
  if (!value) {
    return '—'
  }

  return formatInventoryShortDate(value)
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
</style>
