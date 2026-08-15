<template>
  <section class="customer-crm-kpis">
    <div
      v-for="item in items"
      :key="item.key"
      class="customer-crm-kpi sale-stat-card"
      :class="{ 'customer-crm-kpi--alert': item.alert }"
    >
      <div class="sale-stat-card__icon">
        <i :class="['bi', item.icon]"></i>
      </div>
      <div>
        <div
          class="sale-stat-card__value"
          :class="{ 'sale-stat-card__value--sm': item.compact }"
        >
          {{ item.value }}
        </div>
        <div class="sale-stat-card__label">{{ item.label }}</div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { formatCurrency } from '@/utils/currencyFormatter'

export interface CustomerCrmSummaryData {
  orders_count: number
  total_purchased: number
  total_paid: number
  remaining_balance: number
}

const props = defineProps<{
  summary: CustomerCrmSummaryData
}>()

const items = computed(() => [
  {
    key: 'orders',
    label: 'Commandes',
    value: String(props.summary.orders_count),
    icon: 'bi-bag-check',
    compact: false,
    alert: false,
  },
  {
    key: 'purchased',
    label: 'Total acheté',
    value: formatCurrency(props.summary.total_purchased),
    icon: 'bi-cart-check',
    compact: true,
    alert: false,
  },
  {
    key: 'paid',
    label: 'Total payé',
    value: formatCurrency(props.summary.total_paid),
    icon: 'bi-cash-coin',
    compact: true,
    alert: false,
  },
  {
    key: 'remaining',
    label: 'Solde restant',
    value: formatCurrency(props.summary.remaining_balance),
    icon: 'bi-exclamation-circle',
    compact: true,
    alert: props.summary.remaining_balance > 0,
  },
])
</script>
