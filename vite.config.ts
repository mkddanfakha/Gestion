import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';
import path from 'path';
import fs from 'fs';
import { detectLanIPv4, resolveDevServerHost } from './scripts/detect-lan-ipv4';

const DEV_PORT = 5173;

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    // Détection automatique à chaque `npm run dev`
    const devHost = resolveDevServerHost(env.VITE_DEV_SERVER_HOST);
    const useLan = devHost !== 'localhost' && devHost !== '127.0.0.1';

    // HTTPS uniquement lorsque Vite est utilisé sur le LAN.
    const useHttps = useLan;

    const protocol = useHttps ? 'https' : 'http';
    const devOrigin = `${protocol}://${devHost}:${DEV_PORT}`;

    // Certificats mkcert utilisés pour le développement HTTPS LAN.
    const certPath = path.resolve(__dirname, '.cert/mkd-pro.pem');
    const keyPath = path.resolve(__dirname, '.cert/mkd-pro-key.pem');

    const httpsConfig = useHttps
        ? {
              cert: fs.readFileSync(certPath),
              key: fs.readFileSync(keyPath),
          }
        : undefined;

    if (useLan) {
        console.log(
            `[vite] Réseau local HTTPS : ${devOrigin} (IPv4 détectée : ${detectLanIPv4() ?? devHost})`,
        );

        console.log(
            '[vite] Sur le téléphone : https://<même-ip> (Laravel via Caddy HTTPS)',
        );

        if (!fs.existsSync(certPath) || !fs.existsSync(keyPath)) {
            console.warn(
                '[vite] Certificat HTTPS introuvable dans .cert/. ' +
                'Vite ne pourra pas démarrer correctement en HTTPS.',
            );
        }
    }

    return {
        resolve: {
            alias: {
                '@notification-center': path.resolve(
                    __dirname,
                    'resources/js/modules/NotificationCenter',
                ),
                '@NotificationCenter': path.resolve(
                    __dirname,
                    'resources/js/modules/NotificationCenter',
                ),
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

            // Accès depuis IP LAN / téléphone
            allowedHosts: true,

            // HTTPS avec certificat mkcert sur le LAN
            https: httpsConfig,

            hmr: {
                host: devHost,
                port: DEV_PORT,

                protocol: useHttps ? 'wss' : 'ws',

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