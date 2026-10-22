<script setup lang="ts">
import { computed } from 'vue'
import {
  getPaymentStatusBadgeClass,
  getPaymentStatusIcon,
  getPaymentStatusLabel,
  type SalePaymentStatus,
} from '@/utils/salePaymentStatus'

const props = defineProps<{
  status: SalePaymentStatus | string | null | undefined
}>()

const normalizedStatus = computed((): SalePaymentStatus => {
  const value = props.status
  if (value === 'paid' || value === 'partial' || value === 'pending') {
    return value
  }

  return 'pending'
})
</script>

<template>
  <span :class="getPaymentStatusBadgeClass(normalizedStatus)">
    <i :class="[getPaymentStatusIcon(normalizedStatus), 'sale-payment-status__icon']" aria-hidden="true"></i>
    {{ getPaymentStatusLabel(normalizedStatus) }}
  </span>
</template>
