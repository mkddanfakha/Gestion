import { getNotificationSoundService } from '../services/NotificationSoundService'
import type { NotificationPriority } from '../types'
import { useNotificationPreferences } from './useNotificationPreferences'

/** Lecture des sons — volume et priorité depuis les préférences. */
export function useNotificationSound() {
    const service = getNotificationSoundService()
    const { effective } = useNotificationPreferences()

    const play = (priority: NotificationPriority = 'info') => {
        void service.play(priority, effective.value)
    }

    const activate = () => service.activate()

    const isEnabled = () => service.isEnabled(effective.value)

    return { play, activate, isEnabled }
}
