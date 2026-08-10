/**
 * NotificationCenter — module autonome Laravel + Vue 3 + Pinia
 * @see ./README.md
 */

// Configuration
export {
    configureNotificationCenter,
    notificationConfig,
    resetNotificationConfig,
    setRealtimeProvider,
    getRealtimeProvider,
} from './config/notification.config'
export type { NotificationCenterConfig, NotificationApiRoutes } from './config/notification.config'

// Constants
export * from './constants/notification.constants'

// Types
export type * from './types'

// Stores
export { useNotificationStore } from './stores/NotificationStore'
export { useNotificationPreferencesStore } from './stores/NotificationPreferencesStore'

// Services
export {
    NotificationApi,
    getNotificationApi,
    setNotificationApi,
} from './services/NotificationApi'
export type { NotificationApiOptions } from './services/NotificationApi'
export {
    NotificationPreferenceApi,
    getNotificationPreferenceApi,
    setNotificationPreferenceApi,
} from './services/NotificationPreferenceApi'
export {
    NotificationRealtimeService,
    getNotificationRealtimeService,
    setNotificationRealtimeService,
    refreshNotificationRealtimeService,
} from './services/NotificationRealtimeService'
export {
    NotificationSoundService,
    getNotificationSoundService,
    setNotificationSoundService,
} from './services/NotificationSoundService'
export {
    NotificationBrowserService,
    getNotificationBrowserService,
    setNotificationBrowserService,
} from './services/NotificationBrowserService'

// Composables
export { useNotifications } from './composables/useNotifications'
export { useNotificationPreferences, useNotificationPreferencesReadonly } from './composables/useNotificationPreferences'
export { useNotificationRealtime } from './composables/useNotificationRealtime'
export { useNotificationSound } from './composables/useNotificationSound'
export { useBrowserNotifications } from './composables/useBrowserNotifications'
export { useNotificationUi } from './composables/useNotificationUi'

// Utils
export { getDateGroup, getDateGroupLabel, formatNotificationTime, isNotificationRead, isNotificationResolved } from './utils/dateGroups'
export { computeUnreadCounts, unreadCountForFilter, emptyStateForPriority } from './utils/filterCounts'
export { filterNotificationsBySearch, dedupeNotifications, mergeNotification } from './utils/search'
export { animationClasses } from './utils/animations'
export { diffCalendarDays, formatExpirationStatus } from './utils/expirationStatus'
export type { ExpirationStatusResult, ExpirationVisualStatus } from './utils/expirationStatus'

// Components
export { default as NotificationCenter } from './NotificationCenter.vue'
export { default as NotificationBell } from './components/NotificationBell.vue'
export { default as NotificationDrawer } from './components/NotificationDrawer.vue'
export { default as NotificationToast } from './components/NotificationToast.vue'

/** @deprecated Utiliser NotificationPreferenceApi */
export {
    NotificationPreferenceApi as NotificationPreferencesApi,
    getNotificationPreferenceApi as getNotificationPreferencesApi,
    setNotificationPreferenceApi as setNotificationPreferencesApi,
} from './services/NotificationPreferenceApi'

/** @deprecated Utiliser getNotificationSoundService via useNotificationSound */
import { getNotificationSoundService } from './services/NotificationSoundService'
import { useNotificationPreferencesStore } from './stores/NotificationPreferencesStore'
import type { NotificationPriority } from './types'
import type { RealtimeProvider } from './types/NotificationSettings'
import { useNotificationRealtime } from './composables/useNotificationRealtime'

export const notificationSound = {
    activate: () => getNotificationSoundService().activate(),
    play: (priority: NotificationPriority = 'info') =>
        getNotificationSoundService().play(priority, useNotificationPreferencesStore().effective),
    isEnabled: () => getNotificationSoundService().isEnabled(useNotificationPreferencesStore().effective),
}

/** @deprecated Utiliser useNotificationRealtime().connect() */
export function connectNotificationRealtime(provider: RealtimeProvider): () => void {
    const { connect, disconnect } = useNotificationRealtime(provider)
    connect(provider)
    return disconnect
}