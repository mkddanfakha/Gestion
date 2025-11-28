<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">Modifier l'utilisateur</h1>
        <p class="text-muted mb-0">Modifiez les informations de l'utilisateur</p>
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
                    Nouveau mot de passe (laisser vide pour ne pas changer)
                  </label>
                  <input
                    v-model="form.password"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': errors.password || clientErrors.password }"
                  />
                  <div v-if="errors.password" class="invalid-feedback">{{ errors.password }}</div>
                  <div v-if="clientErrors.password" class="invalid-feedback">{{ clientErrors.password }}</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">
                    Confirmer le nouveau mot de passe
                  </label>
                  <input
                    v-model="form.password_confirmation"
                    type="password"
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
              <h5 class="card-title mb-0">Permissions</h5>
              <small class="text-muted">Sélectionnez les permissions pour cet utilisateur</small>
            </div>
            <div class="card-body">
              <div v-for="(permissions, resource) in permissionsByResource" :key="resource" class="mb-4">
                <h6 class="mb-3 text-capitalize">{{ getResourceLabel(resource) }}</h6>
                <div class="row g-2">
                  <div v-for="permission in permissions" :key="permission.id" class="col-md-6 col-lg-4">
                    <div class="form-check">
                      <input
                        :id="`permission-${permission.id}`"
                        v-model="form.permissions"
                        type="checkbox"
                        :value="permission.id"
                        class="form-check-input"
                      />
                      <label :for="`permission-${permission.id}`" class="form-check-label">
                        <strong>{{ getActionLabel(permission.action) }}</strong>
                        <br />
                        <small class="text-muted">{{ permission.description }}</small>
                      </label>
                    </div>
                  </div>
                </div>
                <hr v-if="Object.keys(permissionsByResource).indexOf(resource) < Object.keys(permissionsByResource).length - 1" class="my-3" />
              </div>
              
              <div class="mt-3">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary me-2"
                  @click="selectAll"
                >
                  Tout sélectionner
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  @click="deselectAll"
                >
                  Tout désélectionner
                </button>
              </div>
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
              {{ processing ? 'Mise à jour...' : 'Mettre à jour' }}
            </button>
          </div>
        </div>
      </div>
    </form>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

const { success, error } = useSweetAlert()

interface Permission {
  id: number
  name: string
  action: string
  description: string
}

interface Props {
  user: {
    id: number
    name: string
    email: string
    role: string
    is_active: boolean
  }
  permissionsByResource: Record<string, Permission[]>
  userPermissionIds: number[]
}

const props = defineProps<Props>()

const clientErrors = ref<Record<string, string>>({})

const validateForm = () => {
  const errors: Record<string, string> = {}
  
  if (!form.name || form.name.trim().length < 2) {
    errors.name = 'Le nom est requis (minimum 2 caractères)'
  }
  
  if (!form.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Adresse email invalide'
  }
  
  if (form.password && form.password.length < 8) {
    errors.password = 'Le mot de passe doit contenir au moins 8 caractères'
  }
  
  if (form.password && form.password !== form.password_confirmation) {
    errors.password_confirmation = 'Les mots de passe ne correspondent pas'
  }
  
  if (!form.role || !['admin', 'user', 'vendeur', 'gestionnaire'].includes(form.role)) {
    errors.role = 'Rôle invalide'
  }
  
  return Object.keys(errors).length === 0 ? null : errors
}

const form = useForm({
  name: props.user.name,
  email: props.user.email,
  password: '',
  password_confirmation: '',
  role: props.user.role,
  is_active: props.user.is_active ?? true,
  permissions: [...props.userPermissionIds],
})

const getResourceLabel = (resource: string): string => {
  const labels: Record<string, string> = {
    'products': 'Produits',
    'categories': 'Catégories',
    'customers': 'Clients',
    'sales': 'Ventes',
    'quotes': 'Devis',
    'expenses': 'Dépenses',
    'suppliers': 'Fournisseurs',
    'purchase-orders': 'Bons de commande',
    'delivery-notes': 'Bons de livraison',
    'company': 'Entreprise',
    'dashboard': 'Tableau de bord',
  }
  return labels[resource] || resource
}

