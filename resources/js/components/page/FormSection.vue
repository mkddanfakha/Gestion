<script setup lang="ts">
defineProps<{
  title?: string
  icon?: string
}>()
</script>

<template>
  <section class="form-section card">
    <div
      v-if="title || $slots.actions"
      class="card-header form-section__header d-flex flex-wrap justify-content-between align-items-center gap-2"
    >
      <h2 v-if="title" class="card-title h5 mb-0">
        <i v-if="icon" :class="[icon, 'me-2']" aria-hidden="true"></i>
        {{ title }}
      </h2>
      <div v-if="$slots.actions" class="form-section__actions">
        <slot name="actions" />
      </div>
    </div>

    <div class="card-body" :class="{ 'p-0': $slots.raw }">
      <slot name="raw" />
      <slot />
    </div>
  </section>
</template>
