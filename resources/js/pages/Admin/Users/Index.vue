<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-shield-lock me-2"></i>
          Gestion des utilisateurs
        </h1>
        <p class="text-muted mb-0">Gérez les utilisateurs et leurs rôles</p>
      </div>
      <Link
        :href="route('admin.users.create')"
        class="btn btn-primary"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Ajouter un utilisateur
      </Link>
    </div>

    <!-- Tableau des utilisateurs -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Utilisateur</th>
              <th>Email</th>
              <th>Rôle</th>
              <th>Email vérifié</th>
              <th>Statut</th>
              <th>Date de création</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users.data" :key="user.id">
              <td>
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0 me-3">
                    <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                      <i class="bi bi-person text-primary"></i>
                    </div>
                  </div>
                  <div>
                    <div class="fw-medium">{{ user.name }}</div>
                    <div class="text-muted small">ID: {{ user.id }}</div>
                  </div>
                </div>
              </td>
              <td>{{ user.email }}</td>
              <td>
                <span 
                  class="badge"
                  :class="{
                    'bg-danger': user.role === 'admin',
                    'bg-primary': user.role === 'vendeur',
                    'bg-info': user.role === 'gestionnaire',
                    'bg-secondary': user.role === 'user'
                  }"
                >
                  {{ getRoleLabel(user.role) }}
                </span>
              </td>
              <td>
                <span 
                  class="badge"
                  :class="user.email_verified_at ? 'bg-success' : 'bg-warning text-dark'"
                >
                  <i :class="user.email_verified_at ? 'bi bi-check-circle' : 'bi bi-exclamation-triangle'" class="me-1"></i>
                  {{ user.email_verified_at ? 'Vérifié' : 'Non vérifié' }}
                </span>
              </td>
              <td>
                <span 
                  class="badge"
                  :class="user.is_active ? 'bg-success' : 'bg-secondary'"
                >
                  {{ user.is_active ? 'Actif' : 'Inactif' }}
                </span>
              </td>
              <td>{{ formatDate(user.created_at) }}</td>
              <td class="text-end">
                <div class="btn-group" role="group">
                  <Link
                    :href="route('admin.users.edit', user.id)"
                    class="btn btn-sm btn-outline-primary"
                  >
                    <i class="bi bi-pencil"></i>
                  </Link>
                  <button
                    v-if="isAdmin && user.id !== $page.props.auth.user?.id"
                    @click="confirmDelete(user)"
                    class="btn btn-sm btn-outline-danger"
                    title="Seuls les administrateurs peuvent supprimer des utilisateurs"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="users.data.length === 0">
              <td colspan="7" class="text-center text-muted py-4">
                Aucun utilisateur trouvé
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="users.links && users.links.length > 3" class="card-footer">
        <div class="d-flex justify-content-center">
          <nav>
            <ul class="pagination mb-0">
              <li
                v-for="(link, index) in users.links"
                :key="index"
                class="page-item"
                :class="{ active: link.active, disabled: !link.url }"
              >
                <Link
                  v-if="link.url"
                  :href="link.url"
                  class="page-link"
                  v-html="link.label"
                ></Link>
                <span v-else class="page-link" v-html="link.label"></span>
              </li>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { ref } from 'vue'
import Swal from 'sweetalert2'
import { usePermissions } from '@/composables/usePermissions'

interface User {
  id: number
  name: string
  email: string
  role: string
  email_verified_at: string | null
  is_active: boolean
  created_at: string
}

interface Props {
  users: {
    data: User[]
    links: Array<{
      url: string | null
      label: string
      active: boolean
    }>
  }
}

const props = defineProps<Props>()

const { isAdmin } = usePermissions()

const getRoleLabel = (role: string): string => {
  const roleLabels: Record<string, string> = {
    'admin': 'Administrateur',
    'vendeur': 'Vendeur',
    'gestionnaire': 'Gestionnaire',
    'user': 'Utilisateur'
  }
  return roleLabels[role] || 'Utilisateur'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

const confirmDelete = (user: User) => {
  Swal.fire({
    title: 'Êtes-vous sûr ?',
    text: `Voulez-vous vraiment supprimer l'utilisateur "${user.name}" ?`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#3085d6',
    confirmButtonText: 'Oui, supprimer',
    cancelButtonText: 'Annuler'
  }).then((result) => {
    if (result.isConfirmed) {
      router.delete(route('admin.users.destroy', user.id), {
        onSuccess: () => {
          Swal.fire({
            title: 'Supprimé !',
            text: 'L\'utilisateur a été supprimé avec succès.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
          })
        },
        onError: (errors) => {
          const errorMessage = errors?.message || errors?.error || 'Une erreur est survenue lors de la suppression.'
          Swal.fire({
            title: 'Erreur !',
            text: errorMessage,
            icon: 'error'
          })
        }
      })
    }
  })
}
</script>