const getActionLabel = (action: string): string => {
  const labels: Record<string, string> = {
    'view': 'Voir',
    'create': 'Créer',
    'edit': 'Modifier',
    'update': 'Mettre à jour',
    'delete': 'Supprimer',
    'export': 'Exporter',
    'invoice': 'Factures',
    'download': 'Télécharger',
    'print': 'Imprimer',
    'validate': 'Valider',
  }
  return labels[action] || action
}

const onRoleChange = () => {
  if (form.role === 'admin') {
    // Si l'utilisateur devient admin, vider les permissions
    form.permissions = []
  } else if (form.role === 'vendeur') {
    // Assigner automatiquement les permissions du vendeur
    const vendeurPermissionNames = [
      // Permissions pour les ventes
      'sales.create',
      'sales.edit',
      'sales.update',
      'sales.delete',
      'sales.view',
      'sales.invoice', // Télécharger/Imprimer les factures
      // Permissions pour les devis (toutes les permissions)
      'quotes.create',
      'quotes.edit',
      'quotes.update',
      'quotes.delete',
      'quotes.view',
      'quotes.download',
      'quotes.print',
      // Permissions pour les produits (lecture seule)
      'products.view',
      // Permissions pour les clients
      'customers.view',
      'customers.create',
      'customers.edit',
      'customers.update',
    ]
    
    const vendeurPermissionIds: number[] = []
    Object.values(props.permissionsByResource).forEach(permissions => {
      permissions.forEach(permission => {
        if (vendeurPermissionNames.includes(permission.name)) {
          vendeurPermissionIds.push(permission.id)
        }
      })
    })
    form.permissions = vendeurPermissionIds
  } else if (form.role === 'gestionnaire') {
    // Assigner automatiquement les permissions du gestionnaire
    const gestionnairePermissionNames = [
      // Permissions pour le dashboard
      'dashboard.view',
      // Permissions pour les produits (toutes les permissions)
      'products.view',
      'products.create',
      'products.edit',
      'products.update',
      'products.delete',
      // Permissions pour les catégories (toutes les permissions)
      'categories.view',
      'categories.create',
      'categories.edit',
      'categories.update',
      'categories.delete',
      // Permissions pour les devis (toutes les permissions)
      'quotes.view',
      'quotes.create',
      'quotes.edit',
      'quotes.update',
      'quotes.delete',
      'quotes.download',
      'quotes.print',
      // Permissions pour les dépenses (toutes les permissions)
      'expenses.view',
      'expenses.create',
      'expenses.edit',
      'expenses.update',
      'expenses.delete',
      // Permissions pour les fournisseurs (toutes les permissions)
      'suppliers.view',
      'suppliers.create',
      'suppliers.edit',
      'suppliers.update',
      'suppliers.delete',
      'suppliers.export',
      // Permissions pour les bons de commande (toutes les permissions)
      'purchase-orders.view',
      'purchase-orders.create',
      'purchase-orders.edit',
      'purchase-orders.update',
      'purchase-orders.delete',
      'purchase-orders.download',
      'purchase-orders.print',
      // Permissions pour les bons de livraison (toutes les permissions)
      'delivery-notes.view',
      'delivery-notes.create',
      'delivery-notes.edit',
      'delivery-notes.update',
      'delivery-notes.delete',
      'delivery-notes.validate',
      'delivery-notes.download',
      'delivery-notes.print',
      'delivery-notes.invoice',
    ]
    
    const gestionnairePermissionIds: number[] = []
    Object.values(props.permissionsByResource).forEach(permissions => {
      permissions.forEach(permission => {
        if (gestionnairePermissionNames.includes(permission.name)) {
          gestionnairePermissionIds.push(permission.id)
        }
      })
    })
    form.permissions = gestionnairePermissionIds
  }
}

const selectAll = () => {
  const allPermissionIds: number[] = []
  Object.values(props.permissionsByResource).forEach(permissions => {
    permissions.forEach(permission => {
      allPermissionIds.push(permission.id)
    })
  })
  form.permissions = allPermissionIds
}

const deselectAll = () => {
  form.permissions = []
}

const submit = () => {
  clientErrors.value = {}
  
  const validationErrors = validateForm()
  
  if (validationErrors) {
    clientErrors.value = validationErrors
    return
  }
  
  form.put(route('admin.users.update', props.user.id), {
    onSuccess: () => {
      success('Utilisateur mis à jour avec succès !')
    },
    onError: () => {
      error('Erreur lors de la mise à jour de l\'utilisateur.')
    }
  })
}

const { errors, processing } = form
</script>


