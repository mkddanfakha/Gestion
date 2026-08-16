<template>
  <AppLayout>
    <QuoteForm
      mode="create"
      :customers="customers"
      :products="products"
      :initial-customer-id="initialCustomerId"
      :attachment-config="attachmentConfig"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import QuoteForm from '@/components/quotes/QuoteForm.vue'
import type { QuoteFormCustomer, QuoteFormProduct } from '@/composables/useQuoteForm'
import type { AttachmentConfig } from '@/types/attachment'

defineProps<{
  customers: QuoteFormCustomer[]
  products: QuoteFormProduct[]
  initialCustomerId?: number | null
  attachmentConfig: AttachmentConfig
}>()
</script>
