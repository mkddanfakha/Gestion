<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { route } from '@/lib/routes';
import { Head, router } from '@inertiajs/vue3';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { ref } from 'vue';

defineProps<{
    status?: string;
}>();

const { success, error: showError } = useSweetAlert();
const processing = ref(false);

const logout = () => route('logout');
const send = () => route('verification.send');

const resendVerification = () => {
    processing.value = true;
    router.post(send(), {}, {
        onSuccess: (page) => {
            processing.value = false;
            const flash = (page.props as any)?.flash;
            if (flash?.status || flash?.success) {
                success('Un nouveau lien de vérification a été envoyé à votre adresse email.');
            } else {
                success('Un nouveau lien de vérification a été envoyé à votre adresse email.');
            }
        },
        onError: (errors) => {
            processing.value = false;
            const errorMessage = errors?.message || 'Une erreur est survenue lors de l\'envoi de l\'email de vérification.';
            showError(errorMessage);
        },
        onFinish: () => {
            processing.value = false;
        }
    });
};
</script>

<template>
    <AuthLayout
        title="Vérifier l'email"
        description="Veuillez vérifier votre adresse email en cliquant sur le lien que nous venons de vous envoyer."
    >
        <Head title="Vérification email" />

        <div class="text-center">
            <div class="row g-3">
                <div class="col-12">
                    <button
                        type="button"
                        @click="resendVerification"
                        class="btn btn-outline-secondary w-100"
                        :disabled="processing"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-envelope me-1"></i>
                        {{ processing ? 'Envoi en cours...' : 'Renvoyer l\'email de vérification' }}
                    </button>
                </div>

                <div class="col-12">
                    <TextLink
                        :href="logout()"
                        as="button"
                        class="btn btn-outline-danger w-100"
                    >
                        <i class="bi bi-box-arrow-right me-1"></i>
                        Se déconnecter
                    </TextLink>
                </div>
            </div>
        </div>
    </AuthLayout>
</template>