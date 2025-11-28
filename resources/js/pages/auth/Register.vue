<script setup lang="ts">
import RegisteredUserController from '@/actions/App/Http/Controllers/Auth/RegisteredUserController';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { Form, Head } from '@inertiajs/vue3';
</script>

<template>
    <AuthBase
        title="Créer un compte"
        description="Entrez vos informations ci-dessous pour créer votre compte"
    >
        <Head title="Inscription" />

        <Form
            v-bind="RegisteredUserController.store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
        >
            <div class="row g-3">
                <div class="col-12">
                    <label for="name" class="form-label">Nom complet</label>
                    <input
                        id="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Nom complet"
                        class="form-control"
                        :class="{ 'is-invalid': errors.name }"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="col-12">
                    <label for="email" class="form-label">Adresse email</label>
                    <input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="nom@exemple.com"
                        class="form-control"
                        :class="{ 'is-invalid': errors.email }"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="col-12">
                    <label for="password" class="form-label">Mot de passe</label>
                    <input
                        id="password"
                        type="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        name="password"
                        placeholder="••••••••"
                        class="form-control"
                        :class="{ 'is-invalid': errors.password }"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="col-12">
                    <label for="password_confirmation" class="form-label">Confirmer le mot de passe</label>
                    <input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="••••••••"
                        class="form-control"
                        :class="{ 'is-invalid': errors.password_confirmation }"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div class="col-12">
                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                        :disabled="processing"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-person-plus me-1"></i>
                        {{ processing ? 'Création du compte...' : 'Créer le compte' }}
                    </button>
                </div>

                <div class="col-12 text-center">
                    <TextLink :href="login()" class="text-decoration-none">
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        Déjà un compte ? Se connecter
                    </TextLink>
                </div>
            </div>
        </Form>
    </AuthBase>
</template>