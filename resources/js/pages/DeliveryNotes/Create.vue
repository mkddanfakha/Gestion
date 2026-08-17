<template>
  <AppLayout>
    <DeliveryNoteForm
      mode="create"
      :standalone="standalone"
      :suppliers="suppliers"
      :products="products"
      :purchase-orders="purchaseOrders"
      :purchase-order="purchaseOrder"
      :purchase-order-receipt="purchaseOrderReceipt"
      :initial-purchase-order-id="purchaseOrder?.id ?? null"
      :attachment-config="attachmentConfig"
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
import type { AttachmentConfig } from '@/types/attachment'

defineProps<{
  suppliers: DeliveryNoteFormSupplier[]
  products: DeliveryNoteFormProduct[]
  purchaseOrders: DeliveryNoteFormPurchaseOrder[]
  purchaseOrder?: DeliveryNoteFormPurchaseOrder | null
  purchaseOrderReceipt?: PurchaseOrderReceiptSummary | null
  standalone?: boolean
  attachmentConfig: AttachmentConfig
}>()
</script>
