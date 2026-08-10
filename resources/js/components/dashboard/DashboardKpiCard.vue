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

<style scoped>
.dashboard-kpi {
  display: block;
  height: 100%;
  padding: 1.1rem 1.15rem;
  border: 1px solid #e2e8f0;
  border-radius: 1rem;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
  text-decoration: none;
  color: inherit;
}

.dashboard-kpi:hover,
.dashboard-kpi:focus,
.dashboard-kpi:focus-visible,
.dashboard-kpi:visited {
  text-decoration: none;
  color: inherit;
}

.dashboard-kpi__label,
.dashboard-kpi__value,
.dashboard-kpi__change {
  text-decoration: none;
}

.dashboard-kpi--link:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
  border-color: #cbd5e1;
}

.dashboard-kpi__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}

.dashboard-kpi__label {
  font-size: 0.88rem;
  color: #64748b;
  font-weight: 600;
}

.dashboard-kpi__icon {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #f8fafc;
  color: #475569;
}

.dashboard-kpi--primary .dashboard-kpi__icon { background: #ecfdf5; color: #059669; }
.dashboard-kpi--success .dashboard-kpi__icon { background: #eff6ff; color: #2563eb; }
.dashboard-kpi--warning .dashboard-kpi__icon { background: #fffbeb; color: #d97706; }
.dashboard-kpi--danger .dashboard-kpi__icon { background: #fef2f2; color: #dc2626; }
.dashboard-kpi--info .dashboard-kpi__icon { background: #ecfeff; color: #0891b2; }

.dashboard-kpi__value {
  font-size: clamp(1.35rem, 3vw, 1.75rem);
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.dashboard-kpi__change {
  margin-top: 0.65rem;
  font-size: 0.82rem;
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
}

.dashboard-kpi__change--up { color: #059669; }
.dashboard-kpi__change--down { color: #dc2626; }
.dashboard-kpi__change--muted { color: #94a3b8; }

:global(.dark) .dashboard-kpi {
  background: #0f172a;
  border-color: #334155;
}

:global(.dark) .dashboard-kpi__value { color: #f8fafc; }
:global(.dark) .dashboard-kpi__label { color: #94a3b8; }
</style>
