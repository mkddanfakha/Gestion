import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import 'sweetalert2/dist/sweetalert2.min.css';

// Bootstrap JavaScript
import 'bootstrap/dist/js/bootstrap.bundle.min.js';

// Laravel Echo pour les notifications en temps réel avec Pusher
import echo from './echo';

// Déclarer Echo comme global
declare global {
    interface Window {
        Pusher: any;
        Echo: typeof echo;
    }
}

window.Echo = echo;

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const loadingState = {
    isLoading: false
};

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
        
        // Fonction de résolution personnalisée pour gérer les sous-dossiers
        const resolvePage = (pageName: string) => {
            // Essayer différentes variantes du nom de page
            const variants = [
                `./pages/${pageName}.vue`,
                `./pages/${pageName}/Index.vue`,
                `./pages/${pageName.toLowerCase()}.vue`,
                `./pages/${pageName.toLowerCase()}/Index.vue`,
            ];
            
            for (const variant of variants) {
                if (pages[variant]) {
                    return pages[variant]();
                }
            }
            
            // Si aucune variante ne fonctionne, essayer la résolution par défaut
            try {
                return resolvePageComponent(pageName, pages);
            } catch (error) {
                throw error;
            }
        };
        
        return resolvePage(name);
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
        
        // Écouter les événements Inertia pour notifier les composants
        document.addEventListener('inertia:start', () => {
            loadingState.isLoading = true;
            window.dispatchEvent(new CustomEvent('loading-state-changed', { detail: true }));
        });
        
        document.addEventListener('inertia:finish', () => {
            setTimeout(() => {
                loadingState.isLoading = false;
                window.dispatchEvent(new CustomEvent('loading-state-changed', { detail: false }));
            }, 300);
        });
        
        return app;
    },
    progress: false, // Désactiver la barre de progression par défaut
});

// This will set light / dark mode on page load...
initializeTheme();
