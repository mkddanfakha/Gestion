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
