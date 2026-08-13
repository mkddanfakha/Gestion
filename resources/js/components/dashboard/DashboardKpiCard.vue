<template>
  <component
    :is="href ? Link : 'div'"
    :href="href"
    class="dashboard-kpi"
    :class="[`dashboard-kpi--${kpi.tone}`, { 'dashboard-kpi--link': !!href }]"
  >
    <div class="dashboard-kpi__top">
      <span class="dashboard-kpi__label">{{ kpi.label }}</span>
      <span class="dashboard-kpi__icon" aria-hidden="true">
        <i :class="['bi', kpi.icon]"></i>
      </span>
    </div>
    <div class="dashboard-kpi__value">{{ formattedValue }}</div>
    <div v-if="kpi.change" class="dashboard-kpi__change" :class="changeClass">
      <i :class="['bi', kpi.change.direction === 'up' ? 'bi-arrow-up-short' : 'bi-arrow-down-short']"></i>
      <span>{{ kpi.change.value }} % vs période précédente</span>
    </div>
    <div v-else class="dashboard-kpi__change dashboard-kpi__change--muted">
      Période : {{ periodLabel }}
    </div>
  </component>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import type { DashboardKpi } from '@/types/dashboard'
import { formatKpiValue } from '@/utils/dashboardFormatters'

const props = defineProps<{
  kpi: DashboardKpi
  periodLabel: string
}>()

const formattedValue = computed(() => formatKpiValue(props.kpi.value, props.kpi.format))

const changeClass = computed(() =>
  props.kpi.change?.direction === 'up' ? 'dashboard-kpi__change--up' : 'dashboard-kpi__change--down',
)

const href = computed(() => {
  if (!props.kpi.href) return null
  return route(props.kpi.href, props.kpi.href_params ?? {})
})
</script>
