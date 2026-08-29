<script setup lang="ts">
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();

const year = new Date().getFullYear();
</script>

<template>
    <div class="mkd-marketing mkd-auth">
        <aside class="mkd-auth__brand" aria-hidden="false">
            <div class="mkd-auth__brand-inner">
                <Link :href="home()" class="mkd-brand text-decoration-none">
                    <img src="/logo.png" alt="" class="mkd-brand__logo" width="44" height="44" />
                    <span class="mkd-brand__name" style="color: #f8fafc">MKD-Pro</span>
                </Link>
                <div class="mkd-auth__brand-copy">
                    <h2>La gestion commerciale, clairement organisée.</h2>
                    <p>
                        Produits, stocks, ventes, clients, devis et factures — centralisés pour votre activité.
                    </p>
                </div>
                <ul class="mkd-auth__brand-points list-unstyled mb-0">
                    <li class="mkd-auth__brand-point">
                        <i class="bi bi-box-seam" aria-hidden="true"></i>
                        Catalogue et inventaire
                    </li>
                    <li class="mkd-auth__brand-point">
                        <i class="bi bi-receipt" aria-hidden="true"></i>
                        Ventes, devis et factures
                    </li>
                    <li class="mkd-auth__brand-point">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        Clients et suivi d’activité
                    </li>
                </ul>
            </div>
        </aside>

        <div class="mkd-auth__panel">
            <div class="mkd-auth__panel-inner mkd-fade-in">
                <div class="mkd-auth__mobile-brand">
                    <Link :href="home()" class="mkd-brand justify-content-center text-decoration-none">
                        <img src="/logo.png" alt="MKD-Pro" class="mkd-brand__logo" width="44" height="44" />
                        <span class="mkd-brand__name">MKD-Pro</span>
                    </Link>
                </div>

                <div class="mkd-auth__card">
                    <h1 class="mkd-auth__title">{{ title }}</h1>
                    <p v-if="description" class="mkd-auth__desc">{{ description }}</p>
                    <slot />
                </div>

                <footer class="mkd-auth__footer">
                    MKD-Pro {{ year }} © Tous droits réservés
                </footer>
            </div>
        </div>
    </div>
</template>
