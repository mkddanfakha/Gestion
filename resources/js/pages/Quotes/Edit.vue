<template>
  <AppLayout>
    <QuoteForm
      mode="edit"
      :quote="quote"
      :customers="customers"
      :products="products"
      :attachment-config="attachmentConfig"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import QuoteForm from '@/components/quotes/QuoteForm.vue'
import type { QuoteFormCustomer, QuoteFormProduct, QuoteFormQuote } from '@/composables/useQuoteForm'
import type { AttachmentConfig } from '@/types/attachment'

defineProps<{
  quote: QuoteFormQuote
  customers: QuoteFormCustomer[]
  products: QuoteFormProduct[]
  attachmentConfig: AttachmentConfig
}>()
</script>
