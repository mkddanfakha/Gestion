<template>
  <AppLayout>
    <PurchaseOrderForm
      mode="create"
      :suppliers="suppliers"
      :products="products"
      :attachment-config="attachmentConfig"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PurchaseOrderForm from '@/components/purchaseOrders/PurchaseOrderForm.vue'
import type { PurchaseOrderFormProduct, PurchaseOrderFormSupplier } from '@/composables/usePurchaseOrderForm'
import type { AttachmentConfig } from '@/types/attachment'

defineProps<{
  suppliers: PurchaseOrderFormSupplier[]
  products: PurchaseOrderFormProduct[]
  attachmentConfig: AttachmentConfig
}>()
</script>
