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
    color: #495057;
    border-left: 3px solid transparent;
    transition: all 0.2s ease;
}

.nav-link:hover {
    background-color: #f8f9fa;
    color: #0d6efd;
    border-left-color: #0d6efd;
}

.nav-link.active {
    background-color: #e7f1ff;
    color: #0d6efd;
    font-weight: 600;
    border-left-color: #0d6efd;
}

:deep(.dark) .nav-link {
    color: #e2e8f0;
}

:deep(.dark) .nav-link:hover {
    background-color: #1e293b;
    color: #60a5fa;
}

:deep(.dark) .nav-link.active {
    background-color: #1e3a5f;
    color: #60a5fa;
}
</style>
