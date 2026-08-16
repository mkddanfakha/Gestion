<template>
  <AppLayout>
    <PurchaseOrderForm
      mode="create"
      :suppliers="suppliers"
      :products="products"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PurchaseOrderForm from '@/components/purchaseOrders/PurchaseOrderForm.vue'
import type { PurchaseOrderFormProduct, PurchaseOrderFormSupplier } from '@/composables/usePurchaseOrderForm'

defineProps<{
  suppliers: PurchaseOrderFormSupplier[]
  products: PurchaseOrderFormProduct[]
}>()
</script>
