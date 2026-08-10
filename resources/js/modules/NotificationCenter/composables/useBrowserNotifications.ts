import { getNotificationBrowserService } from '../services/NotificationBrowserService'
import type { Notification } from '../types'
import { useNotificationPreferences } from './useNotificationPreferences'

/** Notifications système du navigateur. */
export function useBrowserNotifications() {
    const service = getNotificationBrowserService()
    const { effective } = useNotificationPreferences()

    return {
        isSupported: () => service.isSupported(),
        getPermission: () => service.getPermission(),
        requestPermission: () => service.requestPermission(),
        show: (notification: Notification) => service.show(notification, effective.value),
        isEnabled: () => service.isEnabled(effective.value),
    }
}
