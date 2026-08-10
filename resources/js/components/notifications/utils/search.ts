import type { Notification } from '../types'

/** Recherche locale dans titre, description et type — sans connaissance métier. */
export function filterNotificationsBySearch(notifications: Notification[], query: string): Notification[] {
    const q = query.trim().toLowerCase()
    if (!q) return notifications

    return notifications.filter(
        (item) =>
            item.title.toLowerCase().includes(q) ||
            item.description.toLowerCase().includes(q) ||
            item.type.toLowerCase().includes(q),
    )
}

/** Déduplique par identifiant en conservant la dernière occurrence. */
export function dedupeNotifications(notifications: Notification[]): Notification[] {
    const map = new Map<string, Notification>()
    notifications.forEach((item) => map.set(item.id, item))
    return Array.from(map.values())
}

/** Fusionne une notification entrante avec une existante. */
export function mergeNotification(existing: Notification, incoming: Notification): Notification {
    return {
        ...existing,
        ...incoming,
        metadata: { ...existing.metadata, ...incoming.metadata },
    }
}
