<template>
  <AppLayout>
    <PurchaseOrderForm
      mode="edit"
      :purchase-order="purchaseOrder"
      :suppliers="suppliers"
      :products="products"
      :has-deliveries="hasDeliveries"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PurchaseOrderForm from '@/components/purchaseOrders/PurchaseOrderForm.vue'
import type {
  PurchaseOrderFormProduct,
  PurchaseOrderFormPurchase,
  PurchaseOrderFormSupplier,
} from '@/composables/usePurchaseOrderForm'

defineProps<{
  purchaseOrder: PurchaseOrderFormPurchase
  suppliers: PurchaseOrderFormSupplier[]
  products: PurchaseOrderFormProduct[]
  hasDeliveries?: boolean
}>()
</script>
