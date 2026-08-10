<template>
  <AppLayout>
    <SaleForm mode="edit" :sale="sale" :customers="customers" :products="products" />
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SaleForm from '@/components/sales/SaleForm.vue'
import type { SaleFormCustomer, SaleFormProduct, SaleFormSale } from '@/composables/useSaleForm'

defineProps<{
  sale: SaleFormSale
  customers: SaleFormCustomer[]
  products: SaleFormProduct[]
}>()
</script>
