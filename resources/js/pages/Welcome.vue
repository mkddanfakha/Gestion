<script setup lang="ts">
import { dashboard, home, login } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';

const year = new Date().getFullYear();

const features = [
    {
        icon: 'bi-box-seam',
        title: 'Produits',
        text: 'Gérez votre catalogue, références et informations produits.',
    },
    {
        icon: 'bi-clipboard-data',
        title: 'Stock',
        text: 'Suivez les quantités et réalisez vos inventaires en confiance.',
    },
    {
        icon: 'bi-cart-check',
        title: 'Ventes',
        text: 'Enregistrez et suivez vos ventes rapidement au quotidien.',
    },
    {
        icon: 'bi-people',
        title: 'Clients',
        text: 'Centralisez les informations et l’historique de vos clients.',
    },
    {
        icon: 'bi-file-earmark-text',
        title: 'Devis & factures',
        text: 'Créez et imprimez vos documents commerciaux facilement.',
    },
    {
        icon: 'bi-graph-up',
        title: 'Suivi',
        text: 'Visualisez les informations clés de votre activité.',
    },
] as const;
</script>

<template>
    <Head title="MKD-Pro — Gestion commerciale" />

    <div class="mkd-marketing mkd-landing">
        <header class="mkd-landing__nav mkd-fade-in">
            <Link :href="home()" class="mkd-brand" aria-label="MKD-Pro — Accueil">
                <img src="/logo.png" alt="" class="mkd-brand__logo" width="44" height="44" />
                <span class="mkd-brand__name">MKD-Pro</span>
            </Link>
            <nav class="d-flex align-items-center gap-2" aria-label="Navigation principale">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="mkd-btn-primary"
                >
                    <i class="bi bi-speedometer2" aria-hidden="true"></i>
                    Dashboard
                </Link>
                <Link
                    v-else
                    :href="login()"
                    class="mkd-btn-primary"
                >
                    Se connecter
                </Link>
            </nav>
        </header>

        <section class="mkd-landing__hero" aria-labelledby="mkd-hero-title">
            <div class="mkd-fade-in mkd-fade-in-delay-1">
                <p class="mkd-landing__eyebrow">
                    <i class="bi bi-shop-window" aria-hidden="true"></i>
                    Solution de gestion commerciale
                </p>
                <h1 id="mkd-hero-title" class="mkd-landing__title">
                    Gérez votre commerce simplement.
                </h1>
                <p class="mkd-landing__lead">
                    MKD-Pro centralise vos produits, stocks, ventes, clients, devis et factures
                    dans une seule application claire et professionnelle.
                </p>
                <div class="mkd-landing__cta-row">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="mkd-btn-primary"
                    >
                        Accéder au Dashboard
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="mkd-btn-primary">
                            Se connecter
                        </Link>
                        <a href="#fonctionnalites" class="mkd-btn-ghost">
                            Découvrir les fonctions
                        </a>
                    </template>
                </div>
            </div>

            <div class="mkd-landing__visual mkd-fade-in mkd-fade-in-delay-2" aria-hidden="true">
                <img src="/logo.png" alt="" class="mkd-landing__visual-logo" width="220" height="220" />
                <div class="mkd-landing__panel">
                    <div class="mkd-landing__panel-row">
                        <strong>Produits & stock</strong>
                        <span>Catalogue organisé</span>
                    </div>
                    <div class="mkd-landing__panel-row">
                        <strong>Ventes & documents</strong>
                        <span>Devis · Factures</span>
                    </div>
                    <div class="mkd-landing__panel-row">
                        <strong>Clients</strong>
                        <span>Base centralisée</span>
                    </div>
                </div>
            </div>
        </section>

        <section id="fonctionnalites" class="mkd-section mkd-fade-in mkd-fade-in-delay-3" aria-labelledby="mkd-features-title">
            <h2 id="mkd-features-title" class="mkd-section__title">Tout pour piloter votre activité</h2>
            <p class="mkd-section__lead">
                Les modules essentiels de votre gestion commerciale, réunis dans une interface sobre et efficace.
            </p>
            <div class="mkd-feature-grid">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="mkd-feature-card"
                >
                    <div class="mkd-feature-card__icon" aria-hidden="true">
                        <i class="bi" :class="feature.icon"></i>
                    </div>
                    <h3>{{ feature.title }}</h3>
                    <p>{{ feature.text }}</p>
                </article>
            </div>
        </section>

        <section class="mkd-trust" aria-labelledby="mkd-trust-title">
            <h2 id="mkd-trust-title">Une gestion claire pour une activité mieux maîtrisée</h2>
            <p>
                MKD-Pro privilégie la simplicité, l’organisation et la rapidité —
                pour centraliser vos opérations sans complexité inutile.
            </p>
            <div class="mkd-trust__pills">
                <span class="mkd-trust__pill">Simplicité</span>
                <span class="mkd-trust__pill">Organisation</span>
                <span class="mkd-trust__pill">Rapidité</span>
                <span class="mkd-trust__pill">Centralisation</span>
                <span class="mkd-trust__pill">Accessibilité</span>
            </div>
        </section>

        <section class="mkd-cta-band mx-3 mx-md-auto" style="width: calc(100% - 2rem); max-width: 1120px" aria-labelledby="mkd-cta-title">
            <h2 id="mkd-cta-title">Prêt à simplifier votre gestion&nbsp;?</h2>
            <p>Connectez-vous pour accéder à votre espace MKD-Pro.</p>
            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="mkd-btn-primary"
            >
                Ouvrir le Dashboard
            </Link>
            <Link
                v-else
                :href="login()"
                class="mkd-btn-primary"
            >
                Se connecter
            </Link>
        </section>

        <footer class="mkd-footer">
            MKD-Pro {{ year }} © Tous droits réservés
        </footer>
    </div>
</template>
