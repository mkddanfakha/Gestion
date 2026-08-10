/** @deprecated Préférences pilotées par NotificationPreferencesStore. */
export interface NotificationSettings {
    soundEnabled: boolean
    toastEnabled: boolean
    desktopEnabled: boolean
}

/** Contrat du provider temps réel — remplaçable (Pusher, Reverb, SSE…). */
export interface RealtimeProvider {
    subscribe: (onMessage: (payload: unknown) => void) => () => void
    normalize?: (payload: unknown) => import('./Notification').Notification | null
}
