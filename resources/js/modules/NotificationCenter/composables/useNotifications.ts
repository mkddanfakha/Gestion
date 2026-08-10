import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { notificationConfig } from '../config/notification.config'
import { getNotificationBrowserService } from '../services/NotificationBrowserService'
import { getNotificationSoundService } from '../services/NotificationSoundService'
import { useNotificationStore } from '../stores/NotificationStore'
import type { Notification, NotificationDateGroup, NotificationFilter, NotificationPriority } from '../types'
import type { NotificationCounts } from '../types/NotificationCounts'
import { getDateGroup, getDateGroupLabel, isNotificationRead } from '../utils/dateGroups'
import { computeUnreadCounts, emptyStateForPriority, unreadCountForFilter } from '../utils/filterCounts'
import { useNotificationPreferences } from './useNotificationPreferences'

/**
 * Point d'entrée unique des notifications.
 * Orchestre store + préférences + effets (son, toast, navigateur).
 */
export function useNotifications() {
    const store = useNotificationStore()
    const { effective } = useNotificationPreferences()
    const soundService = getNotificationSoundService()
    const browserService = getNotificationBrowserService()

    const {
        drawerOpen,
        loading,
        loadingMore,
        markingAll,
        toasts,
        filter,
        searchQuery,
        baseFilteredNotifications,
        eligibleNotifications,
        visibleCount,
        apiCounts,
        listTotal,
        pendingRefresh,
        expirationClock,
        searching,
    } = storeToRefs(store)

    const usesApiPagination = computed(() => notificationConfig.pagination.mode === 'pages')

    const applyPreferenceFilter = (items: Notification[]) => {
        const eff = effective.value
        if (!eff) return items

        return items.filter((item) => {
            if (eff.critical_only && item.priority !== 'critical') return false
            if (eff.hide_resolved && (item.status === 'resolved' || item.resolved_at)) return false
            return true
        })
    }

    const notificationsForCounts = computed(() => {
        if (usesApiPagination.value) {
            return []
        }

        return applyPreferenceFilter(eligibleNotifications.value)
    })

    const unreadCounts = computed<NotificationCounts>(() => {
        if (usesApiPagination.value) {
            const counts = apiCounts.value
            const eff = effective.value

            if (eff?.critical_only) {
                return {
                    total: counts.critical,
                    critical: counts.critical,
                    warning: 0,
                    info: 0,
                }
            }

            return counts
        }

        return computeUnreadCounts(notificationsForCounts.value)
    })

    const notifications = computed(() => applyPreferenceFilter(baseFilteredNotifications.value))

    const visibleNotifications = computed(() => {
        if (usesApiPagination.value || notificationConfig.pagination.mode === 'pages') {
            return notifications.value
        }

        return notifications.value.slice(0, visibleCount.value)
    })

    const groupedNotifications = computed(() => {
        if (usesApiPagination.value) {
            return visibleNotifications.value.length > 0
                ? [{
                    key: 'today' as NotificationDateGroup,
                    label: '',
                    items: visibleNotifications.value,
                }]
                : []
        }

        const groups: Record<NotificationDateGroup, Notification[]> = {
            today: [],
            yesterday: [],
            this_week: [],
            older: [],
        }

        visibleNotifications.value.forEach((item) => {
            groups[getDateGroup(item.created_at)].push(item)
        })

        return (Object.keys(groups) as NotificationDateGroup[])
            .filter((key) => groups[key].length > 0)
            .map((key) => ({
                key,
                label: getDateGroupLabel(key),
                items: groups[key],
            }))
    })

    const hasMore = computed(() => store.hasMore)

    const unreadCount = computed(() => {
        const eff = effective.value
        if (eff && !eff.badge_enabled) return 0

        return unreadCounts.value.total
    })

    const activePriorityLabel = computed(() => {
        const active = filter.value
        if (active === 'critical' || active === 'warning' || active === 'info') {
            return notificationConfig.priorityLabels[active as NotificationPriority]
        }

        return null
    })

    const activePriorityTotal = computed(() => {
        if (searchQuery.value.trim()) {
            return listTotal.value
        }

        const active = filter.value
        if (active === 'all') {
            return unreadCounts.value.total
        }

        return unreadCountForFilter(unreadCounts.value, active) ?? listTotal.value
    })

    const priorityTabEmpty = computed(() => {
        const active = filter.value
        if (active !== 'critical' && active !== 'warning' && active !== 'info') {
            return null
        }

        const count = unreadCountForFilter(unreadCounts.value, active) ?? 0
        if (count > 0) {
            return null
        }

        return emptyStateForPriority(active)
    })

    const showListEmptyState = computed(() => {
        if (priorityTabEmpty.value) {
            return true
        }

        return !loading.value && !searching.value && groupedNotifications.value.length === 0
    })

    const listEmptyState = computed(() => {
        if (priorityTabEmpty.value) {
            return priorityTabEmpty.value
        }

        return {
            title: notificationConfig.texts.emptyTitle,
            text: notificationConfig.texts.emptyText,
        }
    })

    const ui = computed(() => ({
        texts: notificationConfig.texts,
        animations: notificationConfig.animations,
        drawerWidth: notificationConfig.drawerWidth,
        filterLabels: notificationConfig.filterLabels,
        priorityLabels: notificationConfig.priorityLabels,
        priorityColors: notificationConfig.priorityColors,
    }))

    const setNotifications = (items: Notification[]) => {
        store.setNotifications(applyPreferenceFilter(items))
    }

    const handleIncoming = (notification: Notification) => {
        const eff = effective.value
        if (!eff) return

        if (eff.critical_only && notification.priority !== 'critical') return
        if (eff.hide_resolved && (notification.status === 'resolved' || notification.resolved_at)) return

        if (usesApiPagination.value) {
            if (notification.metadata?.grouped) {
                const count = Number(notification.metadata?.count ?? 0)
                if (count > 0) {
                    const next: NotificationCounts = { ...store.apiCounts }
                    next.total = Math.max(next.total, count)
                    if (notification.priority === 'critical') {
                        next.critical = Math.max(next.critical, count)
                    } else if (notification.priority === 'warning') {
                        next.warning = Math.max(next.warning, count)
                    } else {
                        next.info = Math.max(next.info, count)
                    }
                    store.setApiCounts(next)
                }
            }

            void store.fetchCounts()

            if (drawerOpen.value) {
                store.markPendingRefresh()
            }
        } else {
            store.upsertNotification(notification, eff.grouping_enabled)
        }

        if (eff.toasts_enabled) {
            store.pushToast({
                id: `toast-${notification.id}-${Date.now()}`,
                title: notification.title,
                description: notification.description,
                priority: notification.priority,
                url: notification.url,
                duration: eff.toast_durations[notification.priority],
            })
        }

        if (eff.sound_enabled) {
            void soundService.play(notification.priority, eff)
        }

        if (eff.browser_enabled) {
            browserService.show(notification, eff)
        }
    }

    const openDrawer = async () => {
        store.openDrawer()

        if (usesApiPagination.value) {
            await Promise.all([
                store.fetchCounts(),
                store.fetchAlerts(true),
            ])
            return
        }

        if (effective.value?.auto_mark_read_on_open) {
            await store.markAllAsRead()
        }
    }

    const setFilter = async (value: NotificationFilter) => {
        await store.setFilter(value)
    }

    const setSearchQuery = (value: string) => {
        if (usesApiPagination.value) {
            store.scheduleSearchQuery(value)
            return
        }

        void store.setSearchQuery(value)
    }

    const openNotificationItem = (notification: Notification) => {
        void store.markAsRead(notification)
        store.closeDrawer()

        if (notification.url) {
            store.navigate(notification.url)
        }
    }

    return {
        notifications,
        unreadCount,
        unreadCounts,
        groupedNotifications,
        visibleNotifications,
        hasMore,
        showListEmptyState,
        listEmptyState,
        activePriorityLabel,
        activePriorityTotal,
        drawerOpen,
        loading,
        loadingMore,
        markingAll,
        searching,
        toasts,
        filter,
        searchQuery,
        pendingRefresh,
        listTotal,
        expirationClock,
        ui,
        setNotifications,
        handleIncoming,
        markAsRead: (notification: Notification) => store.markAsRead(notification),
        archive: (notification: Notification) => store.archive(notification),
        delete: (notification: Notification) => store.remove(notification),
        refresh: () => store.refresh(),
        refreshList: () => store.fetchAlerts(true),
        fetchCounts: (options?: { fresh?: boolean }) => store.fetchCounts(options),
        markAllAsRead: () => store.markAllAsRead(),
        openDrawer,
        closeDrawer: () => store.closeDrawer(),
        setFilter,
        setSearchQuery,
        loadMore: () => store.loadMore(),
        dismissToast: (id: string) => store.dismissToast(id),
        navigate: (url: string) => store.navigate(url),
        isRead: (notification: Notification) => store.isRead(notification),
        openNotificationItem,
    }
}
