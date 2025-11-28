<script setup lang="ts">
interface Props {
  class?: string;
}

defineProps<Props>();
</script>

<template>
  <h4 class="alert-heading mb-2">
    <slot />
  </h4>
</template>