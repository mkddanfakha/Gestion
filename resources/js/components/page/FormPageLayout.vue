<script setup lang="ts">
withDefaults(
  defineProps<{
    stickyActions?: boolean
    wide?: boolean
  }>(),
  {
    stickyActions: true,
    wide: false,
  },
)
</script>

<template>
  <div
    class="form-page"
    :class="{
      'form-page--sticky-actions': stickyActions,
      'form-page--wide': wide,
    }"
  >
    <slot />
  </div>
</template>
