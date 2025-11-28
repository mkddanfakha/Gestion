<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/password/confirm';
import { Form, Head } from '@inertiajs/vue3';
</script>

<template>
    <AuthLayout
        title="Confirmer votre mot de passe"
        description="Ceci est une zone sécurisée de l'application. Veuillez confirmer votre mot de passe avant de continuer."
    >
        <Head title="Confirmer le mot de passe" />

        <Form
            v-bind="store.form()"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <div class="row g-3">
                <div class="col-12">
                    <label for="password" class="form-label">Mot de passe</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="form-control"
                        required
                        autocomplete="current-password"
                        autofocus
                        :class="{ 'is-invalid': errors.password }"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="col-12">
                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                        :disabled="processing"
                        data-test="confirm-password-button"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-shield-check me-1"></i>
                        {{ processing ? 'Confirmation...' : 'Confirmer le mot de passe' }}
                    </button>
                </div>
            </div>
        </Form>
    </AuthLayout>
</template>