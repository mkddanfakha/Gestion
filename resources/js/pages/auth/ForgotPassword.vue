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
        title="Réinitialiser votre mot de passe"
        description="Saisissez votre adresse e-mail pour recevoir un lien permettant de réinitialiser votre mot de passe."
    >
        <Head title="Mot de passe oublié" />

        <div
            v-if="status"
            class="mkd-auth__success"
            role="status"
        >
            <i class="bi bi-envelope-check-fill" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">Vérifiez votre boîte mail</strong>
                {{ status }}
            </div>
        </div>

        <Form
            v-bind="PasswordResetLinkController.store.form()"
            v-slot="{ errors, processing }"
        >
            <div class="mb-3">
                <label for="email" class="form-label">
                    Adresse e-mail
                </label>
                <div class="input-group">
                    <span class="input-group-text" aria-hidden="true">
                        <i class="bi bi-envelope"></i>
                    </span>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        autofocus
                        placeholder="vous@exemple.com"
                        class="form-control"
                        :class="{ 'is-invalid': errors.email }"
                        :aria-invalid="errors.email ? 'true' : undefined"
                        :aria-describedby="errors.email ? 'forgot-email-error' : undefined"
                        required
                    />
                </div>
                <InputError id="forgot-email-error" :message="errors.email" />
            </div>

            <button
                type="submit"
                class="mkd-btn-primary w-100 mb-3"
                :disabled="processing"
            >
                <span
                    v-if="processing"
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                ></span>
                <i v-else class="bi bi-send" aria-hidden="true"></i>
                {{ processing ? 'Envoi...' : 'Envoyer le lien' }}
            </button>

            <div class="text-center">
                <TextLink :href="login()" class="text-decoration-none">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                    Retour à la connexion
                </TextLink>
            </div>
        </Form>
    </AuthLayout>
</template>
