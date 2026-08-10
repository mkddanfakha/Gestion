import { notificationConfig } from '../config/notification.config'
import type { Notification } from '../types'
import type { NotificationEffectivePreferences } from '../types/NotificationPreference'
import { logNotificationClientError } from './NotificationClientLogService'

/** Notification API navigateur — aucune dépendance Pinia. */
export class NotificationBrowserService {
    isSupported(): boolean {
        return typeof window !== 'undefined' && 'Notification' in window
    }

    isEnabled(preferences: NotificationEffectivePreferences | null): boolean {
        return preferences?.browser_enabled ?? false
    }

    getPermission(): NotificationPermission | 'unsupported' {
        if (!this.isSupported()) return 'unsupported'
        return Notification.permission
    }

    async requestPermission(): Promise<NotificationPermission | 'unsupported'> {
        if (!this.isSupported()) return 'unsupported'
        if (Notification.permission === 'granted') return 'granted'
        if (Notification.permission === 'denied') return 'denied'
        return Notification.requestPermission()
    }

    show(notification: Notification, preferences: NotificationEffectivePreferences | null): void {
        if (!this.isEnabled(preferences)) return
        if (!this.isSupported() || Notification.permission !== 'granted') return

        try {
            const browserNotification = new Notification(notification.title, {
                body: notification.description,
                tag: notification.id,
            })

            browserNotification.onclick = () => {
                window.focus()
                if (notification.url) {
                    notificationConfig.navigate(notification.url)
                }
                browserNotification.close()
            }
        } catch (error) {
            void logNotificationClientError('browser', 'Impossible d\'afficher la notification navigateur', {
                notification_id: notification.id,
                error: error instanceof Error ? error.message : String(error),
            })
        }
    }
}

let serviceInstance = new NotificationBrowserService()

export function getNotificationBrowserService(): NotificationBrowserService {
    return serviceInstance
}

export function setNotificationBrowserService(service: NotificationBrowserService): void {
    serviceInstance = service
}
