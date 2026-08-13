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
