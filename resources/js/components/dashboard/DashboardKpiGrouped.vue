<template>
  <div class="kpi-grouped">
    <section v-for="group in groups" :key="group.title" class="kpi-grouped__section">
      <header class="kpi-grouped__header">
        <i :class="['bi', group.icon]" />
        <h3 class="kpi-grouped__title">{{ group.title }}</h3>
        <span class="kpi-grouped__count">{{ group.kpis.length }} indicateur(s)</span>
      </header>
      <div
        class="kpi-grouped__grid"
        :class="`kpi-grouped__grid--cols-${Math.min(group.kpis.length, 4)}`"
      >
        <DashboardKpiCard
          v-for="kpi in group.kpis"
          :key="kpi.key"
          :kpi="kpi"
          :period-label="periodLabel"
          class="kpi-grouped__card"
        />
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import DashboardKpiCard from '@/components/dashboard/DashboardKpiCard.vue'
import type { DashboardKpi } from '@/types/dashboard'

const props = defineProps<{
  kpis: DashboardKpi[]
  periodLabel: string
}>()

const groupConfig = [
  {
    title: 'Finances',
    icon: 'bi-cash-stack',
    keys: ['revenue', 'remaining', 'expenses', 'net_profit'],
  },
  {
    title: 'Activité commerciale',
    icon: 'bi-graph-up',
    keys: ['sales_count', 'customers'],
  },
  {
    title: 'Stock & catalogue',
    icon: 'bi-box-seam',
    keys: ['low_stock', 'products'],
  },
]

const groups = computed(() =>
  groupConfig
    .map((group) => ({
      title: group.title,
      icon: group.icon,
      kpis: group.keys
        .map((key) => props.kpis.find((kpi) => kpi.key === key))
        .filter(Boolean) as DashboardKpi[],
    }))
    .filter((group) => group.kpis.length > 0),
)
</script>

<style scoped>
.kpi-grouped__card {
  min-width: 0;
}
</style>
