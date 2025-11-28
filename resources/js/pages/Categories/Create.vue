<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouvelle catégorie</h1>
        <p class="text-muted mb-0">Créez une nouvelle catégorie de produits</p>
      </div>
      <Link
        :href="route('categories.index')"
        class="btn btn-outline-secondary"
      >
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-body">
            <form>
              <!-- Nom -->
              <div class="mb-3">
                <label class="form-label">
                  Nom de la catégorie <span class="text-danger">*</span>
                </label>
                <input
                  v-model="form.name"
                  type="text"
                  required
                  class="form-control"
                  :class="{ 'is-invalid': errors.name || clientErrors.name }"
                  placeholder="Ex: Électronique, Vêtements, Alimentaire..."
                  @blur="validateField('name', form.name)"
                  @input="validateField('name', form.name)"
                />
                <div v-if="errors.name" class="invalid-feedback">
                  {{ errors.name }}
                </div>
                <div v-if="clientErrors.name" class="invalid-feedback">
                  {{ clientErrors.name }}
                </div>
              </div>

              <!-- Description -->
              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea
                  v-model="form.description"
                  rows="3"
                  class="form-control"
                  :class="{ 'is-invalid': errors.description || clientErrors.description }"
                  placeholder="Description de la catégorie (optionnel)"
                  @blur="validateField('description', form.description)"
                  @input="validateField('description', form.description)"
                ></textarea>
                <div v-if="errors.description" class="invalid-feedback">
                  {{ errors.description }}
                </div>
                <div v-if="clientErrors.description" class="invalid-feedback">
                  {{ clientErrors.description }}
                </div>
              </div>

              <!-- Couleur -->
              <div class="mb-3">
                <label class="form-label">
                  Couleur <span class="text-danger">*</span>
                </label>
                <div class="row g-2">
                  <div class="col-auto">
                    <input
                      v-model="form.color"
                      type="color"
                      required
                      class="form-control form-control-color"
                      :class="{ 'is-invalid': errors.color || clientErrors.color }"
                      @change="validateField('color', form.color)"
                    />
                  </div>
                  <div class="col">
                    <input
                      v-model="form.color"
                      type="text"
                      required
                      class="form-control font-monospace"
                      :class="{ 'is-invalid': errors.color || clientErrors.color }"
                      placeholder="#3B82F6"
                      pattern="^#[0-9A-Fa-f]{6}$"
                      @blur="validateField('color', form.color)"
                      @input="validateField('color', form.color)"
                    />
                    <div class="form-text">
                      Format hexadécimal (ex: #3B82F6)
                    </div>
                  </div>
                </div>
                <div v-if="errors.color" class="text-danger small">
                  {{ errors.color }}
                </div>
                <div v-if="clientErrors.color" class="text-danger small">
                  {{ clientErrors.color }}
                </div>
              </div>

              <!-- Aperçu -->
              <div class="mb-4">
                <label class="form-label">Aperçu</label>
                <div class="p-3 border rounded bg-light">
                  <div class="d-flex align-items-center">
                    <div
                      class="rounded-circle me-3"
                      style="width: 16px; height: 16px;"
                      :style="{ backgroundColor: form.color }"
                    ></div>
                    <div>
                      <div class="fw-medium">{{ form.name || 'Nom de la catégorie' }}</div>
                      <div class="text-muted small">{{ form.description || 'Description de la catégorie' }}</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Boutons -->
              <div class="d-flex gap-2">
                <button
                  type="button"
                  class="btn btn-primary"
                  :disabled="processing"
                  @click="submit"
                >
                  <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
                  <i v-else class="bi bi-check-circle me-1"></i>
                  {{ processing ? 'Création...' : 'Créer la catégorie' }}
                </button>
                <Link
                  :href="route('categories.index')"
                  class="btn btn-outline-secondary"
                >
                  Annuler
                </Link>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { ref } from 'vue'

const { success, error } = useSweetAlert()

// État des erreurs de validation côté client
const clientErrors = ref<Record<string, string>>({})

const form = useForm({
  name: '',
  description: '',
  color: '#3B82F6',
})

// Validation simple côté client
const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.name || form.name.trim().length < 2) {
    errors.name = 'Le nom de la catégorie est requis (minimum 2 caractères)'
  }
  
  if (form.name && form.name.length > 255) {
    errors.name = 'Le nom ne peut pas dépasser 255 caractères'
  }
  
  if (form.description && form.description.length > 1000) {
    errors.description = 'La description ne peut pas dépasser 1000 caractères'
  }
  
  if (!form.color || !/^#[0-9A-Fa-f]{6}$/.test(form.color)) {
    errors.color = 'La couleur est requise et doit être au format hexadécimal (#RRGGBB)'
  }
  
  return Object.keys(errors).length === 0 ? null : errors
}

// Validation en temps réel d'un champ
const validateField = (fieldName: string, value: any) => {
  if (clientErrors.value[fieldName]) {
    delete clientErrors.value[fieldName]
  }
  
  let errorMessage = ''
  
  switch (fieldName) {
    case 'name':
      if (!value || value.trim().length < 2) {
        errorMessage = 'Le nom de la catégorie est requis (minimum 2 caractères)'
      } else if (value.length > 255) {
        errorMessage = 'Le nom ne peut pas dépasser 255 caractères'
      }
      break
      
    case 'description':
      if (value && value.length > 1000) {
        errorMessage = 'La description ne peut pas dépasser 1000 caractères'
      }
      break
      
    case 'color':
      if (!value || !/^#[0-9A-Fa-f]{6}$/.test(value)) {
        errorMessage = 'La couleur est requise et doit être au format hexadécimal (#RRGGBB)'
      }
      break
  }
  
  if (errorMessage) {
    clientErrors.value[fieldName] = errorMessage
  }
}

const submit = () => {
  // Effacer les erreurs précédentes
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    // Afficher les erreurs dans le formulaire
    clientErrors.value = validationErrors
    return
  }
  
  form.post(route('categories.store'), {
    onSuccess: () => {
      success('Catégorie créée avec succès !')
      clientErrors.value = {} // Clear client errors on success
    },
    onError: () => {
      error('Erreur lors de la création de la catégorie.')
    }
  })
}

const { errors, processing } = form
</script>
