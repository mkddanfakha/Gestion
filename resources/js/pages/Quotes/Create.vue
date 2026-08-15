<template>
  <AppLayout>
    <QuoteForm
      mode="create"
      :customers="customers"
      :products="products"
      :initial-customer-id="initialCustomerId"
    />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import QuoteForm from '@/components/quotes/QuoteForm.vue'
import type { QuoteFormCustomer, QuoteFormProduct } from '@/composables/useQuoteForm'

defineProps<{
  customers: QuoteFormCustomer[]
  products: QuoteFormProduct[]
  initialCustomerId?: number | null
}>()
</script>
