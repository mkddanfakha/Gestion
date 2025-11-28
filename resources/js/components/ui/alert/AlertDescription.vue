<script setup lang="ts">
interface Props {
  class?: string;
}

defineProps<Props>();
</script>

<template>
  <div class="alert-description">
    <slot />
  </div>
</template>