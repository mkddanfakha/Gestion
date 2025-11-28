<script setup lang="ts">
import PasswordResetLinkController from '@/actions/App/Http/Controllers/Auth/PasswordResetLinkController';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout
        title="Mot de passe oublié"
        description="Entrez votre email pour recevoir un lien de réinitialisation"
    >
        <Head title="Mot de passe oublié" />

        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div
                    v-if="status"
                    class="alert alert-success text-center mb-4"
                >
                    <i class="bi bi-check-circle me-2"></i>
                    {{ status }}
                </div>

                <Form
                    v-bind="PasswordResetLinkController.store.form()"
                    v-slot="{ errors, processing }"
                >
                    <div class="mb-3">
                        <label for="email" class="form-label fw-medium">
                            Adresse email
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-envelope text-muted"></i>
                            </span>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                autocomplete="off"
                                autofocus
                                placeholder="email@example.com"
                                class="form-control"
                                :class="{ 'is-invalid': errors.email }"
                                required
                            />
                        </div>
                        <InputError :message="errors.email" />
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary w-100 py-2 fw-medium mb-3"
                        :disabled="processing"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-envelope me-2"></i>
                        {{ processing ? 'Envoi en cours...' : 'Envoyer le lien de réinitialisation' }}
                    </button>

                    <div class="text-center">
                        <TextLink :href="login()" class="text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i>
                            Retour à la connexion
                        </TextLink>
                    </div>
                </Form>
            </div>
        </div>
    </AuthLayout>
</template>