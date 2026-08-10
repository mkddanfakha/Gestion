import type { NotificationPriority } from './NotificationPriority'
import type { NotificationStatus } from './NotificationStatus'
import type { NotificationType } from './NotificationType'

/** Onglets de filtrage disponibles dans le centre. */
export type NotificationFilter =
    | 'all'
    | 'unread'
    | 'critical'
    | 'warning'
    | 'info'
    | 'archived'
    | 'resolved'
    | 'favorites'

/** Regroupement temporel pour l'affichage de la liste. */
export type NotificationDateGroup = 'today' | 'yesterday' | 'this_week' | 'older'

/** Modèle principal — contrat unique entre backend et UI. */
export interface Notification {
    id: string
    title: string
    description: string
    type: NotificationType
    priority: NotificationPriority
    icon?: string
    color?: string
    created_at: string
    read_at?: string | null
    resolved_at?: string | null
    status: NotificationStatus
    url?: string
    metadata?: Record<string, unknown>
    favorite?: boolean
}

/** Payload affiché dans un toast éphémère. */
export interface NotificationToast {
    id: string
    title: string
    description: string
    priority: NotificationPriority
    url?: string
    duration?: number
}

/** Réponse paginée standard de l'API. */
export interface NotificationPaginatedResponse {
    data: Notification[]
    meta?: {
        current_page: number
        last_page: number
        per_page: number
        total: number
    }
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
}

/** Réponse API des compteurs non lus. */
export interface NotificationCountsResponse {
    data: {
        all: number
        total: number
        critical: number
        warning: number
        info: number
    }
}

/** Paramètres de requête liste / recherche. */
export interface NotificationListParams {
    page?: number
    per_page?: number
    filter?: NotificationFilter
    search?: string
    signal?: AbortSignal
}
