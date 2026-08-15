<script setup lang="ts">
withDefaults(
  defineProps<{
    primaryFirst?: boolean
  }>(),
  {
    primaryFirst: true,
  },
)
</script>

<template>
  <div
    class="form-actions-bar"
    :class="{ 'form-actions-bar--primary-first': primaryFirst }"
  >
    <slot />
  </div>
</template>
