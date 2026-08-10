import { notificationConfig } from '../config/notification.config'
import { NOTIFICATION_STORE_ID } from '../constants/notification.constants'
import { getNotificationApi } from '../services/NotificationApi'
import type {
    Notification,
    NotificationDateGroup,
    NotificationFilter,
    NotificationToast,
} from '../types'
import type { NotificationCounts } from '../types/NotificationCounts'
import { EMPTY_COUNTS } from '../utils/filterCounts'
import { getDateGroup, getDateGroupLabel, isNotificationRead, isNotificationResolved } from '../utils/dateGroups'
import { dedupeNotifications, filterNotificationsBySearch, mergeNotification } from '../utils/search'
import { defineStore } from 'pinia'

interface NotificationState {
    notifications: Notification[]
    apiCounts: NotificationCounts
    archivedIds: Set<string>
    removedIds: Set<string>
    favoriteIds: Set<string>
    filter: NotificationFilter
    searchQuery: string
    drawerOpen: boolean
    loading: boolean
    loadingMore: boolean
    markingAll: boolean
    toasts: NotificationToast[]
    visibleCount: number
    currentPage: number
    lastPage: number
    listTotal: number
    pendingRefresh: boolean
    expirationClock: number
    searching: boolean
    alertsRequestId: number
    searchDebounceTimer: ReturnType<typeof setTimeout> | null
    alertsAbortController: AbortController | null
    expirationInterval: ReturnType<typeof setInterval> | null
}

function mapResponseItem(raw: Record<string, unknown>): Notification {
    if (notificationConfig.mapListItem) {
        return notificationConfig.mapListItem(raw)
    }

    return raw as unknown as Notification
}

function normalizePaginatedResponse(response: Awaited<ReturnType<ReturnType<typeof getNotificationApi>['getNotifications']>>) {
    const meta = response.meta ?? response

    return {
        items: (response.data ?? []).map((item) => mapResponseItem(item as unknown as Record<string, unknown>)),
        currentPage: meta.current_page ?? 1,
        lastPage: meta.last_page ?? 1,
        total: meta.total ?? 0,
    }
}

function filterToSeverity(filter: NotificationFilter): NotificationFilter | undefined {
    if (filter === 'critical' || filter === 'warning' || filter === 'info') {
        return filter
    }

    return undefined
}

function decrementCount(counts: NotificationCounts, priority: Notification['priority']): NotificationCounts {
    const next = { ...counts }

    if (next.total > 0) {
        next.total -= 1
    }

    if (priority === 'critical' && next.critical > 0) {
        next.critical -= 1
    } else if (priority === 'warning' && next.warning > 0) {
        next.warning -= 1
    } else if (priority === 'info' && next.info > 0) {
        next.info -= 1
    }

    return next
}

