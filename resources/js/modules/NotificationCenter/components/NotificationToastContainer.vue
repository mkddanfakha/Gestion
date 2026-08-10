<script setup lang="ts">
import NotificationToast from './NotificationToast.vue'
import { useNotificationPreferences } from '../composables/useNotificationPreferences'
import { useNotifications } from '../composables/useNotifications'
import { computed } from 'vue'

const { toasts, dismissToast, ui } = useNotifications()
const { effective } = useNotificationPreferences()

const containerClass = computed(() => {
    const position = effective.value?.toast_position ?? 'bottom-right'
    const map: Record<string, string> = {
        'bottom-right': 'nc-toast-container--br',
        'bottom-left': 'nc-toast-container--bl',
        'top-right': 'nc-toast-container--tr',
        'top-left': 'nc-toast-container--tl',
    }
    return ['nc-toast-container', map[position] ?? map['bottom-right']]
})
</script>

<template>
    <div :class="containerClass" aria-live="polite" aria-relevant="additions">
        <TransitionGroup :name="ui.animations.toast">
            <NotificationToast
                v-for="toast in toasts"
                :key="toast.id"
                :toast="toast"
                @close="dismissToast(toast.id)"
                @view="dismissToast(toast.id)"
            />
        </TransitionGroup>
    </div>
</template>

<style scoped>
.nc-toast-container--br {
    right: 1rem;
    bottom: 1rem;
}
.nc-toast-container--bl {
    left: 1rem;
    bottom: 1rem;
}
.nc-toast-container--tr {
    right: 1rem;
    top: 1rem;
}
.nc-toast-container--tl {
    left: 1rem;
    top: 1rem;
}
</style>
