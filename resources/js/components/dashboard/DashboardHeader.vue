<template>
  <section class="dashboard-header">
    <div class="dashboard-header__intro">
      <p class="dashboard-header__eyebrow">Tableau de bord</p>
      <h1 class="dashboard-header__title">Bonjour, {{ userName }}</h1>
      <p class="dashboard-header__subtitle">Voici un aperçu de votre activité{{ periodLabel ? ` — ${periodLabel}` : '' }}.</p>
      <p v-if="refreshedAt" class="dashboard-header__meta">
        Dernière actualisation : {{ refreshedAt }}
      </p>
    </div>

    <div class="dashboard-header__toolbar">
      <label class="visually-hidden" for="dashboard-period">Période</label>
      <select
        id="dashboard-period"
        class="form-select dashboard-header__select"
        :value="period"
        :disabled="loading"
        @change="onPeriodChange"
      >
        <option v-for="option in periodOptions" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>

      <button
        type="button"
        class="btn btn-outline-primary dashboard-header__refresh"
        :disabled="loading"
        aria-label="Actualiser le tableau de bord"
        @click="$emit('refresh')"
      >
        <span v-if="loading" class="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
        <i v-else class="bi bi-arrow-clockwise dashboard-header__refresh-icon" aria-hidden="true" />
        <span class="dashboard-header__refresh-label">Actualiser</span>
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { dashboardPeriodOptions } from '@/utils/dashboardFormatters'

defineProps<{
  userName: string
  period: string
  periodLabel: string
  refreshedAt: string
  loading?: boolean
}>()

const emit = defineEmits<{
  refresh: []
  'update:period': [value: string]
}>()

const periodOptions = dashboardPeriodOptions

function onPeriodChange(event: Event) {
  const target = event.target as HTMLSelectElement
  emit('update:period', target.value)
}
</script>

<style scoped>
.dashboard-header {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.dashboard-header__eyebrow {
  margin: 0 0 0.25rem;
  font-size: 0.78rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #64748b;
  font-weight: 600;
}

.dashboard-header__title {
  margin: 0;
  font-size: clamp(1.5rem, 4vw, 2rem);
  font-weight: 700;
  color: #0f172a;
}

.dashboard-header__subtitle,
.dashboard-header__meta {
  margin: 0.35rem 0 0;
  color: #64748b;
  font-size: 0.95rem;
}

.dashboard-header__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  width: 100%;
}

.dashboard-header__select {
  flex: 1 1 11rem;
  min-width: 11rem;
  max-width: 100%;
  border-radius: 0.75rem;
  border-color: #e2e8f0;
  background-color: #fff;
}

.dashboard-header__refresh {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  border-radius: 0.75rem;
  padding: 0.5rem 1rem;
  white-space: nowrap;
  line-height: 1.25;
}

.dashboard-header__refresh-icon {
  font-size: 1rem;
  line-height: 1;
}

.dashboard-header__refresh-label {
  font-weight: 500;
}

@media (min-width: 768px) {
  .dashboard-header__toolbar {
    justify-content: flex-end;
    flex-wrap: nowrap;
  }

  .dashboard-header__select {
    flex: 0 0 auto;
    width: auto;
  }
}

:global(.dark) .dashboard-header__title {
  color: #f8fafc;
}

:global(.dark) .dashboard-header__subtitle,
:global(.dark) .dashboard-header__meta,
:global(.dark) .dashboard-header__eyebrow {
  color: #94a3b8;
}

:global(.dark) .dashboard-header__select {
  background-color: #0f172a;
  border-color: #334155;
  color: #e2e8f0;
}
</style>
