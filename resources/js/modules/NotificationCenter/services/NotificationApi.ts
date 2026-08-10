import { notificationConfig } from '../config/notification.config'
import type { NotificationApiRouteKey } from '../constants/notification.constants'
import type {
    Notification,
    NotificationCountsResponse,
    NotificationFilter,
    NotificationListParams,
    NotificationPaginatedResponse,
} from '../types'
import type { NotificationCounts } from '../types/NotificationCounts'

type RequestBodyMapper = (notification: Notification) => Record<string, unknown>

export interface NotificationApiOptions {
    resolveRoute?: (key: NotificationApiRouteKey, params?: Record<string, unknown>) => string
    markAsReadBody?: RequestBodyMapper
    markAllAsReadBody?: () => Record<string, unknown>
    archiveBody?: RequestBodyMapper
    deleteBody?: RequestBodyMapper
}

function normalizePaginatedResponse(json: NotificationPaginatedResponse): NotificationPaginatedResponse {
    const meta = json.meta ?? {
        current_page: json.current_page ?? 1,
        last_page: json.last_page ?? 1,
        per_page: json.per_page ?? notificationConfig.pagination.pageSize,
        total: json.total ?? json.data.length,
    }

    return {
        data: json.data,
        meta,
        current_page: meta.current_page,
        last_page: meta.last_page,
        per_page: meta.per_page,
        total: meta.total,
    }
}

/** Appels HTTP notifications — aucune logique métier. */
export class NotificationApi {
    constructor(private readonly options: NotificationApiOptions = {}) {}

    private url(key: NotificationApiRouteKey, params?: Record<string, unknown>): string {
        if (this.options.resolveRoute) {
            return this.options.resolveRoute(key, params)
        }
        return notificationConfig.routes[key]
    }

    private async request<T>(url: string, init: RequestInit = {}): Promise<T> {
        const response = await fetch(url, {
            ...init,
            credentials: 'same-origin',
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
        if (params.search) searchParams.set('search', params.search)

        const filter = params.filter
        if (filter && ['critical', 'warning', 'info'].includes(filter)) {
            searchParams.set('severity', filter)
        }

        const base = this.url('list')
        const url = searchParams.toString() ? `${base}?${searchParams}` : base
        const json = await this.request<NotificationPaginatedResponse>(url, {
            signal: params.signal,
        })

        return normalizePaginatedResponse(json)
    }

    async getCounts(options: { fresh?: boolean } = {}): Promise<NotificationCounts> {
        const searchParams = new URLSearchParams()
        if (options.fresh) {
            searchParams.set('fresh', '1')
        }

        const base = this.url('counts')
        const url = searchParams.toString() ? `${base}?${searchParams}` : base
        const json = await this.request<NotificationCountsResponse>(url)

        return {
            total: json.data.all ?? json.data.total ?? 0,
            critical: json.data.critical ?? 0,
            warning: json.data.warning ?? 0,
            info: json.data.info ?? 0,
        }
    }

    async markAsRead(notification: Notification): Promise<void> {
        if (/^\d+$/.test(String(notification.id))) {
            const url = this.url('markAsReadId', { id: notification.id })
            await this.request(url, { method: 'POST' })
            return
        }

        const body = this.options.markAsReadBody?.(notification) ?? { id: notification.id }
        await this.request(this.url('markAsRead'), { method: 'POST', body: JSON.stringify(body) })
    }

    async markAllAsRead(): Promise<void> {
        const body = this.options.markAllAsReadBody?.() ?? {}
        await this.request(this.url('markAllAsRead'), { method: 'POST', body: JSON.stringify(body) })
    }

    async archive(notification: Notification): Promise<void> {
        const body = this.options.archiveBody?.(notification) ?? { id: notification.id }
        await this.request(this.url('archive'), { method: 'POST', body: JSON.stringify(body) })
    }

    async delete(notification: Notification): Promise<void> {
        const body = this.options.deleteBody?.(notification) ?? { id: notification.id }
        await this.request(this.url('delete'), { method: 'DELETE', body: JSON.stringify(body) })
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

let apiInstance = new NotificationApi()

export function getNotificationApi(): NotificationApi {
    return apiInstance
}

export function setNotificationApi(api: NotificationApi): void {
    apiInstance = api
}
