<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <AuthBase
        title="Connexion à votre compte"
        description="Entrez votre email et mot de passe pour vous connecter"
    >
        <Head title="Connexion" />

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
                    v-bind="store.form()"
                    :reset-on-success="['password']"
                    v-slot="{ errors, processing }"
                    class="needs-validation"
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
                                required
                                autofocus
                                :tabindex="1"
                                autocomplete="email"
                                placeholder="email@example.com"
                                class="form-control"
                                :class="{ 'is-invalid': errors.email }"
                            />
                        </div>
                        <InputError :message="errors.email" />
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label for="password" class="form-label fw-medium mb-0">
                                Mot de passe
                            </label>
                            <TextLink
                                v-if="canResetPassword"
                                :href="request()"
                                class="text-decoration-none small"
                                :tabindex="5"
                            >
                                Mot de passe oublié ?
                            </TextLink>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock text-muted"></i>
                            </span>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                required
                                :tabindex="2"
                                autocomplete="current-password"
                                placeholder="Mot de passe"
                                class="form-control"
                                :class="{ 'is-invalid': errors.password }"
                            />
                        </div>
                        <InputError :message="errors.password" />
                    </div>

                    <div class="mb-4 form-check">
                        <input
                            id="remember"
                            type="checkbox"
                            name="remember"
                            :tabindex="3"
                            class="form-check-input"
                        />
                        <label for="remember" class="form-check-label">
                            Se souvenir de moi
                        </label>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary w-100 py-2 fw-medium"
                        :tabindex="4"
                        :disabled="processing"
                        data-test="login-button"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-box-arrow-in-right me-2"></i>
                        Se connecter
                    </button>

                </Form>
            </div>
        </div>
    </AuthBase>
</template>
