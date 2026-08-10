import { notificationConfig } from '../config/notification.config'

/** Accès UI read-only (textes, labels, animations) — sans Pinia. */
export function useNotificationUi() {
    return {
        texts: notificationConfig.texts,
        filterLabels: notificationConfig.filterLabels,
        priorityLabels: notificationConfig.priorityLabels,
        priorityColors: notificationConfig.priorityColors,
        animations: notificationConfig.animations,
        drawerWidth: notificationConfig.drawerWidth,
        iconMap: notificationConfig.iconMap,
        defaultIcon: notificationConfig.defaultIcon,
    }
}
