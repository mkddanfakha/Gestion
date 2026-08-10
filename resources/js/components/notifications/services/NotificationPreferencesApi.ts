import { notificationConfig } from '../notification.config'
import type { NotificationPreferencesPayload } from '../types'

export class NotificationPreferencesApi {
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
            throw new Error(`NotificationPreferencesApi: ${response.status}`)
        }

        return response.json() as Promise<T>
    }

    async fetch(): Promise<NotificationPreferencesPayload> {
        return this.request<NotificationPreferencesPayload>()
    }

    async update(
        data: Partial<NotificationPreferencesPayload['user']>,
    ): Promise<NotificationPreferencesPayload & { success?: boolean }> {
        return this.request({
            method: 'PUT',
            body: JSON.stringify(data),
        })
    }
}

let apiInstance = new NotificationPreferencesApi()

export function getNotificationPreferencesApi(): NotificationPreferencesApi {
    return apiInstance
}

export function setNotificationPreferencesApi(api: NotificationPreferencesApi): void {
    apiInstance = api
}
