import { onUnmounted } from 'vue'
import { NotificationRealtimeService } from '../services/NotificationRealtimeService'
import type { RealtimeProvider } from '../types/NotificationSettings'
import { useNotifications } from './useNotifications'

/**
 * Connexion temps réel — met à jour les notifications via useNotifications.
 */
export function useNotificationRealtime(provider?: RealtimeProvider | null) {
    const { handleIncoming } = useNotifications()
    let unsubscribe: (() => void) | undefined
    let service: NotificationRealtimeService | null = null

    const connect = (customProvider?: RealtimeProvider | null) => {
        const activeProvider = customProvider ?? provider ?? null
        service = new NotificationRealtimeService(activeProvider)

        unsubscribe?.()
        unsubscribe = service.connect((payload) => {
            const notification = service!.normalize(payload)
            if (notification) {
                handleIncoming(notification)
            }
        })
    }

    const disconnect = () => {
        unsubscribe?.()
        unsubscribe = undefined
    }

    onUnmounted(disconnect)

    return { connect, disconnect }
}
