<script setup lang="ts">
import { notificationConfig } from './notification.config'
import type { NotificationPriority } from './types'

defineProps<{
    priority: NotificationPriority
}>()
</script>

<template>
    <span class="nc-priority-badge" :class="`nc-priority-badge--${priority}`">
        {{ notificationConfig.priorityLabels[priority] }}
    </span>
</template>
