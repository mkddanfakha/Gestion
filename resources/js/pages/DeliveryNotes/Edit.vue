<template>
  <AppLayout>
    <DeliveryNoteForm
      mode="edit"
      :delivery-note="deliveryNote"
      :suppliers="suppliers"
      :products="products"
      :purchase-orders="purchaseOrders"
      :purchase-order-receipt="purchaseOrderReceipt"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import DeliveryNoteForm from '@/components/deliveryNotes/DeliveryNoteForm.vue'
import type { PurchaseOrderReceiptSummary } from '@/composables/usePurchaseOrderReceipt'
import type {
  DeliveryNoteFormDeliveryNote,
  DeliveryNoteFormProduct,
  DeliveryNoteFormPurchaseOrder,
  DeliveryNoteFormSupplier,
} from '@/composables/useDeliveryNoteForm'

defineProps<{
  deliveryNote: DeliveryNoteFormDeliveryNote
  suppliers: DeliveryNoteFormSupplier[]
  products: DeliveryNoteFormProduct[]
  purchaseOrders: DeliveryNoteFormPurchaseOrder[]
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null
}>()
</script>
