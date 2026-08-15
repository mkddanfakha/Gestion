<script setup lang="ts">
import NotificationToast from './NotificationToast.vue'
import { notificationConfig } from './notification.config'
import { useNotificationStore } from './store/notificationStore'
import { storeToRefs } from 'pinia'
import { computed } from 'vue'

const store = useNotificationStore()
const { toasts, effectivePreferences } = storeToRefs(store)

const containerClass = computed(() => {
    const position = effectivePreferences.value?.toast_position ?? 'bottom-right'
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
    <Teleport to="body">
        <div :class="containerClass" aria-live="polite" aria-relevant="additions">
            <TransitionGroup :name="notificationConfig.animations.toast">
                <NotificationToast
                    v-for="toast in toasts"
                    :key="toast.id"
                    :toast="toast"
                    @close="store.dismissToast(toast.id)"
                    @view="store.dismissToast(toast.id)"
                />
            </TransitionGroup>
        </div>
    </Teleport>
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
