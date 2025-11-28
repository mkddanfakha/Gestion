<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/two-factor/login';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface AuthConfigContent {
    title: string;
    description: string;
    toggleText: string;
}

const authConfigContent = computed<AuthConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Code de récupération',
            description:
                'Veuillez confirmer l\'accès à votre compte en entrant l\'un de vos codes de récupération d\'urgence.',
            toggleText: 'se connecter avec un code d\'authentification',
        };
    }

    return {
        title: 'Code d\'authentification',
        description:
            'Entrez le code d\'authentification fourni par votre application d\'authentification.',
        toggleText: 'se connecter avec un code de récupération',
    };
});

const showRecoveryInput = ref<boolean>(false);

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};

const code = ref<string>('');
</script>

<template>
    <AuthLayout
        :title="authConfigContent.title"
        :description="authConfigContent.description"
    >
        <Head title="Authentification à deux facteurs" />

        <Form
            v-bind="store.form()"
            :transform="(data) => ({ ...data, code: code })"
            reset-on-success
            v-slot="{ errors, processing, clearErrors }"
        >
            <div class="row g-3">
                <div class="col-12">
                    <label for="code" class="form-label">
                        {{ showRecoveryInput ? 'Code de récupération' : 'Code d\'authentification' }}
                    </label>
                    <input
                        id="code"
                        type="text"
                        v-model="code"
                        class="form-control text-center font-monospace"
                        :class="{ 'is-invalid': errors.code }"
                        :placeholder="showRecoveryInput ? 'Entrez le code de récupération' : 'Entrez le code à 6 chiffres'"
                        maxlength="6"
                        autocomplete="one-time-code"
                        autofocus
                    />
                    <InputError :message="errors.code" />
                </div>

                <div class="col-12">
                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                        :disabled="processing || code.length === 0"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-shield-check me-1"></i>
                        {{ processing ? 'Vérification...' : 'Confirmer' }}
                    </button>
                </div>

                <div class="col-12 text-center">
                    <button
                        type="button"
                        @click="toggleRecoveryMode(clearErrors)"
                        class="btn btn-link text-decoration-none"
                    >
                        <i class="bi bi-arrow-repeat me-1"></i>
                        {{ authConfigContent.toggleText }}
                    </button>
                </div>
            </div>
        </Form>
    </AuthLayout>
</template>