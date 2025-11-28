<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier le client</h1>
        <p class="text-muted mb-0">{{ customer.name }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('customers.show', { id: customer.id })"
          class="btn btn-outline-primary"
        >
          <i class="bi bi-eye me-1"></i>
          Voir le client
        </Link>
        <Link
          :href="route('customers.index')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour à la liste
        </Link>
      </div>
    </div>

    <form>
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <!-- Informations générales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations générales</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">
                    Nom complet <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.name || clientErrors.name }"
                    @blur="validateField('name', form.name)"
                    @input="validateField('name', form.name)"
                  />
                  <div v-if="errors.name" class="invalid-feedback">{{ errors.name }}</div>
                  <div v-if="clientErrors.name" class="invalid-feedback">{{ clientErrors.name }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': errors.email || clientErrors.email }"
                    @blur="validateField('email', form.email)"
                    @input="validateField('email', form.email)"
                  />
                  <div v-if="errors.email" class="invalid-feedback">{{ errors.email }}</div>
                  <div v-if="clientErrors.email" class="invalid-feedback">{{ clientErrors.email }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Téléphone</label>
                  <input
                    v-model="form.phone"
                    type="tel"
                    class="form-control"
                    :class="{ 'is-invalid': errors.phone || clientErrors.phone }"
                    @blur="validateField('phone', form.phone)"
                    @input="validateField('phone', form.phone)"
                  />
                  <div v-if="errors.phone" class="invalid-feedback">{{ errors.phone }}</div>
                  <div v-if="clientErrors.phone" class="invalid-feedback">{{ clientErrors.phone }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Limite de crédit <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <input
                      v-model.number="form.credit_limit"
                      type="number"
                      step="0.01"
                      min="0"
                      required
                      class="form-control"
                      :class="{ 'is-invalid': errors.credit_limit || clientErrors.credit_limit }"
                      @blur="validateField('credit_limit', form.credit_limit)"
                      @input="validateField('credit_limit', form.credit_limit)"
                    />
                    <span class="input-group-text">Fcfa</span>
                  </div>
                  <div v-if="errors.credit_limit" class="invalid-feedback">{{ errors.credit_limit }}</div>
                  <div v-if="clientErrors.credit_limit" class="invalid-feedback">{{ clientErrors.credit_limit }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Adresse -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Adresse</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Adresse</label>
                  <textarea
                    v-model="form.address"
                    rows="3"
                    class="form-control"
                    :class="{ 'is-invalid': errors.address }"
                  ></textarea>
                  <div v-if="errors.address" class="invalid-feedback">{{ errors.address }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Ville</label>
                  <input
                    v-model="form.city"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.city }"
                  />
                  <div v-if="errors.city" class="invalid-feedback">{{ errors.city }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Code postal</label>
                  <input
                    v-model="form.postal_code"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.postal_code }"
                  />
                  <div v-if="errors.postal_code" class="invalid-feedback">{{ errors.postal_code }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Pays</label>
                  <input
                    v-model="form.country"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.country }"
                  />
                  <div v-if="errors.country" class="invalid-feedback">{{ errors.country }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Statut -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Statut</h5>
            </div>
            <div class="card-body">
              <div class="form-check form-switch">
                <input
                  v-model="form.is_active"
                  class="form-check-input"
                  type="checkbox"
                  id="is_active"
                />
                <label class="form-check-label" for="is_active">
                  Client actif
                </label>
                <div class="form-text">
                  Un client inactif ne sera pas visible dans les ventes
                </div>
              </div>
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
              {{ processing ? 'Modification...' : 'Modifier le client' }}
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
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Customer {
  id: number
  name: string
  email?: string
  phone?: string
  address?: string
  city?: string
  postal_code?: string
  country?: string
  credit_limit: number
  is_active: boolean
}

interface Props {
  customer: Customer
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

// État des erreurs de validation côté client
const clientErrors = ref<Record<string, string>>({})

// Validation simple côté client
const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.name || form.name.trim().length < 2) {
    errors.name = 'Le nom du client est requis (minimum 2 caractères)'
  }
  
  if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Adresse email invalide'
  }
  
  if (form.phone && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(form.phone)) {
    errors.phone = 'Numéro de téléphone invalide'
  }
  
  if (form.credit_limit < 0) {
    errors.credit_limit = 'La limite de crédit ne peut pas être négative'
  }
  
  return Object.keys(errors).length === 0 ? null : errors
}

// Validation en temps réel pour un champ spécifique
const validateField = (fieldName: string, value: any) => {
  // Effacer l'erreur précédente pour ce champ
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'name':
      if (!value || value.trim().length < 2) {
        errorMessage = 'Le nom du client est requis (minimum 2 caractères)'
      }
      break
      
    case 'email':
      if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        errorMessage = 'Adresse email invalide'
      }
      break
      
    case 'phone':
      if (value && !/^[\+]?[0-9\s\-\(\)]{8,}$/.test(value)) {
        errorMessage = 'Numéro de téléphone invalide'
      }
      break
      
    case 'credit_limit':
      if (value < 0) {
        errorMessage = 'La limite de crédit ne peut pas être négative'
      }
      break
  }
  
  // Ajouter l'erreur si elle existe
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const form = useForm({
  name: props.customer.name,
  email: props.customer.email || '',
  phone: props.customer.phone || '',
  address: props.customer.address || '',
  city: props.customer.city || '',
  postal_code: props.customer.postal_code || '',
  country: props.customer.country || '',
  credit_limit: props.customer.credit_limit,
  is_active: props.customer.is_active,
})

const submit = () => {
  // Effacer les erreurs précédentes
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    // Afficher les erreurs dans le formulaire
    clientErrors.value = validationErrors
    return
  }
  
  form.put(route('customers.update', { id: props.customer.id }), {
    onSuccess: () => {
      success('Client modifié avec succès !')
      clientErrors.value = {} // Clear client errors on success
    },
    onError: () => {
      error('Erreur lors de la modification du client.')
    }
  })
}

const { errors, processing } = form
</script>