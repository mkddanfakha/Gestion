<template>
  <AppLayout>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h1 class="h2 mb-1">Modifier le client</h1>
        <p class="text-muted mb-0">{{ customer.name }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <Link :href="route('customers.show', { id: customer.id })" class="btn btn-outline-primary">
          <i class="bi bi-eye me-1"></i>
          Voir le client
        </Link>
        <Link :href="route('customers.index')" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <form @submit.prevent="submit">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations client</h5>
            </div>
            <div class="card-body">
              <CustomerFormFields
                :form="form"
                :errors="errors"
                :client-errors="clientErrors"
                :exclude-id="customer.id"
                id-prefix="customer-edit"
                @validate-field="validateField"
              />
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary" :disabled="processing">
              <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Modification...' : 'Modifier le client' }}
            </button>
            <Link :href="route('customers.show', { id: customer.id })" class="btn btn-outline-secondary">
              Annuler
            </Link>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import CustomerFormFields from '@/components/customers/CustomerFormFields.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { validateCustomerField, validateCustomerForm } from '@/composables/useCustomerFormValidation'

interface Customer {
  id: number
  name: string
  email?: string | null
  phone?: string | null
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

  const errorMessage = validateCustomerField(fieldName, value)
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const form = useForm({
  name: props.customer.name,
  email: props.customer.email || '',
  phone: props.customer.phone || '',
  identity_document_type: props.customer.identity_document_type || '',
  identity_document_number: props.customer.identity_document_number || '',
  birthday: props.customer.birthday || '',
  address: props.customer.address || '',
  city: props.customer.city || '',
  postal_code: props.customer.postal_code || '',
  country: props.customer.country || '',
  notes: props.customer.notes || '',
  is_active: props.customer.is_active,
})

const submit = () => {
  clientErrors.value = {}

  const validationErrors = validateCustomerForm(form)
  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }

  form.put(route('customers.update', { id: props.customer.id }), {
    onSuccess: () => {
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
