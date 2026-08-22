<template>
  <div v-if="scan" class="inventory-last-scan border rounded p-3">
    <div class="d-flex align-items-start gap-3">
      <img
        v-if="scan.product.image_url"
        :src="scan.product.image_url"
        :alt="scan.product.name"
        class="inventory-last-scan__image rounded"
      >
      <div v-else class="inventory-last-scan__placeholder rounded d-flex align-items-center justify-content-center">
        <i class="bi bi-box-seam fs-4 inventory-last-scan__meta"></i>
      </div>
      <div class="flex-grow-1 min-w-0">
        <div class="inventory-last-scan__status mb-1">
          <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
          Produit compté
        </div>
        <div class="fw-semibold text-truncate">{{ scan.product.name }}</div>
        <div class="small inventory-last-scan__meta">Code-barres : {{ scan.product.barcode ?? '—' }}</div>
        <div class="mt-2">Quantité comptée : <strong>{{ scan.quantityCounted }}</strong></div>
        <div v-if="scan.increment !== null" class="badge inventory-last-scan__increment mt-2">
          +{{ scan.increment }}
        </div>
        <div v-if="scan.scannedAt" class="small inventory-last-scan__meta mt-1">{{ scan.scannedAt }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
export type InventoryLastScanData = {
  product: {
    name: string
    barcode: string | null
    image_url?: string | null
  }
  quantityCounted: number
  increment: number | null
  scannedAt?: string
}

defineProps<{
  scan: InventoryLastScanData | null
}>()
</script>

<style scoped>
.inventory-last-scan__image,
.inventory-last-scan__placeholder {
  width: 64px;
  height: 64px;
  object-fit: cover;
  flex-shrink: 0;
}
</style>
