import { getRealtimeProvider } from '../config/notification.config'
import type { Notification } from '../types'
import type { RealtimeProvider } from '../types/NotificationSettings'

/** Abstraction temps réel — le module ne connaît pas Pusher/Reverb/SSE. */
export class NotificationRealtimeService {
    constructor(private readonly provider: RealtimeProvider | null) {}

    connect(onMessage: (payload: unknown) => void): () => void {
        if (!this.provider) {
            return () => {}
        }

        return this.provider.subscribe(onMessage)
    }

    normalize(payload: unknown): Notification | null {
        if (!this.provider?.normalize) {
            return (payload as Notification)?.id ? (payload as Notification) : null
        }

        return this.provider.normalize(payload)
    }
}

let serviceInstance: NotificationRealtimeService | null = null

export function getNotificationRealtimeService(): NotificationRealtimeService {
    if (!serviceInstance) {
        serviceInstance = new NotificationRealtimeService(getRealtimeProvider())
    }

    return serviceInstance
}

export function setNotificationRealtimeService(service: NotificationRealtimeService): void {
    serviceInstance = service
}

export function refreshNotificationRealtimeService(): void {
    serviceInstance = new NotificationRealtimeService(getRealtimeProvider())
}
