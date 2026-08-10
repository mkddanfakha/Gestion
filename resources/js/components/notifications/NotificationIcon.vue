<script setup lang="ts">
import { notificationConfig } from './notification.config'
import type { NotificationPriority } from './types'
import { computed } from 'vue'

const props = defineProps<{
    type: string
    icon?: string
    priority: NotificationPriority
    resolved?: boolean
    size?: number
}>()

const iconComponent = computed(() => {
    if (props.icon && notificationConfig.iconMap[props.icon]) {
        return notificationConfig.iconMap[props.icon]
    }
    if (notificationConfig.iconMap[props.type]) {
        return notificationConfig.iconMap[props.type]
    }
    return notificationConfig.defaultIcon
})

const priorityClass = computed(
    () =>
        `nc-icon nc-icon--${props.priority} ${props.resolved ? 'nc-icon--resolved' : ''}`,
)
</script>

<template>
    <div class="nc-icon-wrap" :class="priorityClass" aria-hidden="true">
        <component :is="iconComponent" :size="size ?? 18" stroke-width="2" />
    </div>
</template>
