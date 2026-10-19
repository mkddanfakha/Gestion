import type { EchoOptions } from 'laravel-echo';

export type PusherClientConfig = {
    key: string;
    cluster: string;
};

/**
 * Détermine si une clé Pusher frontend est utilisable (non vide après trim).
 */
export function resolvePusherClientConfig(
    rawKey: string | undefined,
    rawCluster: string | undefined,
): PusherClientConfig | null {
    const key = rawKey?.trim();
    if (!key) {
        return null;
    }

    const cluster = rawCluster?.trim() || 'mt1';

    return { key, cluster };
}

/**
 * Options Laravel Echo / Pusher partagées entre l'initialisation runtime et les tests.
 */
export function buildEchoOptions(
    key: string,
    cluster: string,
    csrfToken: string,
): EchoOptions<'pusher'> {
    return {
        broadcaster: 'pusher',
        key,
        cluster,
        forceTLS: true,
        encrypted: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        },
        enabledTransports: ['ws', 'wss'],
    };
}
