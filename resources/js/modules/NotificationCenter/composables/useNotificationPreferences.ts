import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useNotificationPreferencesStore } from '../stores/NotificationPreferencesStore'
import { useNotificationStore } from '../stores/NotificationStore'
import type { NotificationPreferencesPayload, NotificationUserPreferences } from '../types'

/**
 * Point d'accès unique aux préférences utilisateur.
 * Les composants ne doivent pas accéder directement au store.
 */
export function useNotificationPreferences() {
    const store = useNotificationPreferencesStore()
    const { payload, loading, effective, user, meta, global, isReady } = storeToRefs(store)

    const applyPayload = (next: NotificationPreferencesPayload) => {
        store.applyPayload(next)
        const notificationStore = useNotificationStore()
        notificationStore.removeNotificationByPreferenceFilter((item) => store.shouldShowNotification(item))

        if (!next.effective.toasts_enabled) {
            notificationStore.clearToasts()
        }
    }

    const loadPreferences = (force = false) => store.load(force)

    const updatePreferences = async (data: Partial<NotificationUserPreferences>) => {
        const result = await store.update(data)
        applyPayload(result)
        return result
    }

    return {
        payload,
        loading,
        effective,
        user,
        meta,
        global,
        isReady,
        loadPreferences,
        updatePreferences,
        applyPayload,
    }
}

export function useNotificationPreferencesReadonly() {
    const { effective, user, meta, global, isReady } = useNotificationPreferences()

    return {
        effective: computed(() => effective.value),
        user: computed(() => user.value),
        meta: computed(() => meta.value),
        global: computed(() => global.value),
        isReady,
    }
}
