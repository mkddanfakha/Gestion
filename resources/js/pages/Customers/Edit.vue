<template>
  <AppLayout>
    <FormPageLayout>
      <FormPageHeader
        title="Modifier le client"
        :subtitle="customer.name"
        :back-href="route('customers.index')"
        back-label="Retour à la liste"
      >
        <template #meta>
          <DraftSaveStatus :status="draft.status" :last-saved-at="draft.lastSavedAt" />
        </template>
        <template #actions>
          <Link :href="route('customers.show', { id: customer.id })" class="btn btn-outline-primary">
            <i class="bi bi-eye me-1"></i>
            Voir le client
          </Link>
        </template>
      </FormPageHeader>

      <DraftRestoreDialog
        :visible="draft.showRestoreDialog"
        mode="edit"
        :config="draft.config"
        :draft="draft.pendingDraft"
        @restore="draft.restoreDraft()"
        @dismiss="draft.dismissDraft()"
      />

      <form @submit.prevent="submit">
        <div class="form-page__body">
          <FormSection title="Informations client">
            <CustomerFormFields
              :form="form"
              :errors="errors"
              :client-errors="clientErrors"
              :exclude-id="customer.id"
              id-prefix="customer-edit"
              @validate-field="validateField"
            />
          </FormSection>

          <FormActionsBar class="form-actions-bar--split">
            <Link :href="route('customers.show', { id: customer.id })" class="btn btn-outline-secondary">
              Annuler
            </Link>
            <button type="submit" class="btn btn-primary" :disabled="processing">
              <span v-if="processing" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Modification...' : 'Modifier le client' }}
            </button>
          </FormActionsBar>
        </div>
      </form>
    </FormPageLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import FormPageLayout from '@/components/page/FormPageLayout.vue'
import FormPageHeader from '@/components/page/FormPageHeader.vue'
import FormSection from '@/components/page/FormSection.vue'
import FormActionsBar from '@/components/page/FormActionsBar.vue'
import CustomerFormFields from '@/components/customers/CustomerFormFields.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { validateCustomerField, validateCustomerForm } from '@/composables/useCustomerFormValidation'
import { useFormDraft } from '@/composables/useFormDraft'
import DraftSaveStatus from '@/components/drafts/DraftSaveStatus.vue'
import DraftRestoreDialog from '@/components/drafts/DraftRestoreDialog.vue'
import { restoreInertiaFormData } from '@/drafts/restoreInertiaForm'

interface Customer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
  nationality?: string | null
  identity_document_type?: string | null
  identity_document_number?: string | null
  birthday?: string | null
  address?: string | null
  city?: string | null
  postal_code?: string | null
  country?: string | null
  notes?: string | null
  is_active: boolean
}

const props = defineProps<{
  customer: Customer
}>()

const { success, error } = useSweetAlert()
const clientErrors = ref<Record<string, string>>({})

const validateField = (fieldName: string, value: unknown) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }

  const errorMessage = validateCustomerField(fieldName, value, form)
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const customerEditBaseline = {
  name: props.customer.name,
  email: props.customer.email || '',
  phone: props.customer.phone || '',
  nationality: props.customer.nationality || '',
  identity_document_type: props.customer.identity_document_type || '',
  identity_document_number: props.customer.identity_document_number || '',
  birthday: props.customer.birthday || '',
  address: props.customer.address || '',
  city: props.customer.city || '',
  postal_code: props.customer.postal_code || '',
  country: props.customer.country || '',
  notes: props.customer.notes || '',
  is_active: props.customer.is_active,
}

const form = useForm({ ...customerEditBaseline })

const draft = useFormDraft({
  formType: 'customer',
  mode: 'edit',
  entityId: props.customer.id,
  watchSource: form,
  getData: () => form.data() as Record<string, unknown>,
  restoreData: (data) => restoreInertiaFormData(form as unknown as Record<string, unknown>, data),
  getBaseline: () => customerEditBaseline,
})

const submit = () => {
  clientErrors.value = {}

  const validationErrors = validateCustomerForm(form)
  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }

  form.put(route('customers.update', { id: props.customer.id }), {
    onSuccess: async () => {
      await draft.markSubmitted()
      success('Client modifié avec succès !')
      clientErrors.value = {}
    },
    onError: () => {
      error('Erreur lors de la modification du client.')
    },
  })
}

const { errors, processing } = form
</script>
