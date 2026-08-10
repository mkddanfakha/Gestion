import type { NotificationPriority } from '../types'

export const NOTIFICATION_STORE_ID = 'notification-center'
export const NOTIFICATION_PREFERENCES_STORE_ID = 'notification-preferences'

export const NOTIFICATION_API_ROUTE_KEYS = [
    'list',
    'counts',
    'markAsRead',
    'markAsReadId',
    'markAllAsRead',
    'archive',
    'delete',
    'deleteRead',
    'search',
    'preferences',
] as const

export type NotificationApiRouteKey = (typeof NOTIFICATION_API_ROUTE_KEYS)[number]

export const DEFAULT_TOAST_POSITION = 'bottom-right'

export const DEFAULT_PAGINATION = {
    pageSize: 5,
    mode: 'pages' as const,
}

export const DEFAULT_DRAWER_WIDTH = 'min(440px, 100vw)'

export const DEFAULT_ANIMATIONS = {
    drawerBackdrop: 'nc-drawer-fade',
    drawerPanel: 'nc-drawer-slide',
    toast: 'nc-toast',
    listItem: 'nc-item-in',
}

export const DEFAULT_PRIORITY_LABELS: Record<NotificationPriority, string> = {
    critical: 'Critique',
    warning: 'Warning',
    info: 'Info',
}

export const DEFAULT_FILTER_LABELS = {
    all: 'Toutes',
    unread: 'Non lues',
    critical: 'Critique',
    warning: 'Warning',
    info: 'Info',
    archived: 'Archivées',
    resolved: 'Résolues',
    favorites: 'Favoris',
} as const

export const DEFAULT_DATE_GROUP_LABELS = {
    today: "Aujourd'hui",
    yesterday: 'Hier',
    this_week: 'Cette semaine',
    older: 'Plus anciennes',
} as const

export const DEFAULT_UI_TEXTS = {
    title: 'Notifications',
    subtitle: 'Centre de notifications',
    markAllRead: 'Tout marquer comme lu',
    refresh: 'Actualiser',
    settings: 'Paramètres',
    searchPlaceholder: 'Rechercher une notification...',
    emptyTitle: 'Aucune notification',
    emptyText: 'Vous êtes à jour. Les nouvelles alertes apparaîtront ici.',
    loadMore: 'Charger plus',
    loadMoreLoading: 'Chargement...',
    newAlertsBanner: 'Nouvelles alertes disponibles',
    refreshList: 'Actualiser',
    toastView: 'Voir',
    toastClose: 'Fermer',
    statusActive: 'Active',
    statusResolved: 'Résolue',
    openBell: 'Ouvrir les notifications',
    closeDrawer: 'Fermer le panneau',
} as const
