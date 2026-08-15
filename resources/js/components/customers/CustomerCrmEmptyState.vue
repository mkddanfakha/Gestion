<template>
  <div class="customer-crm-empty text-center py-5">
    <i :class="['bi', icon, 'customer-crm-empty__icon']"></i>
    <p class="customer-crm-empty__message mb-0">{{ message }}</p>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  message: string
  icon?: string
}>()
</script>
