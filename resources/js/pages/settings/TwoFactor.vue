<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { disable, enable, show } from '@/routes/two-factor';
import { BreadcrumbItem } from '@/types';
import { Form, Head } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';

interface Props {
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Authentification à deux facteurs',
        href: show.url(),
    },
];

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => {
    clearTwoFactorAuthData();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Authentification à deux facteurs" />
        <SettingsLayout>
            <div class="row g-4">
                <div class="col-12">
                    <HeadingSmall
                        title="Authentification à deux facteurs"
                        description="Gérez vos paramètres d'authentification à deux facteurs"
                    />

                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Authentification à deux facteurs</h5>
                                    <p class="text-muted mb-0">
                                        Ajoutez une couche de sécurité supplémentaire à votre compte
                                    </p>
                                </div>
                                <div>
                                    <span
                                        v-if="twoFactorEnabled"
                                        class="badge bg-success fs-6"
                                    >
                                        <i class="bi bi-shield-check me-1"></i>
                                        Activé
                                    </span>
                                    <span
                                        v-else
                                        class="badge bg-secondary fs-6"
                                    >
                                        <i class="bi bi-shield-x me-1"></i>
                                        Désactivé
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4">
                                <div v-if="twoFactorEnabled">
                                    <Form
                                        v-bind="disable.form()"
                                        v-slot="{ processing }"
                                    >
                                        <button
                                            type="submit"
                                            class="btn btn-outline-danger"
                                            :disabled="processing"
                                        >
                                            <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="bi bi-shield-x me-1"></i>
                                            {{ processing ? 'Désactivation...' : 'Désactiver' }}
                                        </button>
                                    </Form>
                                </div>

                                <div v-else>
                                    <Form
                                        v-bind="enable.form()"
                                        v-slot="{ processing }"
                                    >
                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                            :disabled="processing"
                                            @click="showSetupModal = true"
                                        >
                                            <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                                            <i v-else class="bi bi-shield-check me-1"></i>
                                            {{ processing ? 'Activation...' : 'Activer' }}
                                        </button>
                                    </Form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="hasSetupData" class="col-12">
                    <TwoFactorRecoveryCodes />
                </div>

                <TwoFactorSetupModal
                    v-if="showSetupModal"
                    @close="showSetupModal = false"
                />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>