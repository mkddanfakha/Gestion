import { getCsrfToken } from '@/lib/csrf'
import { route } from '@/lib/routes'

type ClientLogContext = 'browser' | 'sound' | 'api' | 'pusher' | 'realtime'

export async function logNotificationClientError(
    context: ClientLogContext,
    message: string,
    details?: Record<string, unknown>,
): Promise<void> {
    try {
        await fetch(route('notification-center.client-log'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ context, message, details }),
        })
    } catch {
        // Ne pas masquer l'erreur originale si le log échoue
    }
}
