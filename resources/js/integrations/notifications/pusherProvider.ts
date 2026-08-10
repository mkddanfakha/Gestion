/**
 * Provider Pusher — couche projet, hors module NotificationCenter.
 */
import echo from '@/echo'
import type { RealtimeProvider } from '@/modules/NotificationCenter/types'
import { mapRealtimePayload, type RealtimeNotificationPayload } from './notificationMapper'

export function createPusherNotificationProvider(userId: number): RealtimeProvider {
    return {
        subscribe(onMessage) {
            if (!echo) {
                return () => {}
            }

            const channelName = `user.${userId}.notifications`
            const channel = echo.private(channelName)

            const handler = (data: { notification?: RealtimeNotificationPayload }) => {
                if (data.notification) {
                    onMessage(data.notification)
                }
            }

            channel.listen('.notification.sent', handler)

            return () => {
                try {
                    echo.leave(channelName)
                } catch {
                    // ignore
                }
            }
        },
        normalize(payload) {
            return mapRealtimePayload(payload as RealtimeNotificationPayload)
        },
    }
}
