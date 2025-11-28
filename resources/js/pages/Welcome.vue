<script setup lang="ts">
import { dashboard, login } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Bienvenue">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    
    <div class="min-vh-100 bg-light d-flex flex-column align-items-center p-4">
        <!-- Header -->
        <header class="mb-4 w-100 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img 
                    src="/logo.png" 
                    alt="Logo Gestion" 
                    class="logo-img"
                    style="width: 60px; height: 60px; object-fit: contain;"
                />
                <h2 class="mb-0 fw-bold text-primary">Gestion</h2>
            </div>
            <nav class="d-flex align-items-center gap-2">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="btn btn-outline-primary btn-sm"
                >
                    <i class="bi bi-speedometer2 me-1"></i>
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="btn btn-primary btn-sm"
                    >
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        Connexion
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Main Content -->
        <div class="flex-grow-1 d-flex align-items-center justify-content-center w-100">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-10">
                        <div class="card shadow-lg border-0">
                            <div class="card-body p-5">
                                <div class="row align-items-center">
                                    <!-- Content -->
                                    <div class="col-lg-6">
                                        <h1 class="display-4 fw-bold text-primary mb-3">
                                            Bienvenue sur Gestion
                                        </h1>
                                        <p class="lead text-muted mb-4">
                                            Votre système de gestion de stock et de ventes complet.
                                            Gérez vos produits, clients et ventes en toute simplicité.
                                        </p>
                                        
                                        <div class="d-flex gap-3 mb-4">
                                            <Link
                                                v-if="$page.props.auth.user"
                                                :href="dashboard()"
                                                class="btn btn-primary btn-lg"
                                            >
                                                <i class="bi bi-speedometer2 me-2"></i>
                                                Accéder au Dashboard
                                            </Link>
                                            <template v-else>
                                                <Link
                                                    :href="login()"
                                                    class="btn btn-primary btn-lg"
                                                >
                                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                                    Se connecter
                                                </Link>
                                            </template>
                                        </div>

                                        <!-- Features -->
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0 me-3">
                                                        <div class="bg-primary bg-opacity-10 rounded-circle p-2">
                                                            <i class="bi bi-box text-primary fs-5"></i>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-semibold">Gestion des produits</h6>
                                                        <small class="text-muted">Inventaire complet</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0 me-3">
                                                        <div class="bg-success bg-opacity-10 rounded-circle p-2">
                                                            <i class="bi bi-people text-success fs-5"></i>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-semibold">Gestion des clients</h6>
                                                        <small class="text-muted">Base de données clients</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0 me-3">
                                                        <div class="bg-info bg-opacity-10 rounded-circle p-2">
                                                            <i class="bi bi-cart text-info fs-5"></i>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-semibold">Ventes</h6>
                                                        <small class="text-muted">Processus de vente simplifié</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0 me-3">
                                                        <div class="bg-warning bg-opacity-10 rounded-circle p-2">
                                                            <i class="bi bi-bar-chart text-warning fs-5"></i>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-semibold">Rapports</h6>
                                                        <small class="text-muted">Analyses et statistiques</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Illustration -->
                                    <div class="col-lg-6 text-center">
                                        <div class="position-relative d-flex align-items-center justify-content-center">
                                            <div class="bg-primary bg-opacity-10 rounded-4 p-5 d-flex align-items-center justify-content-center">
                                                <img 
                                                    src="/logo.png" 
                                                    alt="Logo Gestion" 
                                                    class="welcome-logo"
                                                    style="max-width: 300px; max-height: 300px; object-fit: contain;"
                                                />
                                            </div>
                                            <div class="position-absolute top-0 start-0 translate-middle">
                                                <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                                    <i class="bi bi-graph-up text-success fs-3"></i>
                                                </div>
                                            </div>
                                            <div class="position-absolute bottom-0 end-0 translate-middle">
                                                <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                                    <i class="bi bi-trophy text-warning fs-3"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="mt-auto w-100 text-center py-4">
            <p class="text-muted mb-0">
                <small>
                    MKD-pro {{ new Date().getFullYear() }} © Tous droits réservés
                </small>
            </p>
        </footer>
    </div>
</template>

<style scoped>
.min-vh-100 {
    min-height: 100vh;
}

.flex-grow-1 {
    flex-grow: 1;
}

.translate-middle {
    transform: translate(-50%, -50%);
}

.top-0 {
    top: 0;
}

.start-0 {
    left: 0;
}

.bottom-0 {
    bottom: 0;
}

.end-0 {
    right: 0;
}
</style>