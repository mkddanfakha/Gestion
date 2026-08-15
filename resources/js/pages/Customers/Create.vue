<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouveau client</h1>
        <p class="text-muted mb-0">Ajoutez un nouveau client à votre base</p>
      </div>
      <Link
        :href="route('customers.index')"
        class="btn btn-outline-secondary"
      >
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <form>
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
                id-prefix="customer-create"
                @validate-field="validateField"
              />
            </div>
          </div>

          <!-- Boutons -->
          <div class="d-flex gap-2">
            <button
              type="button"
              @click="submit"
              class="btn btn-primary"
              :disabled="processing"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Création...' : 'Créer le client' }}
            </button>
            <Link
              :href="route('customers.index')"
              class="btn btn-outline-secondary"
            >
              Annuler
            </Link>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import CustomerFormFields from '@/components/customers/CustomerFormFields.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { validateCustomerField, validateCustomerForm } from '@/composables/useCustomerFormValidation'

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
})

const submit = () => {
  // Effacer les erreurs précédentes
  clientErrors.value = {}
  
  const validationErrors = validateCustomerForm(form)
  
  if (validationErrors) {
    // Afficher les erreurs dans le formulaire
    clientErrors.value = validationErrors
    return
  }
  
  form.post(route('customers.store'), {
    onSuccess: () => {
      success('Client créé avec succès !')
      form.reset()
      clientErrors.value = {}
    },
    onError: () => {
      error('Erreur lors de la création du client.')
    }
  })
}

const { errors, processing } = form
</script>