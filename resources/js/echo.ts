import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { buildEchoOptions, resolvePusherClientConfig } from './echo/pusherConfig';

type EchoInstance = InstanceType<typeof Echo>;

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: EchoInstance | null;
    }
}

function readCsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function createEchoInstance(key: string, cluster: string): EchoInstance {
    if (typeof window !== 'undefined') {
        window.Pusher = Pusher;
    }

    const echo = new Echo(buildEchoOptions(key, cluster, readCsrfToken()));

    echo.connector.pusher.connection.bind('error', (error: unknown) => {
        console.error('Erreur de connexion Pusher:', error);
        const pusherError = error as { error?: { data?: { code?: number } } };
        if (pusherError.error?.data?.code === 4001) {
            console.error(
                "Erreur d'authentification: Vérifiez que la route /broadcasting/auth est accessible et que vous êtes authentifié",
            );
        }
    });

    return echo;
}

const pusherConfig = resolvePusherClientConfig(
    import.meta.env.VITE_PUSHER_APP_KEY,
    import.meta.env.VITE_PUSHER_APP_CLUSTER,
);

let echo: EchoInstance | null = null;

if (!pusherConfig) {
    console.warn(
        "VITE_PUSHER_APP_KEY n'est pas défini : les notifications en temps réel sont désactivées. " +
            'Ajoutez VITE_PUSHER_APP_KEY dans votre .env et recompilez les assets avec npm run build.',
    );
} else {
    echo = createEchoInstance(pusherConfig.key, pusherConfig.cluster);
}

export default echo;
