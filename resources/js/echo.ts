import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Déclarer Pusher comme global pour Laravel Echo
window.Pusher = Pusher;

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';

// Vérifier si Pusher est configuré
if (!pusherKey) {
    console.error('VITE_PUSHER_APP_KEY n\'est pas défini. Les notifications en temps réel ne fonctionneront pas.')
    console.error('Solution: Ajoutez VITE_PUSHER_APP_KEY dans votre .env et recompilez les assets avec npm run build')
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

// Écouter les événements de connexion pour le diagnostic
echo.connector.pusher.connection.bind('error', (error: any) => {
    console.error('Erreur de connexion Pusher:', error)
    if (error.error?.data?.code === 4001) {
        console.error('Erreur d\'authentification: Vérifiez que la route /broadcasting/auth est accessible et que vous êtes authentifié')
    }
});

echo.connector.pusher.connection.bind('connected', () => {
    console.log('Connexion Pusher établie avec succès')
});

echo.connector.pusher.connection.bind('disconnected', () => {
    console.warn('Connexion Pusher perdue')
});

echo.connector.pusher.connection.bind('state_change', (states: any) => {
    console.log('État de connexion Pusher:', states.previous, '->', states.current)
});

export default echo;

