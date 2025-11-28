<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import { Form, Head, Link, usePage, router } from '@inertiajs/vue3';
import { useForm as useVeeForm } from 'vee-validate';
import * as yup from 'yup';
import { ref } from 'vue';

import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { usePermissions } from '@/composables/usePermissions';
import { route } from '@/lib/routes';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const page = usePage();
const user = page.props.auth.user;
const { success, error: showError } = useSweetAlert();
const { isAdmin } = usePermissions();
const resendingVerification = ref(false);

const resendVerificationEmail = () => {
    resendingVerification.value = true;
    router.post(send().url, {}, {
        onSuccess: (page) => {
            resendingVerification.value = false;
            const flash = (page.props as any)?.flash;
            if (flash?.status || flash?.success) {
                success('Un nouveau lien de vérification a été envoyé à votre adresse email.');
            } else {
                success('Un nouveau lien de vérification a été envoyé à votre adresse email.');
            }
        },
        onError: (errors) => {
            resendingVerification.value = false;
            const errorMessage = errors?.message || 'Une erreur est survenue lors de l\'envoi de l\'email de vérification.';
            showError(errorMessage);
        },
        onFinish: () => {
            resendingVerification.value = false;
        }
    });
};

// Schéma de validation VeeValidate
const schema = yup.object({
    name: yup.string().required('Le nom est requis').min(2, 'Le nom doit contenir au moins 2 caractères'),
    email: yup.string().required('L\'email est requis').email('L\'email doit être valide'),
});

const { handleSubmit, defineField, errors: veeErrors, isSubmitting, setFieldError } = useVeeForm({
    validationSchema: schema,
    initialValues: {
        name: user.name,
        email: user.email,
    },
});

const [name, nameAttrs] = defineField('name');
const [email, emailAttrs] = defineField('email');

const onSubmit = handleSubmit((values) => {
    router.patch(route('profile.update'), values, {
        onSuccess: () => {
            success('Profil mis à jour avec succès.');
        },
        onError: (errors) => {
            // Intégrer les erreurs du serveur dans VeeValidate
            if (errors.name) {
                const errorMessage = Array.isArray(errors.name) 
                    ? errors.name[0] 
                    : errors.name;
                setFieldError('name', errorMessage);
            }
            if (errors.email) {
                const errorMessage = Array.isArray(errors.email) 
                    ? errors.email[0] 
                    : errors.email;
                setFieldError('email', errorMessage);
            }
        },
    });
});
</script>

<template>
    <AppLayout>
        <Head title="Paramètres du profil" />

        <SettingsLayout>
            <div class="row g-4">
                <div class="col-12">
                    <HeadingSmall
                        title="Informations du profil"
                        description="Mettez à jour votre nom et votre adresse email"
                    />

                    <form @submit="onSubmit">
                        <div class="card">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="name" class="form-label">Nom</label>
                                        <input
                                            id="name"
                                            v-model="name"
                                            v-bind="nameAttrs"
                                            type="text"
                                            class="form-control"
                                            :class="{ 'is-invalid': veeErrors.name }"
                                            required
                                            autofocus
                                            autocomplete="name"
                                        />
                                        <div v-if="veeErrors.name" class="invalid-feedback d-block">
                                            {{ veeErrors.name }}
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="email" class="form-label">Email</label>
                                        <input
                                            id="email"
                                            v-model="email"
                                            v-bind="emailAttrs"
                                            type="email"
                                            class="form-control"
                                            :class="{ 'is-invalid': veeErrors.email }"
                                            required
                                            autocomplete="username"
                                        />
                                        <div v-if="veeErrors.email" class="invalid-feedback d-block">
                                            {{ veeErrors.email }}
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                            :disabled="isSubmitting"
                                        >
                                            <span v-if="isSubmitting" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="bi bi-check-circle me-1"></i>
                                            {{ isSubmitting ? 'Sauvegarde...' : 'Sauvegarder' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="col-12">
                    <HeadingSmall
                        title="Vérification de l'email"
                        description="Vérifiez votre adresse email pour sécuriser votre compte"
                    />

                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-1">
                                        {{ user.email }}
                                        <span
                                            v-if="user.email_verified_at"
                                            class="badge bg-success ms-2"
                                        >
                                            <i class="bi bi-check-circle me-1"></i>
                                            Vérifié
                                        </span>
                                        <span
                                            v-else
                                            class="badge bg-warning text-dark ms-2"
                                        >
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            Non vérifié
                                        </span>
                                    </p>
                                    <small class="text-muted">
                                        {{ user.email_verified_at
                                            ? 'Votre email a été vérifié avec succès.'
                                            : 'Votre email n\'est pas vérifié.' }}
                                    </small>
                                </div>

                                <div v-if="!user.email_verified_at">
                                    <button
                                        type="button"
                                        @click="resendVerificationEmail"
                                        class="btn btn-outline-primary"
                                        :disabled="resendingVerification"
                                    >
                                        <span v-if="resendingVerification" class="spinner-border spinner-border-sm me-2"></span>
                                        <i v-else class="bi bi-envelope me-1"></i>
                                        {{ resendingVerification ? 'Envoi...' : 'Renvoyer l\'email de vérification' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <HeadingSmall
                        title="Supprimer le compte"
                        description="Gestion de la suppression de compte"
                    />

                    <div class="card border-warning">
                        <div class="card-body">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Information :</strong> La suppression de compte n'est pas disponible depuis cette page. 
                                <span v-if="isAdmin">
                                    Veuillez utiliser la section <Link :href="route('admin.users.index')" class="alert-link">Administration</Link> pour gérer les utilisateurs.
                                </span>
                                <span v-else>
                                    Veuillez contacter un administrateur pour supprimer votre compte.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
