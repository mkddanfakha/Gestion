<script setup lang="ts">
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editPassword } from '@/routes/password';
import { edit as editProfile } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profil',
        href: editProfile(),
        icon: 'bi-person',
    },
    {
        title: 'Mot de passe',
        href: editPassword(),
        icon: 'bi-key',
    },
    {
        title: 'Authentification à deux facteurs',
        href: show(),
        icon: 'bi-shield-lock',
    },
    {
        title: 'Apparence',
        href: editAppearance(),
        icon: 'bi-palette',
    },
    {
        title: 'Notifications',
        href: '/settings/notifications',
        icon: 'bi-bell',
    },
];

const page = usePage();
const currentPath = page.url;
</script>

<template>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="h2 mb-1">
                    <i class="bi bi-gear me-2"></i>
                    Paramètres
                </h1>
                <p class="text-muted mb-0">Gérez votre profil et les paramètres de votre compte</p>
            </div>
        </div>

        <div class="row g-4">
            <!-- Sidebar de navigation -->
            <aside class="col-lg-3">
                <div class="card">
                    <div class="card-body p-0">
                        <nav class="nav flex-column">
                            <Link
                                v-for="item in sidebarNavItems"
                                :key="toUrl(item.href)"
                                :href="item.href"
                                class="nav-link"
                                :class="{
                                    'active': urlIsActive(item.href, currentPath),
                                }"
                            >
                                <i :class="`${item.icon} me-2`"></i>
                                {{ item.title }}
                            </Link>
                        </nav>
                    </div>
                </div>
            </aside>

            <!-- Contenu principal -->
            <div class="col-lg-9">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
.nav-link {
    padding: 0.75rem 1rem;
    color: var(--color-text-secondary);
    border-left: 3px solid transparent;
    transition: background-color var(--transition-fast), color var(--transition-fast), border-color var(--transition-fast);
}

.nav-link:hover {
    background-color: var(--color-surface-hover);
    color: var(--color-link);
    border-left-color: var(--color-accent);
}

.nav-link.active {
    background-color: var(--color-accent-soft);
    color: var(--color-accent);
    font-weight: 600;
    border-left-color: var(--color-accent);
}
</style>
