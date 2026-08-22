<template>
  <span class="badge inventory-status-badge" :class="badgeClass">{{ label }}</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { getInventoryStatusBadgeClass, getInventoryStatusLabel } from '@/utils/inventoryUi'

const props = defineProps<{
  status: string
}>()

const label = computed(() => getInventoryStatusLabel(props.status))
const badgeClass = computed(() => getInventoryStatusBadgeClass(props.status))
</script>

<style scoped>
.inventory-status-badge {
  font-weight: 600;
  letter-spacing: 0.01em;
}
</style>
