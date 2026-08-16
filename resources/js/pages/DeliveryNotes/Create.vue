<template>
  <AppLayout>
    <DeliveryNoteForm
      mode="create"
      :suppliers="suppliers"
      :products="products"
      :purchase-orders="purchaseOrders"
      :purchase-order="purchaseOrder"
      :purchase-order-receipt="purchaseOrderReceipt"
      :initial-purchase-order-id="purchaseOrder?.id ?? null"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import DeliveryNoteForm from '@/components/deliveryNotes/DeliveryNoteForm.vue'
import type { PurchaseOrderReceiptSummary } from '@/composables/usePurchaseOrderReceipt'
import type {
  DeliveryNoteFormProduct,
  DeliveryNoteFormPurchaseOrder,
  DeliveryNoteFormSupplier,
} from '@/composables/useDeliveryNoteForm'

defineProps<{
  suppliers: DeliveryNoteFormSupplier[]
  products: DeliveryNoteFormProduct[]
  purchaseOrders: DeliveryNoteFormPurchaseOrder[]
  purchaseOrder?: DeliveryNoteFormPurchaseOrder | null
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null
}>()
</script>
