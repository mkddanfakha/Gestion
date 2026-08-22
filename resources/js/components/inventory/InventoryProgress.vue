<template>
  <div class="inventory-progress">
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
      <div class="fw-semibold">{{ title }}</div>
      <div class="text-muted small">{{ counted }} / {{ total }} produits comptés</div>
    </div>
    <div
      class="progress inventory-progress__bar"
      role="progressbar"
      :aria-valuenow="percentage"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="`${percentage} pour cent des produits comptés`"
    >
      <div class="progress-bar" :style="{ width: `${percentage}%` }">
        {{ percentage }} %
      </div>
    </div>
    <div class="inventory-progress__meta small text-muted mt-2">
      <span>{{ uncounted }} non compté(s)</span>
      <span v-if="withVariance !== undefined">{{ withVariance }} avec écart</span>
      <span v-if="conforme !== undefined">{{ conforme }} conforme(s)</span>
      <span v-if="totalUnits !== undefined">Unités comptées : {{ totalUnits }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title?: string
  counted: number
  total: number
  percentage: number
  uncounted: number
  withVariance?: number
  conforme?: number
  totalUnits?: number
}>()
</script>

<style scoped>
.inventory-progress__bar {
  height: 1.35rem;
  border-radius: 999px;
  overflow: hidden;
}

.inventory-progress__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
}
</style>
