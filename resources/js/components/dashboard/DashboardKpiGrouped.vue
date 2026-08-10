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
.kpi-grouped {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.kpi-grouped__section {
  border: 1px solid #e2e8f0;
  border-radius: 1rem;
  background: #f8fafc;
  overflow: hidden;
}

.kpi-grouped__header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  color: #334155;
}

.kpi-grouped__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  flex: 1;
}

.kpi-grouped__count {
  font-size: 0.75rem;
  color: #94a3b8;
}

.kpi-grouped__grid {
  display: grid;
  gap: 0.75rem;
  padding: 0.75rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.kpi-grouped__card {
  min-width: 0;
}

.kpi-grouped__card :deep(.dashboard-kpi) {
  border: none;
  box-shadow: none;
  background: #fff;
  height: 100%;
}

@media (min-width: 992px) {
  .kpi-grouped__grid--cols-1 {
    grid-template-columns: 1fr;
  }

  .kpi-grouped__grid--cols-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .kpi-grouped__grid--cols-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .kpi-grouped__grid--cols-4 {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

:global(.dark) .kpi-grouped__section {
  background: #1e293b;
  border-color: #334155;
}

:global(.dark) .kpi-grouped__header {
  background: #0f172a;
  border-color: #334155;
  color: #e2e8f0;
}
</style>
