<script setup lang="ts">
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="min-vh-100 d-flex flex-column align-items-center justify-content-center bg-light p-3 p-md-5">
        <div class="w-100" style="max-width: 400px;">
            <div class="d-flex flex-column gap-4">
                <div class="d-flex flex-column align-items-center gap-3">
                    <Link
                        :href="home()"
                        class="d-flex flex-column align-items-center text-decoration-none"
                    >
                        <div class="mb-2 d-flex align-items-center gap-2">
                            <img 
                                src="/logo.png" 
                                alt="Logo Gestion" 
                                style="width: 60px; height: 60px; object-fit: contain;"
                            />
                            <h2 class="mb-0 fw-bold text-primary">Gestion</h2>
                        </div>
                        <span class="visually-hidden">{{ title }}</span>
                    </Link>
                    <div class="text-center">
                        <h1 class="h4 mb-2 fw-medium">{{ title }}</h1>
                        <p class="text-muted mb-0">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
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
