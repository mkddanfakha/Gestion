<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useForm as useVeeForm } from 'vee-validate';
import * as yup from 'yup';
import { watch, ref } from 'vue';
import { route } from '@/lib/routes';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps<{
    token: string;
    email: string;
}>();

const { success } = useSweetAlert();
const page = usePage();
const inputEmail = ref(props.email);

// Schéma de validation VeeValidate
const schema = yup.object({
    email: yup.string().required('L\'email est requis').email('L\'email doit être valide'),
    password: yup
        .string()
        .required('Le nouveau mot de passe est requis')
        .min(8, 'Le mot de passe doit contenir au moins 8 caractères'),
    password_confirmation: yup
        .string()
        .required('La confirmation du mot de passe est requise')
        .oneOf([yup.ref('password')], 'Les mots de passe ne correspondent pas'),
});

const { handleSubmit, defineField, errors: veeErrors, isSubmitting, setFieldError } = useVeeForm({
    validationSchema: schema,
    initialValues: {
        email: props.email,
        password: '',
        password_confirmation: '',
    },
});

const [email, emailAttrs] = defineField('email');
const [password, passwordAttrs] = defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation');

// Surveiller les erreurs de la page Inertia et les intégrer dans VeeValidate
watch(() => page.props.errors, (errors) => {
    if (errors && Object.keys(errors).length > 0) {
        if (errors.email) {
            const errorMessage = Array.isArray(errors.email) 
                ? errors.email[0] 
                : errors.email;
            setFieldError('email', errorMessage);
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
    router.post(route('password.store'), {
        ...values,
        token: props.token,
    }, {
        onSuccess: () => {
            success('Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.');
        },
    });
});
</script>

<template>
    <AuthLayout
        title="Réinitialiser le mot de passe"
        description="Veuillez entrer votre nouveau mot de passe ci-dessous"
    >
        <Head title="Réinitialiser le mot de passe" />

        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <form @submit="onSubmit">
                    <div class="mb-3">
                        <label for="email" class="form-label fw-medium">Email</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-envelope text-muted"></i>
                            </span>
                            <input
                                id="email"
                                v-model="email"
                                v-bind="emailAttrs"
                                type="email"
                                class="form-control"
                                :class="{ 'is-invalid': veeErrors.email }"
                                readonly
                                autocomplete="email"
                            />
                        </div>
                        <div v-if="veeErrors.email" class="invalid-feedback d-block">
                            {{ veeErrors.email }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-medium">Nouveau mot de passe</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock text-muted"></i>
                            </span>
                            <input
                                id="password"
                                v-model="password"
                                v-bind="passwordAttrs"
                                type="password"
                                class="form-control"
                                :class="{ 'is-invalid': veeErrors.password }"
                                placeholder="••••••••"
                                autocomplete="new-password"
                            />
                        </div>
                        <div v-if="veeErrors.password" class="invalid-feedback d-block">
                            {{ veeErrors.password }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label fw-medium">Confirmer le mot de passe</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock text-muted"></i>
                            </span>
                            <input
                                id="password_confirmation"
                                v-model="passwordConfirmation"
                                v-bind="passwordConfirmationAttrs"
                                type="password"
                                class="form-control"
                                :class="{ 'is-invalid': veeErrors.password_confirmation }"
                                placeholder="••••••••"
                                autocomplete="new-password"
                            />
                        </div>
                        <div v-if="veeErrors.password_confirmation" class="invalid-feedback d-block">
                            {{ veeErrors.password_confirmation }}
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary w-100 py-2 fw-medium"
                        :disabled="isSubmitting"
                    >
                        <span v-if="isSubmitting" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-key me-2"></i>
                        {{ isSubmitting ? 'Réinitialisation...' : 'Réinitialiser le mot de passe' }}
                    </button>
                </form>
            </div>
        </div>
    </AuthLayout>
</template>
