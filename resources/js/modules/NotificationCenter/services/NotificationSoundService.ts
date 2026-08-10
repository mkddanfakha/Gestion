import type { NotificationPriority } from '../types'
import type { NotificationEffectivePreferences } from '../types/NotificationPreference'
import { logNotificationClientError } from './NotificationClientLogService'

/** Lecture audio — aucune dépendance Pinia. */
export class NotificationSoundService {
    private audioContext: AudioContext | null = null

    isEnabled(preferences: NotificationEffectivePreferences | null): boolean {
        return preferences?.sound_enabled ?? false
    }

    getVolume(preferences: NotificationEffectivePreferences | null): number {
        return preferences?.sound_volume ?? 0
    }

    getFrequency(priority: NotificationPriority, preferences: NotificationEffectivePreferences | null): number {
        if (!preferences) return 0

        const profileKey = preferences.sound_profiles[priority]
        const profile = preferences.sound_catalog[profileKey]
        if (!profile || profileKey === 'silent') return 0

        return profile.frequencies[priority] ?? 0
    }

    async play(priority: NotificationPriority, preferences: NotificationEffectivePreferences | null): Promise<void> {
        if (!this.isEnabled(preferences)) return

        const frequency = this.getFrequency(priority, preferences)
        if (frequency <= 0) return

        try {
            if (!this.audioContext || this.audioContext.state === 'closed') {
                this.audioContext = new (window.AudioContext ||
                    (window as typeof window & { webkitAudioContext: typeof AudioContext }).webkitAudioContext)()
            }

            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume()
            }

            const ctx = this.audioContext
            const oscillator = ctx.createOscillator()
            const gain = ctx.createGain()
            const volume = this.getVolume(preferences)

            oscillator.connect(gain)
            gain.connect(ctx.destination)
            oscillator.frequency.value = frequency
            oscillator.type = 'sine'

            const now = ctx.currentTime
            gain.gain.setValueAtTime(0, now)
            gain.gain.linearRampToValueAtTime(volume, now + 0.02)
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25)

            oscillator.start(now)
            oscillator.stop(now + 0.25)
        } catch (error) {
            void logNotificationClientError('sound', 'Impossible de jouer le son de notification', {
                priority,
                error: error instanceof Error ? error.message : String(error),
            })
        }
    }

    async activate(): Promise<void> {
        if (!this.audioContext || this.audioContext.state === 'closed') {
            this.audioContext = new (window.AudioContext ||
                (window as typeof window & { webkitAudioContext: typeof AudioContext }).webkitAudioContext)()
        }
        if (this.audioContext.state === 'suspended') {
            await this.audioContext.resume()
        }
    }
}

let serviceInstance = new NotificationSoundService()

export function getNotificationSoundService(): NotificationSoundService {
    return serviceInstance
}

export function setNotificationSoundService(service: NotificationSoundService): void {
    serviceInstance = service
}
