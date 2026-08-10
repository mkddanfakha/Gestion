import { onMounted, onUnmounted } from 'vue'
import type { Notification, RealtimeProvider } from '../types'
import { useNotificationStore } from '../store/notificationStore'

/**
 * Connecte un provider temps réel au store — sans connaître Pusher/Reverb/SSE.
 */
export function useNotificationRealtime(provider: RealtimeProvider | null | undefined): void {
    const store = useNotificationStore()
    let unsubscribe: (() => void) | undefined

    onMounted(() => {
        if (!provider) return

        unsubscribe = provider.subscribe((payload) => {
            const notification = provider.normalize?.(payload) ?? (payload as Notification)
            if (notification?.id) {
                store.add(notification)
            }
        })
    })

    onUnmounted(() => {
        unsubscribe?.()
    })
}

/** Variante impérative pour intégrations custom. */
export function connectNotificationRealtime(provider: RealtimeProvider): () => void {
    const store = useNotificationStore()

    return provider.subscribe((payload) => {
        const notification = provider.normalize?.(payload)
        if (notification) {
            store.add(notification)
        }
    })
}
