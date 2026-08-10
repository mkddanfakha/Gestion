import { notificationConfig } from '../config/notification.config'
import type { NotificationPreferencesPayload, NotificationUserPreferences } from '../types'

/** Appels HTTP préférences — aucune logique métier. */
export class NotificationPreferenceApi {
    private url(): string {
        return notificationConfig.routes.preferences
    }

    private async request<T>(init: RequestInit = {}): Promise<T> {
        const response = await fetch(this.url(), {
            ...init,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': notificationConfig.getCsrfToken(),
                ...(init.headers ?? {}),
            },
        })

        if (!response.ok) {
            throw new Error(`NotificationPreferenceApi: ${response.status}`)
        }

        return response.json() as Promise<T>
    }

    async fetch(): Promise<NotificationPreferencesPayload> {
        return this.request<NotificationPreferencesPayload>()
    }

    async update(
        data: Partial<NotificationUserPreferences>,
    ): Promise<NotificationPreferencesPayload & { success?: boolean }> {
        return this.request({ method: 'PUT', body: JSON.stringify(data) })
    }
}

let apiInstance = new NotificationPreferenceApi()

export function getNotificationPreferenceApi(): NotificationPreferenceApi {
    return apiInstance
}

export function setNotificationPreferenceApi(api: NotificationPreferenceApi): void {
    apiInstance = api
}
