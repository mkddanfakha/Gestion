<script setup lang="ts">
import { useNotificationUi } from '../composables/useNotificationUi'
import type { NotificationPriority } from '../types'

defineProps<{
    priority: NotificationPriority
}>()

const { priorityLabels } = useNotificationUi()
</script>

<template>
    <span class="nc-priority-badge" :class="`nc-priority-badge--${priority}`">
        {{ priorityLabels[priority] }}
    </span>
</template>
