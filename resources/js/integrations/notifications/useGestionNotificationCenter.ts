/**
 * Intégration NotificationCenter ↔ projet Gestion (Inertia + Pusher + API module).
 */
import {
    configureNotificationCenter,
    NotificationApi,
    setNotificationApi,
    setRealtimeProvider,
} from '@/modules/NotificationCenter'
import { useNotificationPreferences } from '@/modules/NotificationCenter/composables/useNotificationPreferences'
import { useNotificationRealtime } from '@/modules/NotificationCenter/composables/useNotificationRealtime'
import { useNotifications } from '@/modules/NotificationCenter/composables/useNotifications'
import { useNotificationSound } from '@/modules/NotificationCenter/composables/useNotificationSound'
import type { NotificationPreferencesPayload } from '@/modules/NotificationCenter/types'
import { getCsrfToken } from '@/lib/csrf'
import { route } from '@/lib/routes'
import { router, usePage } from '@inertiajs/vue3'
import { onMounted, onUnmounted, watch } from 'vue'
import { mapAlertItemApiPayload, mapLegacyPayload, type LegacyNotificationsPayload } from './notificationMapper'
import { computeUnreadCounts } from '@/modules/NotificationCenter/utils/filterCounts'
import type { NotificationCounts } from '@/modules/NotificationCenter/types/NotificationCounts'
import { createPusherNotificationProvider } from './pusherProvider'

import { useNotificationStore } from '@/modules/NotificationCenter/stores/NotificationStore'

let configured = false

export function setupGestionNotificationCenter(): void {
    if (configured) return
    configured = true

    const page = usePage()
    const role = (page.props.auth as { user?: { role?: string } })?.user?.role
    const settingsUrl = role === 'admin'
        ? route('admin.settings.notifications')
        : route('notification-preferences.edit')

    configureNotificationCenter({
        getCsrfToken,
        navigate: (url) => router.visit(url),
        settingsUrl,
        pagination: {
            pageSize: 5,
            mode: 'pages',
        },
        mapListItem: mapAlertItemApiPayload,
        routes: {
            preferences: route('notification-center.preferences.show'),
        },
        filterLabels: {
            all: 'Toutes',
            unread: 'Non lues',
            critical: 'Critique',
            warning: 'Warning',
            info: 'Info',
            archived: 'Archivées',
            resolved: 'Résolues',
            favorites: 'Favoris',
        },
    })

    setNotificationApi(
        new NotificationApi({
            resolveRoute(key, params) {
                const routes: Record<string, string> = {
                    list: route('notification-center.index'),
                    counts: route('notification-center.counts'),
                    markAsRead: route('notifications.mark-as-read'),
                    markAsReadId: route('notification-center.mark-as-read-id', params ?? {}),
                    markAllAsRead: route('notifications.mark-all-as-read'),
                    archive: route('notification-center.archive', params ?? {}),
                    delete: route('notification-center.destroy', params ?? {}),
                    deleteRead: route('notification-center.delete-read'),
                    search: route('notification-center.search'),
                }

                let url = routes[key] ?? '/'

                if (params) {
                    Object.entries(params).forEach(([name, value]) => {
                        url = url.replace(`{${name}}`, String(value))
                    })
                }

                return url
            },
            markAsReadBody(notification) {
                const legacyType = (notification.metadata?.legacy_type ?? notification.type) as string | undefined
                const entityId = notification.metadata?.entity_id as number | undefined
                if (!legacyType || !entityId || entityId <= 0 || notification.metadata?.skip_api) {
                    throw new Error('skip')
                }
                return { type: legacyType, id: entityId }
            },
            markAllAsReadBody: () => ({ type: 'all' }),
        }),
    )
}

function bootstrapCountsFromLegacy(payload: LegacyNotificationsPayload | undefined): NotificationCounts | null {
    if (!payload) {
        return null
    }

    const items = mapLegacyPayload(payload).filter((item) => !item.metadata?.grouped)
    if (items.length === 0) {
        return null
    }

    return computeUnreadCounts(items)
}

export function useGestionNotificationCenter(): void {
    setupGestionNotificationCenter()

    const page = usePage()
    const store = useNotificationStore()
    const { fetchCounts } = useNotifications()
    const { loadPreferences, applyPayload } = useNotificationPreferences()
    const { connect, disconnect } = useNotificationRealtime()
    const { activate: activateSound } = useNotificationSound()
    const userId = (page.props.auth as { user?: { id?: number } })?.user?.id

    function syncPreferencesFromPage() {
        const payload = page.props.notificationPreferences as NotificationPreferencesPayload | null | undefined
        if (payload) {
            applyPayload(payload)
        }
    }

    watch(() => page.props.notificationPreferences, syncPreferencesFromPage, { deep: true, immediate: true })

    onMounted(async () => {
        syncPreferencesFromPage()

        const legacyCounts = bootstrapCountsFromLegacy(page.props.notifications as LegacyNotificationsPayload | undefined)
        if (legacyCounts) {
            store.setApiCounts(legacyCounts)
        }

        await loadPreferences(true)
        await fetchCounts({ fresh: true })

        const provider = userId ? createPusherNotificationProvider(userId) : null
        setRealtimeProvider(provider)
        connect(provider)

        document.addEventListener('click', activateSound, { passive: true })
        document.addEventListener('keydown', activateSound, { passive: true })
    })

    onUnmounted(() => {
        disconnect()
        document.removeEventListener('click', activateSound)
        document.removeEventListener('keydown', activateSound)
    })
}

/** @deprecated */
export { useGestionNotificationCenter as useNotificationCenter }
