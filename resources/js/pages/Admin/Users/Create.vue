<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Nouvel utilisateur</h1>
        <p class="text-muted mb-0">Ajoutez un nouvel utilisateur au système</p>
      </div>
      <Link
        :href="route('admin.users.index')"
        class="btn btn-outline-secondary"
      >
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
      </Link>
    </div>

    <form @submit.prevent="submit">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <!-- Informations générales -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Informations de l'utilisateur</h5>
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
                  />
                  <div v-if="errors.name" class="invalid-feedback">{{ errors.name }}</div>
                  <div v-if="clientErrors.name" class="invalid-feedback">{{ clientErrors.name }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Email <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.email"
                    type="email"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.email || clientErrors.email }"
                  />
                  <div v-if="errors.email" class="invalid-feedback">{{ errors.email }}</div>
                  <div v-if="clientErrors.email" class="invalid-feedback">{{ clientErrors.email }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Mot de passe <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.password"
                    type="password"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.password || clientErrors.password }"
                  />
                  <div v-if="errors.password" class="invalid-feedback">{{ errors.password }}</div>
                  <div v-if="clientErrors.password" class="invalid-feedback">{{ clientErrors.password }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Confirmer le mot de passe <span class="text-danger">*</span>
                  </label>
                  <input
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    class="form-control"
                    :class="{ 'is-invalid': errors.password_confirmation || clientErrors.password_confirmation }"
                  />
                  <div v-if="errors.password_confirmation" class="invalid-feedback">{{ errors.password_confirmation }}</div>
                  <div v-if="clientErrors.password_confirmation" class="invalid-feedback">{{ clientErrors.password_confirmation }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Rôle <span class="text-danger">*</span>
                  </label>
                  <select
                    v-model="form.role"
                    required
                    class="form-select"
                    :class="{ 'is-invalid': errors.role || clientErrors.role }"
                    @change="onRoleChange"
                  >
                    <option value="user">Utilisateur</option>
                    <option value="vendeur">Vendeur</option>
                    <option value="gestionnaire">Gestionnaire</option>
                    <option value="admin">Administrateur</option>
                  </select>
                  <div v-if="errors.role" class="invalid-feedback">{{ errors.role }}</div>
                  <div v-if="clientErrors.role" class="invalid-feedback">{{ clientErrors.role }}</div>
                  <small class="form-text text-muted">
                    Les administrateurs ont automatiquement toutes les permissions.
                  </small>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Statut</label>
                  <div class="form-check form-switch">
                    <input
                      v-model="form.is_active"
                      class="form-check-input"
                      type="checkbox"
                      id="is_active"
                      :class="{ 'is-invalid': errors.is_active || clientErrors.is_active }"
                    />
                    <label class="form-check-label" for="is_active">
                      {{ form.is_active ? 'Actif' : 'Inactif' }}
                    </label>
                  </div>
                  <div v-if="errors.is_active" class="invalid-feedback">{{ errors.is_active }}</div>
                  <div v-if="clientErrors.is_active" class="invalid-feedback">{{ clientErrors.is_active }}</div>
                  <small class="form-text text-muted">
                    Les utilisateurs inactifs ne peuvent pas se connecter.
                  </small>
                </div>
              </div>
            </div>
          </div>

          <!-- Permissions (seulement pour les utilisateurs non-admin) -->
          <div v-if="form.role !== 'admin'" class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">
                {{ form.role === 'user' ? 'Permissions personnalisées' : 'Permissions' }}
              </h5>
              <small class="text-muted">
                <template v-if="form.role === 'user'">Sélectionnez les permissions personnalisées</template>
                <template v-else>Aperçu du preset « {{ form.role }} » (appliqué à l’enregistrement côté serveur)</template>
              </small>
            </div>
            <div class="card-body">
              <RbacPermissionPicker
                v-model="form.permissions"
                :permissions="flatCanonicalPermissions"
                :module-labels="moduleLabels"
                :disabled="form.role === 'vendeur' || form.role === 'gestionnaire'"
              />
            </div>
          </div>

          <!-- Actions -->
          <div class="d-flex justify-content-end gap-2">
            <Link
              :href="route('admin.users.index')"
              class="btn btn-outline-secondary"
            >
              Annuler
            </Link>
            <button
              type="submit"
              class="btn btn-primary"
              :disabled="processing"
            >
              <span v-if="processing" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
              <i v-else class="bi bi-check-circle me-1"></i>
              {{ processing ? 'Création...' : 'Créer l\'utilisateur' }}
            </button>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import RbacPermissionPicker from '@/components/rbac/RbacPermissionPicker.vue'
import {
  filterCanonicalPermissionGrid,
  permissionActionLabel,
} from '@/utils/rbacPermissions'
import { DEFAULT_MODULE_LABELS, type RbacPermission } from '@/utils/rbacUi'

const { success, error } = useSweetAlert()

/** Aligné RolePresets PHP (vendeur) — aperçu UI uniquement. */
const VENDEUR_PRESET_NAMES = [
  'dashboard.view',
  'sales.view',
  'sales.create',
  'sales.update',
  'sales.delete',
  'sales.invoice',
  'quotes.view',
  'quotes.create',
  'quotes.update',
  'quotes.delete',
  'quotes.download',
  'quotes.print',
  'products.view',
  'customers.view',
  'customers.create',
  'customers.update',
]

/** Aligné RolePresets PHP (gestionnaire) — pas de sales.*. */
const GESTIONNAIRE_PRESET_NAMES = [
  'dashboard.view',
  'products.view',
  'products.create',
  'products.update',
  'products.delete',
  'categories.view',
  'categories.create',
  'categories.update',
  'categories.delete',
  'quotes.view',
  'quotes.create',
  'quotes.update',
  'quotes.delete',
  'quotes.download',
  'quotes.print',
  'expenses.view',
  'expenses.create',
  'expenses.update',
  'expenses.delete',
  'suppliers.view',
  'suppliers.create',
  'suppliers.update',
  'suppliers.delete',
  'suppliers.export',
  'purchase-orders.view',
  'purchase-orders.create',
  'purchase-orders.update',
  'purchase-orders.delete',
  'purchase-orders.download',
  'purchase-orders.print',
  'delivery-notes.view',
  'delivery-notes.create',
  'delivery-notes.update',
  'delivery-notes.delete',
  'delivery-notes.validate',
  'delivery-notes.download',
  'delivery-notes.print',
  'inventory.view',
  'inventory.create',
  'inventory.count',
  'inventory.submit',
  'inventory.reopen',
  'inventory.validate',
  'inventory.apply',
  'inventory.cancel',
  'inventory.close',
  'inventory.export',
]

interface Permission {
  id: number
  name: string
  action: string
  description: string
}

interface Props {
  permissionsByResource: Record<string, Permission[]>
}

const props = defineProps<Props>()

const canonicalPermissionsByResource = computed(() =>
  filterCanonicalPermissionGrid(props.permissionsByResource),
)

const moduleLabels = DEFAULT_MODULE_LABELS

const flatCanonicalPermissions = computed<RbacPermission[]>(() => {
  const items: RbacPermission[] = []
  for (const [module, permissions] of Object.entries(canonicalPermissionsByResource.value)) {
    for (const permission of permissions) {
      items.push({
        id: permission.id,
        name: permission.name,
        module,
        action: permission.action,
        label: permissionActionLabel(permission.action),
        description: permission.description ?? undefined,
      })
    }
  }
  return items
})

const clientErrors = ref<Record<string, string>>({})

const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.name || form.name.trim().length < 2) {
    errors.name = 'Le nom est requis (minimum 2 caractères)'
  }
  
  if (!form.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Adresse email invalide'
  }
  
  if (!form.password || form.password.length < 8) {
    errors.password = 'Le mot de passe doit contenir au moins 8 caractères'
  }
  
  if (form.password !== form.password_confirmation) {
    errors.password_confirmation = 'Les mots de passe ne correspondent pas'
  }
  
  if (!form.role || !['admin', 'user', 'vendeur', 'gestionnaire'].includes(form.role)) {
    errors.role = 'Rôle invalide'
  }
  
  return Object.keys(errors).length === 0 ? null : errors
}

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'user',
  is_active: true,
  permissions: [] as number[],
})

const idsForNames = (names: string[]): number[] => {
  const ids: number[] = []
  Object.values(props.permissionsByResource).forEach((permissions) => {
    permissions.forEach((permission) => {
      if (names.includes(permission.name)) {
        ids.push(permission.id)
      }
    })
  })
  return ids
}

const onRoleChange = () => {
  if (form.role === 'admin') {
    form.permissions = []
  } else if (form.role === 'vendeur') {
    form.permissions = idsForNames(VENDEUR_PRESET_NAMES)
  } else if (form.role === 'gestionnaire') {
    form.permissions = idsForNames(GESTIONNAIRE_PRESET_NAMES)
  } else {
    form.permissions = []
  }
}

const submit = () => {
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }
  
  form.post(route('admin.users.store'), {
    onSuccess: () => {
      success('Utilisateur créé avec succès !')
    },
    onError: () => {
      error('Erreur lors de la création de l\'utilisateur.')
    }
  })
}

const { errors, processing } = form
</script>


