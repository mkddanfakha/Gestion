<template>
  <AppLayout>
    <FormPageLayout>
      <FormPageHeader
        title="Nouveau client"
        subtitle="Ajoutez un nouveau client à votre base"
        :back-href="route('customers.index')"
        back-label="Retour à la liste"
      >
        <template #meta>
          <DraftSaveStatus :status="draft.status" :last-saved-at="draft.lastSavedAt" />
        </template>
      </FormPageHeader>

      <DraftRestoreDialog
        :visible="draft.showRestoreDialog"
        mode="create"
        :config="draft.config"
        :draft="draft.pendingDraft"
        @restore="draft.restoreDraft()"
        @dismiss="draft.dismissDraft()"
      />

      <form>
        <div class="form-page__body">
          <FormSection title="Informations client">
            <CustomerFormFields
              :form="form"
              :errors="errors"
              :client-errors="clientErrors"
              id-prefix="customer-create"
              @validate-field="validateField"
            />
          </FormSection>

          <FormActionsBar class="form-actions-bar--split">
            <Link
              :href="route('customers.index')"
              class="btn btn-outline-secondary"
            >
              Annuler
            </Link>
            <button
              type="button"
              class="btn btn-primary"
              :disabled="processing"
              @click="submit"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Création...' : 'Créer le client' }}
            </button>
          </FormActionsBar>
        </div>
      </form>
    </FormPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import FormPageLayout from '@/components/page/FormPageLayout.vue'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import FormSection from '@/components/page/FormSection.vue'
import FormActionsBar from '@/components/page/FormActionsBar.vue'
import CustomerFormFields from '@/components/customers/CustomerFormFields.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { validateCustomerField, validateCustomerForm } from '@/composables/useCustomerFormValidation'
import { useFormDraft } from '@/composables/useFormDraft'
import DraftSaveStatus from '@/components/drafts/DraftSaveStatus.vue'
import DraftRestoreDialog from '@/components/drafts/DraftRestoreDialog.vue'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'

const { success, error } = useSweetAlert()

const clientErrors = ref<Record<string, string>>({})

const customerCreateBaseline = {
  name: '',
  email: '',
  phone: '',
  identity_document_type: '',
  identity_document_number: '',
  birthday: '',
  address: '',
  city: '',
  postal_code: '',
  country: '',
  notes: '',
  is_active: true,
}

const validateField = (fieldName: string, value: unknown) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }

  const errorMessage = validateCustomerField(fieldName, value)
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const form = useForm({ ...customerCreateBaseline })

const draft = useFormDraft({
  formType: 'customer',
  mode: 'create',
  watchSource: form,
  getData: () => form.data() as Record<string, unknown>,
  restoreData: (data) => restoreInertiaFormData(form as unknown as Record<string, unknown>, data),
  getBaseline: () => customerCreateBaseline,
})

const submit = () => {
  clientErrors.value = {}

  const validationErrors = validateCustomerForm(form)

  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }

  form.post(route('customers.store'), {
    onSuccess: async () => {
      await draft.markSubmitted()
      success('Client créé avec succès !')
      form.reset()
      clientErrors.value = {}
    },
    onError: () => {
      error('Erreur lors de la création du client.')
    },
  })
}

const { errors, processing } = form
</script>
