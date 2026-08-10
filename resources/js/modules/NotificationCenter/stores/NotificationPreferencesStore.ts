import { NOTIFICATION_PREFERENCES_STORE_ID } from '../constants/notification.constants'
import { getNotificationPreferenceApi } from '../services/NotificationPreferenceApi'
import type { NotificationEffectivePreferences, NotificationPreferencesPayload, NotificationUserPreferences } from '../types'
import { isNotificationResolved } from '../utils/dateGroups'
import type { Notification } from '../types'
import { defineStore } from 'pinia'

function passesCriticalOnly(
    notification: Notification,
    effective: NotificationEffectivePreferences | null,
): boolean {
    if (!effective?.critical_only) return true
    return notification.priority === 'critical'
}

function passesHideResolved(
    notification: Notification,
    effective: NotificationEffectivePreferences | null,
): boolean {
    if (!effective?.hide_resolved) return true
    return !isNotificationResolved(notification.status, notification.resolved_at)
}

/** Store préférences — chargement, fusion effective, filtres comportementaux. */
export const useNotificationPreferencesStore = defineStore(NOTIFICATION_PREFERENCES_STORE_ID, {
    state: () => ({
        payload: null as NotificationPreferencesPayload | null,
        loading: false,
    }),

    getters: {
        effective(state): NotificationEffectivePreferences | null {
            return state.payload?.effective ?? null
        },

        user(state) {
            return state.payload?.user ?? null
        },

        meta(state) {
            return state.payload?.meta ?? null
        },

        global(state) {
            return state.payload?.global ?? null
        },

        isReady(state): boolean {
            return state.payload !== null
        },
    },

    actions: {
        applyPayload(payload: NotificationPreferencesPayload) {
            this.payload = payload
        },

        applyPreferenceFilters(notifications: Notification[]): Notification[] {
            const effective = this.effective
            return notifications.filter(
                (item) => passesCriticalOnly(item, effective) && passesHideResolved(item, effective),
            )
        },

        shouldShowNotification(notification: Notification): boolean {
            const effective = this.effective
            return passesCriticalOnly(notification, effective) && passesHideResolved(notification, effective)
        },

        async load(force = false) {
            if (this.loading) return
            if (this.payload && !force) return

            this.loading = true
            try {
                const payload = await getNotificationPreferenceApi().fetch()
                this.applyPayload(payload)
            } finally {
                this.loading = false
            }
        },

        async update(data: Partial<NotificationUserPreferences>) {
            const response = await getNotificationPreferenceApi().update(data)
            const payload: NotificationPreferencesPayload = {
                user: response.user,
                effective: response.effective,
                meta: response.meta,
                global: response.global,
            }
            this.applyPayload(payload)
            return payload
        },
    },
})
