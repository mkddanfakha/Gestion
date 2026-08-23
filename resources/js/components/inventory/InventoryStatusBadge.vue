<template>
  <span class="badge inventory-list-badge inventory-status-badge" :class="badgeClass">{{ label }}</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
  getInventoryStatusBadgeClass,
  getInventoryStatusLabel,
  getInventoryStatusListLabel,
} from '@/utils/inventoryUi'

const props = withDefaults(defineProps<{
  status: string
  compact?: boolean
}>(), {
  compact: false,
})

const label = computed(() => (
  props.compact
    ? getInventoryStatusListLabel(props.status)
    : getInventoryStatusLabel(props.status)
))
const badgeClass = computed(() => getInventoryStatusBadgeClass(props.status))
</script>