/** Store notifications — liste, filtres, pagination, toasts UI. Sans préférences utilisateur. */
export const useNotificationStore = defineStore(NOTIFICATION_STORE_ID, {
    state: (): NotificationState => ({
        notifications: [],
        apiCounts: { ...EMPTY_COUNTS },
        archivedIds: new Set(),
        removedIds: new Set(),
        favoriteIds: new Set(),
        filter: 'all',
        searchQuery: '',
        drawerOpen: false,
        loading: false,
        loadingMore: false,
        markingAll: false,
        toasts: [],
        visibleCount: notificationConfig.pagination.pageSize,
        currentPage: 1,
        lastPage: 1,
        listTotal: 0,
        pendingRefresh: false,
        expirationClock: 0,
        searching: false,
        alertsRequestId: 0,
        searchDebounceTimer: null,
        alertsAbortController: null,
        expirationInterval: null,
    }),

    getters: {
        usesApiPagination(): boolean {
            return notificationConfig.pagination.mode === 'pages'
        },

        baseFilteredNotifications(state): Notification[] {
            if (this.usesApiPagination) {
                return state.notifications.filter((item) => !state.removedIds.has(item.id))
            }

            let list = state.notifications.filter((item) => !state.removedIds.has(item.id))

            if (state.filter === 'archived') {
                list = list.filter((item) => state.archivedIds.has(item.id) || item.status === 'archived')
            } else {
                list = list.filter((item) => !state.archivedIds.has(item.id) && item.status !== 'archived')
            }

            switch (state.filter) {
                case 'unread':
                    list = list.filter((item) => !isNotificationRead(item.read_at))
                    break
                case 'critical':
                    list = list.filter(
                        (item) => item.priority === 'critical' && !isNotificationRead(item.read_at),
                    )
                    break
                case 'warning':
                    list = list.filter(
                        (item) => item.priority === 'warning' && !isNotificationRead(item.read_at),
                    )
                    break
                case 'info':
                    list = list.filter(
                        (item) => item.priority === 'info' && !isNotificationRead(item.read_at),
                    )
                    break
                case 'resolved':
                    list = list.filter(
                        (item) =>
                            isNotificationResolved(item.status, item.resolved_at) ||
                            isNotificationRead(item.read_at),
                    )
                    break
                case 'favorites':
                    list = list.filter((item) => state.favoriteIds.has(item.id) || item.favorite)
                    break
            }

            return filterNotificationsBySearch(list, state.searchQuery)
        },

        eligibleNotifications(state): Notification[] {
            let list = state.notifications.filter((item) => !state.removedIds.has(item.id))

            if (state.filter === 'archived') {
                return list.filter((item) => state.archivedIds.has(item.id) || item.status === 'archived')
            }

            return list.filter((item) => !state.archivedIds.has(item.id) && item.status !== 'archived')
        },

        visibleNotifications(state): Notification[] {
            const filtered = (this as ReturnType<typeof useNotificationStore>).baseFilteredNotifications
            if (this.usesApiPagination || notificationConfig.pagination.mode === 'pages') {
                return filtered
            }
            return filtered.slice(0, state.visibleCount)
        },

        hasMore(state): boolean {
            if (this.usesApiPagination) {
                return state.currentPage < state.lastPage
            }

            const store = this as ReturnType<typeof useNotificationStore>
            return store.baseFilteredNotifications.length > store.visibleNotifications.length
        },

        groupedNotifications(): Array<{ key: NotificationDateGroup; label: string; items: Notification[] }> {
            const groups: Record<NotificationDateGroup, Notification[]> = {
                today: [],
                yesterday: [],
                this_week: [],
                older: [],
            }

            const store = this as ReturnType<typeof useNotificationStore>
            store.visibleNotifications.forEach((item) => {
                groups[getDateGroup(item.created_at)].push(item)
            })

            return (Object.keys(groups) as NotificationDateGroup[])
                .filter((key) => groups[key].length > 0)
                .map((key) => ({
                    key,
                    label: getDateGroupLabel(key),
                    items: groups[key],
                }))
        },
    },

    actions: {
        setNotifications(notifications: Notification[]) {
            this.notifications = dedupeNotifications(notifications)
        },

        setApiCounts(counts: NotificationCounts) {
            this.apiCounts = counts
        },

        upsertNotification(notification: Notification, groupingEnabled = true) {
            const groupKey = notification.metadata?.group_key as string | undefined
            const isGrouped = Boolean(notification.metadata?.grouped)

            let index = this.notifications.findIndex((item) => item.id === notification.id)

            if (index < 0 && groupingEnabled && isGrouped && groupKey) {
                index = this.notifications.findIndex(
                    (item) =>
                        item.type === notification.type &&
                        item.metadata?.group_key === groupKey &&
                        Boolean(item.metadata?.grouped),
                )
            }

            if (index >= 0) {
                this.notifications[index] = mergeNotification(this.notifications[index], {
                    ...notification,
                    read_at: this.notifications[index].read_at ?? null,
                })
                return this.notifications[index]
            }

            this.notifications.unshift(notification)
            return notification
        },

        removeNotificationByPreferenceFilter(predicate: (notification: Notification) => boolean) {
            this.notifications = this.notifications.filter(predicate)
        },

        pushToast(toast: NotificationToast) {
            this.toasts.push(toast)
        },

        clearToasts() {
            this.toasts = []
        },

        dismissToast(id: string) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id)
        },

        openDrawer() {
            this.drawerOpen = true
            this.visibleCount = notificationConfig.pagination.pageSize
            this.bumpExpirationClock()
            this.startExpirationClock()
        },

        closeDrawer() {
            this.drawerOpen = false
            this.stopExpirationClock()
            this.clearSearchDebounce()
        },

        async setFilter(filter: NotificationFilter) {
            this.filter = filter
            this.visibleCount = notificationConfig.pagination.pageSize
            this.currentPage = 1
            this.pendingRefresh = false
            this.clearSearchDebounce()

            if (this.usesApiPagination) {
                await this.fetchAlerts(true)
            }
        },

        async setSearchQuery(query: string) {
            this.searchQuery = query
            this.visibleCount = notificationConfig.pagination.pageSize
            this.currentPage = 1
            this.pendingRefresh = false

            if (this.usesApiPagination) {
                await this.fetchAlerts(true)
            }
        },

        /**
         * Met à jour le champ de recherche immédiatement (UI) puis lance
         * une requête API debouncée — une seule source de vérité côté store.
         */
        scheduleSearchQuery(query: string) {
            this.searchQuery = query
            this.visibleCount = notificationConfig.pagination.pageSize
            this.currentPage = 1
            this.pendingRefresh = false

            if (!this.usesApiPagination) {
                return
            }

            this.clearSearchDebounce()
            this.searchDebounceTimer = setTimeout(() => {
                this.searchDebounceTimer = null
                void this.fetchAlerts(true)
            }, 300)
        },

        clearSearchDebounce() {
            if (this.searchDebounceTimer) {
                clearTimeout(this.searchDebounceTimer)
                this.searchDebounceTimer = null
            }
        },

        abortAlertsRequest() {
            if (this.alertsAbortController) {
                this.alertsAbortController.abort()
                this.alertsAbortController = null
            }
        },

        async loadMore() {
            if (this.usesApiPagination) {
                if (this.loadingMore || !this.hasMore) {
                    return
                }

                await this.fetchAlerts(false)
                return
            }

            this.visibleCount += notificationConfig.pagination.pageSize
        },

        isRead(notification: Notification): boolean {
            return isNotificationRead(notification.read_at)
        },

        async fetchCounts(options: { fresh?: boolean } = {}) {
            try {
                const counts = await getNotificationApi().getCounts(options)
                this.setApiCounts(counts)
            } catch (error) {
                console.warn('[NotificationCenter] Impossible de charger les compteurs', error)
            }
        },

        async fetchAlerts(reset = true) {
            const requestId = ++this.alertsRequestId
            const search = this.searchQuery.trim()
            const severity = filterToSeverity(this.filter)

            this.abortAlertsRequest()
            const controller = new AbortController()
            this.alertsAbortController = controller

            if (reset) {
                if (this.notifications.length === 0) {
                    this.loading = true
                } else {
                    this.searching = true
                }
                this.currentPage = 1
            } else {
                this.loadingMore = true
            }

            try {
                const page = reset ? 1 : this.currentPage + 1
                const response = await getNotificationApi().getNotifications({
                    page,
                    per_page: notificationConfig.pagination.pageSize,
                    filter: severity,
                    search: search || undefined,
                    signal: controller.signal,
                })

                if (requestId !== this.alertsRequestId) {
                    return
                }

                const normalized = normalizePaginatedResponse(response)

                if (reset) {
                    this.notifications = normalized.items
                } else {
                    this.notifications = dedupeNotifications([
                        ...this.notifications,
                        ...normalized.items,
                    ])
                }

                this.currentPage = normalized.currentPage
                this.lastPage = normalized.lastPage
                this.listTotal = normalized.total
                this.pendingRefresh = false
                this.bumpExpirationClock()
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return
                }

                console.warn('[NotificationCenter] Impossible de charger les alertes', error)
            } finally {
                if (requestId !== this.alertsRequestId) {
                    return
                }

                this.loading = false
                this.loadingMore = false
                this.searching = false
                this.alertsAbortController = null
            }
        },

        async fetchNotifications() {
            await this.fetchAlerts(true)
        },

        async refresh() {
            if (this.usesApiPagination) {
                this.clearSearchDebounce()

                try {
                    await Promise.all([this.fetchCounts(), this.fetchAlerts(true)])
                } catch (error) {
                    console.warn('[NotificationCenter] Actualisation impossible', error)
                }
                return
            }

            if (notificationConfig.onRefresh) {
                this.loading = true
                try {
                    const result = await notificationConfig.onRefresh()
                    if (result) {
                        this.setNotifications(result)
                    }
                } finally {
                    this.loading = false
                }
                return
            }

            await this.fetchNotifications()
        },

        markPendingRefresh() {
            this.pendingRefresh = true
        },

        bumpExpirationClock() {
            this.expirationClock += 1
        },

        startExpirationClock() {
            this.stopExpirationClock()
            this.expirationInterval = setInterval(() => {
                this.bumpExpirationClock()
            }, 60_000)
        },

        stopExpirationClock() {
            if (this.expirationInterval) {
                clearInterval(this.expirationInterval)
                this.expirationInterval = null
            }
        },

        async markAsRead(notification: Notification) {
            const wasRead = isNotificationRead(notification.read_at)
            const now = new Date().toISOString()
            const index = this.notifications.findIndex((item) => item.id === notification.id)

            if (index >= 0 && this.usesApiPagination) {
                this.notifications.splice(index, 1)
                this.listTotal = Math.max(0, this.listTotal - 1)
            } else if (index >= 0) {
                this.notifications[index] = {
                    ...this.notifications[index],
                    read_at: now,
                    status: 'resolved',
                    resolved_at: this.notifications[index].resolved_at ?? now,
                }
            }

            if (!wasRead && this.usesApiPagination) {
                this.apiCounts = decrementCount(this.apiCounts, notification.priority)
            }

            if (notification.metadata?.skip_api) return

            try {
                await getNotificationApi().markAsRead(notification)
            } catch {
                // État optimiste conservé
            }
        },

        async archive(notification: Notification) {
            this.archivedIds.add(notification.id)
            const index = this.notifications.findIndex((item) => item.id === notification.id)
            if (index >= 0) {
                this.notifications[index] = { ...this.notifications[index], status: 'archived' }
            }
            await this.markAsRead(notification)
            try {
                await getNotificationApi().archive(notification)
            } catch {
                // ignore
            }
        },

        async remove(notification: Notification) {
            this.removedIds.add(notification.id)
            try {
                await getNotificationApi().delete(notification)
            } catch {
                // ignore
            }
        },

        async markAllAsRead() {
            this.markingAll = true
            const now = new Date().toISOString()

            try {
                if (this.usesApiPagination) {
                    this.notifications = []
                    this.listTotal = 0
                    this.apiCounts = { ...EMPTY_COUNTS }
                } else {
                    this.notifications = this.notifications.map((item) => ({
                        ...item,
                        read_at: item.read_at ?? now,
                        status: 'resolved',
                        resolved_at: item.resolved_at ?? now,
                    }))
                }

                await getNotificationApi().markAllAsRead()
                await this.fetchCounts()
            } catch {
                // ignore
            } finally {
                this.markingAll = false
            }
        },

        navigate(url: string) {
            notificationConfig.navigate(url)
        },
    },
})
