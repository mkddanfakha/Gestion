<template>
  <div class="dashboard-panel">
    <div class="dashboard-panel__header">
      <h2 class="dashboard-panel__title">{{ title }}</h2>
      <slot name="actions" />
    </div>
    <div class="dashboard-panel__body">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<style scoped>
.dashboard-panel {
  height: 100%;
  border: 1px solid #e2e8f0;
  border-radius: 1rem;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  overflow: hidden;
}

.dashboard-panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.15rem;
  border-bottom: 1px solid #f1f5f9;
}

.dashboard-panel__title {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.dashboard-panel__body {
  padding: 1rem 1.15rem 1.15rem;
}

:global(.dark) .dashboard-panel {
  background: #0f172a;
  border-color: #334155;
}

:global(.dark) .dashboard-panel__header {
  border-bottom-color: #1e293b;
}

:global(.dark) .dashboard-panel__title {
  color: #f8fafc;
}
</style>
