<script setup lang="ts">
import NotificationIcon from './NotificationIcon.vue'
import { useNotificationPreferences } from '../composables/useNotificationPreferences'
import { useNotifications } from '../composables/useNotifications'
import { useNotificationUi } from '../composables/useNotificationUi'
import type { NotificationToast } from '../types'
import { X } from 'lucide-vue-next'
import { onMounted, onUnmounted } from 'vue'

const props = defineProps<{
    toast: NotificationToast
}>()

const emit = defineEmits<{
    close: []
    view: []
}>()

const { navigate } = useNotifications()
const { effective } = useNotificationPreferences()
const { texts } = useNotificationUi()

let timer: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
    const duration =
        props.toast.duration ??
        effective.value?.toast_durations[props.toast.priority]

    if (duration && duration > 0) {
        timer = setTimeout(() => emit('close'), duration)
    }
})

onUnmounted(() => {
    if (timer) clearTimeout(timer)
})

function view() {
    if (props.toast.url) {
        navigate(props.toast.url)
    }
    emit('view')
}
</script>

<template>
    <div class="nc-toast" :class="`nc-toast--${toast.priority}`" role="alert">
        <NotificationIcon
            :type="toast.priority"
            :priority="toast.priority"
            :size="20"
        />
        <div class="nc-toast__content">
            <p class="nc-toast__title">{{ toast.title }}</p>
            <p class="nc-toast__desc">{{ toast.description }}</p>
            <div class="nc-toast__actions">
                <button
                    v-if="toast.url"
                    type="button"
                    class="nc-toast__btn nc-toast__btn--primary"
                    @click="view"
                >
                    {{ texts.toastView }}
                </button>
                <button type="button" class="nc-toast__btn" @click="emit('close')">
                    {{ texts.toastClose }}
                </button>
            </div>
        </div>
        <button type="button" class="nc-toast__close" aria-label="Fermer" @click="emit('close')">
            <X :size="16" />
        </button>
    </div>
</template>
