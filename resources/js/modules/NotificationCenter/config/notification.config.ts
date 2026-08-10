import type { Component } from 'vue'
import {
    AlertTriangle,
    Bell,
    CircleAlert,
    Database,
    Package,
    Receipt,
    User,
    Boxes,
} from 'lucide-vue-next'
import {
    DEFAULT_ANIMATIONS,
    DEFAULT_DATE_GROUP_LABELS,
    DEFAULT_DRAWER_WIDTH,
    DEFAULT_FILTER_LABELS,
    DEFAULT_PAGINATION,
    DEFAULT_PRIORITY_LABELS,
    DEFAULT_UI_TEXTS,
    type NotificationApiRouteKey,
} from '../constants/notification.constants'
import type { Notification, NotificationFilter, NotificationPriority } from '../types'
import type { RealtimeProvider } from '../types/NotificationSettings'

export interface NotificationApiRoutes extends Record<NotificationApiRouteKey, string> {}

export interface NotificationCenterConfig {
    routes: NotificationApiRoutes
    getCsrfToken: () => string
    navigate: (url: string) => void
    locale: string
    filterLabels: Record<NotificationFilter, string>
    priorityLabels: Record<NotificationPriority, string>
    dateGroupLabels: Record<string, string>
    priorityColors: Record<
        NotificationPriority,
        { bg: string; text: string; border: string; badge: string }
    >
    iconMap: Record<string, Component>
    defaultIcon: Component
    drawerWidth: string
    pagination: {
        pageSize: number
        mode: 'infinite' | 'pages'
    }
    animations: {
        drawerBackdrop: string
        drawerPanel: string
        toast: string
        listItem: string
    }
    texts: typeof DEFAULT_UI_TEXTS
    /** URL de la page paramètres (admin ou utilisateur). Null = bouton masqué. */
    settingsUrl?: string | null
    onRefresh?: () => Promise<Notification[] | void>
    mapListItem?: (raw: Record<string, unknown>) => Notification
    realtimeProvider?: RealtimeProvider | null
}

const defaultConfig: NotificationCenterConfig = {
    routes: {
        list: '/api/notifications',
        counts: '/api/notifications/counts',
        markAsRead: '/api/notifications/read',
        markAllAsRead: '/api/notifications/read-all',
        archive: '/api/notifications/archive',
        delete: '/api/notifications',
        deleteRead: '/api/notifications/read',
        search: '/api/notifications/search',
        preferences: '/api/user/notification-preferences',
    },
    getCsrfToken: () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    navigate: (url: string) => {
        window.location.href = url
    },
    locale: 'fr-FR',
    filterLabels: { ...DEFAULT_FILTER_LABELS },
    priorityLabels: { ...DEFAULT_PRIORITY_LABELS },
    dateGroupLabels: { ...DEFAULT_DATE_GROUP_LABELS },
    priorityColors: {
        critical: {
            bg: 'bg-red-50 dark:bg-red-950/30',
            text: 'text-red-700 dark:text-red-300',
            border: 'border-red-200 dark:border-red-900',
            badge: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        },
        warning: {
            bg: 'bg-amber-50 dark:bg-amber-950/30',
            text: 'text-amber-700 dark:text-amber-300',
            border: 'border-amber-200 dark:border-amber-900',
            badge: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        },
        info: {
            bg: 'bg-blue-50 dark:bg-blue-950/30',
            text: 'text-blue-700 dark:text-blue-300',
            border: 'border-blue-200 dark:border-blue-900',
            badge: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        },
    },
    iconMap: {
        default: Bell,
        alert: AlertTriangle,
        error: CircleAlert,
        database: Database,
        package: Package,
        receipt: Receipt,
        user: User,
        inventory: Boxes,
    },
    defaultIcon: Bell,
    drawerWidth: DEFAULT_DRAWER_WIDTH,
    pagination: { ...DEFAULT_PAGINATION },
    animations: { ...DEFAULT_ANIMATIONS },
    texts: { ...DEFAULT_UI_TEXTS },
    settingsUrl: null,
    realtimeProvider: null,
}

export const notificationConfig: NotificationCenterConfig = defaultConfig

export function configureNotificationCenter(partial: Partial<NotificationCenterConfig>): void {
    Object.assign(notificationConfig, partial)

    if (partial.routes) Object.assign(notificationConfig.routes, partial.routes)
    if (partial.texts) Object.assign(notificationConfig.texts, partial.texts)
    if (partial.pagination) Object.assign(notificationConfig.pagination, partial.pagination)
    if (partial.iconMap) Object.assign(notificationConfig.iconMap, partial.iconMap)
    if (partial.priorityColors) Object.assign(notificationConfig.priorityColors, partial.priorityColors)
    if (partial.filterLabels) Object.assign(notificationConfig.filterLabels, partial.filterLabels)
    if (partial.dateGroupLabels) Object.assign(notificationConfig.dateGroupLabels, partial.dateGroupLabels)
    if (partial.priorityLabels) Object.assign(notificationConfig.priorityLabels, partial.priorityLabels)
    if (partial.animations) Object.assign(notificationConfig.animations, partial.animations)
}

export function resetNotificationConfig(): void {
    configureNotificationCenter({
        routes: { ...defaultConfig.routes },
        locale: defaultConfig.locale,
        filterLabels: { ...defaultConfig.filterLabels },
        priorityLabels: { ...defaultConfig.priorityLabels },
        dateGroupLabels: { ...defaultConfig.dateGroupLabels },
        priorityColors: { ...defaultConfig.priorityColors },
        drawerWidth: defaultConfig.drawerWidth,
        pagination: { ...defaultConfig.pagination },
        animations: { ...defaultConfig.animations },
        texts: { ...defaultConfig.texts },
        settingsUrl: defaultConfig.settingsUrl,
        getCsrfToken: defaultConfig.getCsrfToken,
        navigate: defaultConfig.navigate,
        onRefresh: undefined,
        realtimeProvider: null,
    })
}

export function setRealtimeProvider(provider: RealtimeProvider | null): void {
    notificationConfig.realtimeProvider = provider
}

export function getRealtimeProvider(): RealtimeProvider | null {
    return notificationConfig.realtimeProvider ?? null
}
