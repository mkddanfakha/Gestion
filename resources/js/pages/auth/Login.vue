<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const showPassword = ref(false);
</script>

<template>
    <AuthBase
        title="Connexion"
        description="Entrez votre e-mail et votre mot de passe pour accéder à MKD-Pro."
    >
        <Head title="Connexion" />

        <div
            v-if="status"
            class="mkd-auth__success"
            role="status"
        >
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <div>{{ status }}</div>
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="needs-validation"
        >
            <div
                v-if="errors.email || errors.password"
                class="mkd-auth__error"
                role="alert"
            >
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <div>
                    {{ errors.email || errors.password }}
                </div>
            </div>

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
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="vous@exemple.com"
                        class="form-control"
                        :class="{ 'is-invalid': errors.email }"
                        :aria-invalid="errors.email ? 'true' : undefined"
                        :aria-describedby="errors.email ? 'email-error' : undefined"
                    />
                </div>
                <InputError id="email-error" :message="errors.email" />
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label for="password" class="form-label mb-0">
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
                    <span class="input-group-text" aria-hidden="true">
                        <i class="bi bi-lock"></i>
                    </span>
                    <input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Mot de passe"
                        class="form-control"
                        :class="{ 'is-invalid': errors.password }"
                        :aria-invalid="errors.password ? 'true' : undefined"
                        :aria-describedby="errors.password ? 'password-error' : undefined"
                    />
                    <button
                        type="button"
                        class="btn btn-outline-secondary mkd-password-toggle"
                        :tabindex="6"
                        :aria-pressed="showPassword"
                        :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                        @click="showPassword = !showPassword"
                    >
                        <i
                            class="bi"
                            :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"
                            aria-hidden="true"
                        ></i>
                    </button>
                </div>
                <InputError id="password-error" :message="errors.password" />
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
                class="mkd-btn-primary w-100"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <span
                    v-if="processing"
                    class="spinner-border spinner-border-sm"
                    role="status"
                    aria-hidden="true"
                ></span>
                <i v-else class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                {{ processing ? 'Connexion...' : 'Se connecter' }}
            </button>
        </Form>
    </AuthBase>
</template>
