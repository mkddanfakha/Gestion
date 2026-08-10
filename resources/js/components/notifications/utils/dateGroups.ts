import { notificationConfig } from '../notification.config'
import type { NotificationDateGroup } from '../types'

/** Détermine le groupe temporel d'une notification. */
export function getDateGroup(dateIso: string): NotificationDateGroup {
    const date = new Date(dateIso)
    const now = new Date()
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const startOfDate = new Date(date.getFullYear(), date.getMonth(), date.getDate())
    const diffDays = Math.floor((startOfToday.getTime() - startOfDate.getTime()) / (1000 * 60 * 60 * 24))

    if (diffDays <= 0) return 'today'
    if (diffDays === 1) return 'yesterday'
    if (diffDays <= 7) return 'this_week'
    return 'older'
}

/** Label localisé d'un groupe de dates. */
export function getDateGroupLabel(key: NotificationDateGroup): string {
    return notificationConfig.dateGroupLabels[key] ?? key
}

/** Formate l'heure d'une notification. */
export function formatNotificationTime(dateIso: string): string {
    return new Intl.DateTimeFormat(notificationConfig.locale, {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(dateIso))
}

/** Indique si une notification est lue. */
export function isNotificationRead(readAt?: string | null): boolean {
    return Boolean(readAt)
}

/** Indique si une notification est résolue. */
export function isNotificationResolved(status: string, resolvedAt?: string | null): boolean {
    return status === 'resolved' || Boolean(resolvedAt)
}
