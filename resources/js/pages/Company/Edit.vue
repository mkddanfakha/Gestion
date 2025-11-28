<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Informations de l'entreprise</h1>
        <p class="text-muted mb-0">Gérer les informations affichées sur les factures</p>
      </div>
      <div class="d-flex gap-2">
        <Link
          :href="route('dashboard')"
          class="btn btn-outline-secondary"
        >
          <i class="bi bi-arrow-left me-1"></i>
          Retour
        </Link>
      </div>
    </div>

    <form @submit.prevent="submit">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <!-- Informations générales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations générales</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-12">
                  <label class="form-label">
                    Nom de l'entreprise <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.name }"
                    placeholder="ENTREPRISE SARL"
                  />
                  <div v-if="errors.name" class="invalid-feedback">{{ errors.name }}</div>
                </div>

                <div class="col-md-12">
                  <label class="form-label">Slogan / Tagline</label>
                  <input
                    v-model="form.tagline"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.tagline }"
                    placeholder="Votre partenaire de confiance"
                  />
                  <div v-if="errors.tagline" class="invalid-feedback">{{ errors.tagline }}</div>
                </div>

                <div class="col-md-12">
                  <label class="form-label">Adresse</label>
                  <textarea
                    v-model="form.address"
                    class="form-control"
                    :class="{ 'is-invalid': errors.address }"
                    rows="2"
                    placeholder="123 Avenue de la République, Abidjan, Côte d'Ivoire"
                  ></textarea>
                  <div v-if="errors.address" class="invalid-feedback">{{ errors.address }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Coordonnées -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Coordonnées</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Téléphone 1</label>
                  <input
                    v-model="form.phone1"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.phone1 }"
                    placeholder="+225 27 22 44 55 66"
                  />
                  <div v-if="errors.phone1" class="invalid-feedback">{{ errors.phone1 }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Téléphone 2</label>
                  <input
                    v-model="form.phone2"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.phone2 }"
                    placeholder="+225 07 12 34 56 78"
                  />
                  <div v-if="errors.phone2" class="invalid-feedback">{{ errors.phone2 }}</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Téléphone 3</label>
                  <input
                    v-model="form.phone3"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.phone3 }"
                    placeholder="+225 05 98 76 54 32"
                  />
                  <div v-if="errors.phone3" class="invalid-feedback">{{ errors.phone3 }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': errors.email }"
                    placeholder="contact@entreprise.ci"
                  />
                  <div v-if="errors.email" class="invalid-feedback">{{ errors.email }}</div>
                </div>

                <div class="col-md-12">
                  <label class="form-label">Site web</label>
                  <input
                    v-model="form.website"
                    type="url"
                    class="form-control"
                    :class="{ 'is-invalid': errors.website }"
                    placeholder="https://www.entreprise.ci"
                  />
                  <div v-if="errors.website" class="invalid-feedback">{{ errors.website }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Informations légales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations légales</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Numéro RC (Registre du Commerce)</label>
                  <input
                    v-model="form.rc_number"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.rc_number }"
                    placeholder="CI-ABJ-2024-A-12345"
                  />
                  <div v-if="errors.rc_number" class="invalid-feedback">{{ errors.rc_number }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Numéro NCC (Numéro de Contribuable)</label>
                  <input
                    v-model="form.ncc_number"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': errors.ncc_number }"
                    placeholder="12345678X"
                  />
                  <div v-if="errors.ncc_number" class="invalid-feedback">{{ errors.ncc_number }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="card">
            <div class="card-body">
              <div class="d-flex justify-content-end gap-2">
                <Link
                  :href="route('dashboard')"
                  class="btn btn-outline-secondary"
                >
                  Annuler
                </Link>
                <button
                  type="submit"
                  class="btn btn-primary"
                  :disabled="form.processing"
                >
                  <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="bi bi-check-circle me-1"></i>
                  {{ form.processing ? 'Enregistrement...' : 'Enregistrer les modifications' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Company {
  id: number
  name: string
  tagline?: string
  address?: string
  phone1?: string
  phone2?: string
  phone3?: string
  email?: string
  website?: string
  rc_number?: string
  ncc_number?: string
}

interface Props {
  company: Company
}

const props = defineProps<Props>()

const { success, error } = useSweetAlert()

const form = useForm({
  name: props.company.name || '',
  tagline: props.company.tagline || '',
  address: props.company.address || '',
  phone1: props.company.phone1 || '',
  phone2: props.company.phone2 || '',
  phone3: props.company.phone3 || '',
  email: props.company.email || '',
  website: props.company.website || '',
  rc_number: props.company.rc_number || '',
  ncc_number: props.company.ncc_number || '',
})

const errors = computed(() => form.errors)

const submit = () => {
  form.put(route('company.update'), {
    preserveScroll: true,
    onSuccess: () => {
      success('Informations de l\'entreprise mises à jour avec succès !')
    },
    onError: () => {
      error('Erreur lors de la mise à jour des informations.')
    }
  })
}
</script>

