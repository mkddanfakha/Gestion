import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';
import path from 'path';
import { detectLanIPv4, resolveDevServerHost } from './scripts/detect-lan-ipv4';

const DEV_PORT = 5173;

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    // Détection automatique à chaque `npm run dev` (pas d'IP en dur).
    // VITE_DEV_SERVER_HOST=localhost force le mode poste uniquement.
    const devHost = resolveDevServerHost(env.VITE_DEV_SERVER_HOST);
    const devOrigin = `http://${devHost}:${DEV_PORT}`;
    const useLan = devHost !== 'localhost' && devHost !== '127.0.0.1';

    if (useLan) {
        console.log(`[vite] Réseau local : ${devOrigin} (IPv4 détectée : ${detectLanIPv4() ?? devHost})`);
        console.log('[vite] Sur le téléphone : http://<même-ip>:8000 (Laravel avec --host=0.0.0.0)');
    }

    return {
        resolve: {
            alias: {
                '@notification-center': path.resolve(__dirname, 'resources/js/modules/NotificationCenter'),
                '@NotificationCenter': path.resolve(__dirname, 'resources/js/modules/NotificationCenter'),
            },
        },
        server: {
            // IPv4 explicite — évite que Vite annonce http://[::]:5173
            host: '0.0.0.0',
            port: DEV_PORT,
            strictPort: true,
            cors: true,
            // URL publique des assets (@vite/client, .vue, app.ts)
            origin: devOrigin,
            // Accès depuis IP LAN / téléphone (Vite 6+)
            allowedHosts: true,
            hmr: {
                host: devHost,
                port: DEV_PORT,
                protocol: 'ws',
                clientPort: DEV_PORT,
            },
        },
        plugins: [
            laravel({
                input: ['resources/js/app.ts'],
                ssr: 'resources/js/ssr.ts',
                refresh: true,
            }),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
    };
});
