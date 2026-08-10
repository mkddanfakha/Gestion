import { notificationConfig } from '../notification.config'
import type { NotificationPriority } from '../types'
import { useNotificationStore } from '../store/notificationStore'

/** Service audio — piloté par les préférences utilisateur (store Pinia). */
class NotificationSoundService {
    private audioContext: AudioContext | null = null

    private getStore() {
        return useNotificationStore()
    }

    isEnabled(): boolean {
        return this.getStore().effectivePreferences?.sound_enabled ?? false
    }

    getVolume(): number {
        return this.getStore().effectivePreferences?.sound_volume ?? 0
    }

    getFrequency(priority: NotificationPriority): number {
        const store = this.getStore()
        const effective = store.effectivePreferences
        if (!effective) return 0

        const profileKey = effective.sound_profiles[priority]
        const profile = effective.sound_catalog[profileKey]
        if (!profile || profileKey === 'silent') return 0

        return profile.frequencies[priority] ?? 0
    }

    async play(priority: NotificationPriority = 'info'): Promise<void> {
        if (!this.isEnabled()) return

        const frequency = this.getFrequency(priority)
        if (frequency <= 0) return

        try {
            if (!this.audioContext || this.audioContext.state === 'closed') {
                this.audioContext = new (window.AudioContext ||
                    (window as typeof window & { webkitAudioContext: typeof AudioContext })
                        .webkitAudioContext)()
            }

            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume()
            }

            const ctx = this.audioContext
            const oscillator = ctx.createOscillator()
            const gain = ctx.createGain()
            const volume = this.getVolume()

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
        } catch {
            // Autoplay bloqué
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

export const notificationSound = new NotificationSoundService()
