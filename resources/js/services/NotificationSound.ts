/**
 * Service audio des notifications — désactivé par défaut.
 * Activation future via paramètres utilisateur.
 */
class NotificationSoundService {
    private enabled = false
    private audioContext: AudioContext | null = null

    isEnabled(): boolean {
        return this.enabled
    }

    setEnabled(value: boolean): void {
        this.enabled = value
    }

    async play(priority: 'critical' | 'warning' | 'info' = 'info'): Promise<void> {
        if (!this.enabled) return

        try {
            if (!this.audioContext || this.audioContext.state === 'closed') {
                this.audioContext = new (window.AudioContext || (window as any).webkitAudioContext)()
            }

            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume()
            }

            const ctx = this.audioContext
            const oscillator = ctx.createOscillator()
            const gain = ctx.createGain()

            oscillator.connect(gain)
            gain.connect(ctx.destination)

            const frequencies: Record<string, number> = {
                critical: 880,
                warning: 740,
                info: 620,
            }

            oscillator.frequency.value = frequencies[priority] ?? 620
            oscillator.type = 'sine'

            const now = ctx.currentTime
            gain.gain.setValueAtTime(0, now)
            gain.gain.linearRampToValueAtTime(0.15, now + 0.02)
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25)

            oscillator.start(now)
            oscillator.stop(now + 0.25)
        } catch {
            // Silencieux si autoplay bloqué
        }
    }
}

export const notificationSound = new NotificationSoundService()
