import type { Notification, NotificationFilter, NotificationPriority } from '../types'
import type { NotificationCounts } from '../types/NotificationCounts'
import { isNotificationRead } from './dateGroups'

const EMPTY_COUNTS: NotificationCounts = {
    total: 0,
    critical: 0,
    warning: 0,
    info: 0,
}

export function computeUnreadCounts(notifications: Notification[]): NotificationCounts {
    const unread = notifications.filter((item) => !isNotificationRead(item.read_at))

    return {
        total: unread.length,
        critical: unread.filter((item) => item.priority === 'critical').length,
        warning: unread.filter((item) => item.priority === 'warning').length,
        info: unread.filter((item) => item.priority === 'info').length,
    }
}

export function unreadCountForFilter(counts: NotificationCounts, filter: NotificationFilter): number | null {
    switch (filter) {
        case 'all':
            return counts.total
        case 'critical':
            return counts.critical
        case 'warning':
            return counts.warning
        case 'info':
            return counts.info
        default:
            return null
    }
}

export function emptyStateForPriority(priority: NotificationPriority): { title: string; text: string } {
    const labels: Record<NotificationPriority, { title: string; text: string }> = {
        critical: {
            title: 'Tout est en ordre',
            text: 'Aucune alerte critique non lue pour le moment.',
        },
        warning: {
            title: 'Tout est en ordre',
            text: 'Aucune alerte warning non lue pour le moment.',
        },
        info: {
            title: 'Tout est en ordre',
            text: 'Aucune notification d\'information.',
        },
    }

    return labels[priority]
}

export { EMPTY_COUNTS }
