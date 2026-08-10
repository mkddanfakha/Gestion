import { notificationConfig } from '../notification.config'
import type { Notification, NotificationListParams, NotificationPaginatedResponse } from '../types'

type RequestBodyMapper = (notification: Notification) => Record<string, unknown>

export interface NotificationApiOptions {
    resolveRoute?: (key: keyof typeof notificationConfig.routes, params?: Record<string, unknown>) => string
    markAsReadBody?: RequestBodyMapper
    markAllAsReadBody?: () => Record<string, unknown>
    archiveBody?: RequestBodyMapper
    deleteBody?: RequestBodyMapper
}

/**
 * Service HTTP découplé — seul point d'accès réseau du module.
 */
export class NotificationApi {
    constructor(private readonly options: NotificationApiOptions = {}) {}

    private url(key: keyof typeof notificationConfig.routes, params?: Record<string, unknown>): string {
        if (this.options.resolveRoute) {
            return this.options.resolveRoute(key, params)
        }
        return notificationConfig.routes[key]
    }

    private async request<T>(url: string, init: RequestInit = {}): Promise<T> {
        const response = await fetch(url, {
            ...init,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': notificationConfig.getCsrfToken(),
                ...(init.headers ?? {}),
            },
        })

        if (!response.ok) {
            throw new Error(`NotificationApi: ${response.status} ${response.statusText}`)
        }

        if (response.status === 204) {
            return undefined as T
        }

        return response.json() as Promise<T>
    }

    async getNotifications(params: NotificationListParams = {}): Promise<NotificationPaginatedResponse> {
        const searchParams = new URLSearchParams()
        if (params.page) searchParams.set('page', String(params.page))
        if (params.per_page) searchParams.set('per_page', String(params.per_page))
        if (params.filter) searchParams.set('filter', params.filter)
        if (params.search) searchParams.set('search', params.search)

        const base = this.url('list')
        const url = searchParams.toString() ? `${base}?${searchParams}` : base
        return this.request<NotificationPaginatedResponse>(url)
    }

    async markAsRead(notification: Notification): Promise<void> {
        const body = this.options.markAsReadBody?.(notification) ?? { id: notification.id }
        await this.request(this.url('markAsRead'), {
            method: 'POST',
            body: JSON.stringify(body),
        })
    }

    async markAllAsRead(): Promise<void> {
        const body = this.options.markAllAsReadBody?.() ?? {}
        await this.request(this.url('markAllAsRead'), { method: 'POST', body: JSON.stringify(body) })
    }

    async archive(notification: Notification): Promise<void> {
        const body = this.options.archiveBody?.(notification) ?? { id: notification.id }
        await this.request(this.url('archive'), {
            method: 'POST',
            body: JSON.stringify(body),
        })
    }

    async delete(notification: Notification): Promise<void> {
        const body = this.options.deleteBody?.(notification) ?? { id: notification.id }
        await this.request(this.url('delete'), {
            method: 'DELETE',
            body: JSON.stringify(body),
        })
    }

    async deleteRead(): Promise<void> {
        await this.request(this.url('deleteRead'), { method: 'DELETE' })
    }

    async search(
        query: string,
        params: Omit<NotificationListParams, 'search'> = {},
    ): Promise<NotificationPaginatedResponse> {
        return this.getNotifications({ ...params, search: query })
    }
}

let apiInstance: NotificationApi = new NotificationApi()

export function getNotificationApi(): NotificationApi {
    return apiInstance
}

export function setNotificationApi(api: NotificationApi): void {
    apiInstance = api
}
