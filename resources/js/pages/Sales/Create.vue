<template>
  <AppLayout>
    <SaleForm mode="create" :customers="customers" :products="products" />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SaleForm from '@/components/sales/SaleForm.vue'
import type { SaleFormCustomer, SaleFormProduct } from '@/composables/useSaleForm'

defineProps<{
  customers: SaleFormCustomer[]
  products: SaleFormProduct[]
}>()
</script>
