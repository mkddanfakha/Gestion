<template>
  <AppLayout>
    <QuoteForm mode="edit" :quote="quote" :customers="customers" :products="products" />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import QuoteForm from '@/components/quotes/QuoteForm.vue'
import type { QuoteFormCustomer, QuoteFormProduct, QuoteFormQuote } from '@/composables/useQuoteForm'

defineProps<{
  quote: QuoteFormQuote
  customers: QuoteFormCustomer[]
  products: QuoteFormProduct[]
}>()
</script>
