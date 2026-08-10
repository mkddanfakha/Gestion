/**
 * Types génériques du NotificationCenter.
 * Aucune notion métier — uniquement le contrat de données.
 */

/** Priorité visuelle d'une notification. */
export type NotificationPriority = 'critical' | 'warning' | 'info'

/** Statut fonctionnel d'une notification. */
export type NotificationStatus = 'active' | 'resolved' | 'archived'

/** Onglets de filtrage disponibles dans le centre. */
export type NotificationFilter =
    | 'all'
    | 'unread'
    | 'critical'
    | 'warning'
    | 'info'
    | 'archived'
    | 'resolved'
    | 'favorites'

/** Regroupement temporel pour l'affichage de la liste. */
export type NotificationDateGroup = 'today' | 'yesterday' | 'this_week' | 'older'

/** Modèle principal — contrat unique entre backend et UI. */
export interface Notification {
    id: string
    title: string
    description: string
    type: string
    priority: NotificationPriority
    icon?: string
    color?: string
    created_at: string
    read_at?: string | null
    resolved_at?: string | null
    status: NotificationStatus
    url?: string
    metadata?: Record<string, unknown>
    favorite?: boolean
}

/** Payload affiché dans un toast éphémère. */
export interface NotificationToast {
    id: string
    title: string
    description: string
    priority: NotificationPriority
    url?: string
    duration?: number
}

/** Paramètres utilisateur — chargés depuis l'API (aucune valeur codée en dur côté UI). */
export interface NotificationUserPreferences {
    toasts_enabled: boolean
    sound_enabled: boolean
    browser_enabled: boolean
    critical_only: boolean
    hide_resolved: boolean
    sound_profile?: string
    toast_position: string
    toast_durations: Record<NotificationPriority, number>
    sound_volume: number
    sound_profiles: Record<NotificationPriority, string>
    auto_mark_read_on_open: boolean
    grouping_enabled: boolean
}

export interface NotificationSoundProfileMeta {
    label: string
    frequencies: Record<NotificationPriority, number>
}

/** Préférences effectives après fusion global + utilisateur. */
export interface NotificationEffectivePreferences {
    toasts_enabled: boolean
    sound_enabled: boolean
    browser_enabled: boolean
    badge_enabled: boolean
    critical_only: boolean
    hide_resolved: boolean
    auto_mark_read_on_open: boolean
    grouping_enabled: boolean
    toast_position: string
    toast_durations: Record<NotificationPriority, number>
    sound_volume: number
    sound_profiles: Record<NotificationPriority, string>
    sound_catalog: Record<string, NotificationSoundProfileMeta>
}

export interface NotificationPreferencesMeta {
    toast_positions: Record<string, string>
    default_toast_durations: Record<NotificationPriority, number>
    default_sound_volume: number
    sound_profiles: Record<string, NotificationSoundProfileMeta>
    sound_profile_keys: string[]
    priorities: string[]
}

export interface NotificationGlobalPreferences {
    toasts_enabled: boolean
    sound_enabled: boolean
    browser_enabled: boolean
    badge_enabled: boolean
    grouping_enabled: boolean
    auto_mark_read_on_open: boolean
}

export interface NotificationPreferencesPayload {
    user: NotificationUserPreferences & Record<string, unknown>
    effective: NotificationEffectivePreferences
    meta: NotificationPreferencesMeta
    global: NotificationGlobalPreferences
}

/** @deprecated Utiliser NotificationEffectivePreferences via le store. */
export interface NotificationSettings {
    soundEnabled: boolean
    toastEnabled: boolean
    desktopEnabled: boolean
}

/** Réponse paginée standard de l'API. */
export interface NotificationPaginatedResponse {
    data: Notification[]
    current_page: number
    last_page: number
    per_page: number
    total: number
}

/** Paramètres de requête liste / recherche. */
export interface NotificationListParams {
    page?: number
    per_page?: number
    filter?: NotificationFilter
    search?: string
}

/** Provider temps réel — remplaçable (Pusher, Reverb, SSE…). */
export interface RealtimeProvider {
    /** Démarre l'écoute ; retourne une fonction de nettoyage. */
    subscribe: (onMessage: (payload: unknown) => void) => () => void
    /** Transforme le payload brut en Notification (optionnel). */
    normalize?: (payload: unknown) => Notification | null
}
