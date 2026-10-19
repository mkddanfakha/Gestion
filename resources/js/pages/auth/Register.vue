<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <AuthBase
        title="Inscription"
        description="La création de compte en ligne n'est pas proposée sur cette application."
    >
        <Head title="Inscription" />

        <div class="alert alert-secondary mb-0" role="status">
            Inscription indisponible. Demandez à un administrateur la création de votre compte.
        </div>

        <div class="col-12 text-center mt-3">
            <TextLink :href="login()" class="text-decoration-none">
                <i class="bi bi-box-arrow-in-right me-1"></i>
                Retour à la connexion
            </TextLink>
        </div>
    </AuthBase>
</template>
