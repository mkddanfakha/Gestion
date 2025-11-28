import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Déclarer Pusher comme global pour Laravel Echo
window.Pusher = Pusher;

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';

// Vérifier si Pusher est configuré
if (!pusherKey) {
    console.error('VITE_PUSHER_APP_KEY n\'est pas défini. Les notifications en temps réel ne fonctionneront pas.')
}

const echo = new Echo({
    broadcaster: 'pusher',
    key: pusherKey,
    cluster: pusherCluster,
    forceTLS: true,
    encrypted: true,
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
    },
    enabledTransports: ['ws', 'wss'],
});

// Écouter les événements de connexion
echo.connector.pusher.connection.bind('error', (error: any) => {
    console.error('Erreur de connexion Pusher:', error)
});

export default echo;

