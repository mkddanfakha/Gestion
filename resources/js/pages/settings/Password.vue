<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/password';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useForm as useVeeForm } from 'vee-validate';
import * as yup from 'yup';
import { watch } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { route } from '@/lib/routes';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Paramètres du mot de passe',
        href: edit().url,
    },
];

const { success, error } = useSweetAlert();
const page = usePage();

// Schéma de validation VeeValidate
const schema = yup.object({
    current_password: yup.string().required('Le mot de passe actuel est requis'),
    password: yup
        .string()
        .required('Le nouveau mot de passe est requis')
        .min(8, 'Le mot de passe doit contenir au moins 8 caractères'),
    password_confirmation: yup
        .string()
        .required('La confirmation du mot de passe est requise')
        .oneOf([yup.ref('password')], 'Les mots de passe ne correspondent pas'),
});

const { handleSubmit, defineField, errors: veeErrors, resetForm, isSubmitting, setFieldError } = useVeeForm({
    validationSchema: schema,
    initialValues: {
        current_password: '',
        password: '',
        password_confirmation: '',
    },
});

const [currentPassword, currentPasswordAttrs] = defineField('current_password');
const [password, passwordAttrs] = defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation');

// Surveiller les erreurs de la page Inertia et les intégrer dans VeeValidate
watch(() => page.props.errors, (errors) => {
    if (errors && Object.keys(errors).length > 0) {
        if (errors.current_password) {
            const errorMessage = Array.isArray(errors.current_password) 
                ? errors.current_password[0] 
                : errors.current_password;
            setFieldError('current_password', errorMessage);
        }
        if (errors.password) {
            const errorMessage = Array.isArray(errors.password) 
                ? errors.password[0] 
                : errors.password;
            setFieldError('password', errorMessage);
        }
        if (errors.password_confirmation) {
            const errorMessage = Array.isArray(errors.password_confirmation) 
                ? errors.password_confirmation[0] 
                : errors.password_confirmation;
            setFieldError('password_confirmation', errorMessage);
        }
    }
}, { deep: true });

const onSubmit = handleSubmit((values) => {
    router.put(route('password.update'), values, {
        preserveScroll: true,
        onSuccess: () => {
            success('Mot de passe mis à jour avec succès.');
            resetForm();
        },
        onError: (errors) => {
            // Intégrer les erreurs du serveur dans VeeValidate
            if (errors.current_password) {
                const errorMessage = Array.isArray(errors.current_password) 
                    ? errors.current_password[0] 
                    : errors.current_password;
                setFieldError('current_password', errorMessage);
            }
            if (errors.password) {
                const errorMessage = Array.isArray(errors.password) 
                    ? errors.password[0] 
                    : errors.password;
                setFieldError('password', errorMessage);
            }
            if (errors.password_confirmation) {
                const errorMessage = Array.isArray(errors.password_confirmation) 
                    ? errors.password_confirmation[0] 
                    : errors.password_confirmation;
                setFieldError('password_confirmation', errorMessage);
            }
        },
    });
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Paramètres du mot de passe" />

        <SettingsLayout>
            <div class="row g-4">
                <div class="col-12">
                    <HeadingSmall
                        title="Mettre à jour le mot de passe"
                        description="Assurez-vous que votre compte utilise un mot de passe long et aléatoire pour rester sécurisé"
                    />

                    <form @submit="onSubmit">
                        <div class="card">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="current_password" class="form-label">Mot de passe actuel</label>
                                        <input
                                            id="current_password"
                                            v-model="currentPassword"
                                            v-bind="currentPasswordAttrs"
                                            type="password"
                                            class="form-control"
                                            :class="{ 'is-invalid': veeErrors.current_password }"
                                            autocomplete="current-password"
                                        />
                                        <div v-if="veeErrors.current_password" class="invalid-feedback d-block">
                                            {{ veeErrors.current_password }}
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label for="password" class="form-label">Nouveau mot de passe</label>
                                        <input
                                            id="password"
                                            v-model="password"
                                            v-bind="passwordAttrs"
                                            type="password"
                                            class="form-control"
                                            :class="{ 'is-invalid': veeErrors.password }"
                                            autocomplete="new-password"
                                        />
                                        <div v-if="veeErrors.password" class="invalid-feedback d-block">
                                            {{ veeErrors.password }}
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label for="password_confirmation" class="form-label">Confirmer le nouveau mot de passe</label>
                                        <input
                                            id="password_confirmation"
                                            v-model="passwordConfirmation"
                                            v-bind="passwordConfirmationAttrs"
                                            type="password"
                                            class="form-control"
                                            :class="{ 'is-invalid': veeErrors.password_confirmation }"
                                            autocomplete="new-password"
                                        />
                                        <div v-if="veeErrors.password_confirmation" class="invalid-feedback d-block">
                                            {{ veeErrors.password_confirmation }}
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                            :disabled="isSubmitting"
                                        >
                                            <span v-if="isSubmitting" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="bi bi-key me-1"></i>
                                            {{ isSubmitting ? 'Mise à jour...' : 'Mettre à jour le mot de passe' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
