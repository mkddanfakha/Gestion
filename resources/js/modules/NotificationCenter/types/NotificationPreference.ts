import type { NotificationPriority } from './NotificationPriority'

export interface NotificationSoundProfileMeta {
    label: string
    frequencies: Record<NotificationPriority, number>
}

/** Préférences utilisateur brutes (base de données). */
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

/** Préférences effectives après fusion global admin + utilisateur. */
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
