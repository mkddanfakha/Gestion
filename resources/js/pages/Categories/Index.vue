<template>
  <AppLayout>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-tags me-2"></i>
          Catégories
        </h1>
        <p class="text-muted mb-0">Gérez vos catégories de produits</p>
      </div>
      <Link
        :href="route('categories.create')"
        class="btn btn-primary"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Ajouter une catégorie
      </Link>
    </div>

    <!-- Grille des catégories -->
    <div class="row g-4">
      <div
        v-for="category in categories"
        :key="category.id"
        class="col-md-6 col-lg-4"
      >
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div class="d-flex align-items-center">
                <div
                  class="rounded-circle me-2"
                  style="width: 16px; height: 16px;"
                  :style="{ backgroundColor: category.color }"
                ></div>
                <h5 class="card-title mb-0">{{ category.name }}</h5>
              </div>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu">
                  <li>
                    <Link
                      :href="route('categories.edit', { id: category.id })"
                      class="dropdown-item"
                    >
                      <i class="bi bi-pencil me-2"></i>
                      Modifier
                    </Link>
                  </li>
                  <li>
                    <button
                      @click="deleteCategory(category)"
                      class="dropdown-item text-danger"
                    >
                      <i class="bi bi-trash me-2"></i>
                      Supprimer
                    </button>
                  </li>
                </ul>
              </div>
            </div>
            <p class="text-muted small mb-3">{{ category.description || 'Aucune description' }}</p>
            <div class="d-flex justify-content-between align-items-center">
              <span class="badge bg-light text-dark">{{ category.products_count || 0 }} produit(s)</span>
              <Link
                :href="route('categories.show', { id: category.id })"
                class="btn btn-sm btn-outline-primary"
              >
                <i class="bi bi-eye me-1"></i>
                Voir les produits
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Message si aucune catégorie -->
    <div v-if="categories.length === 0" class="text-center py-5">
      <div class="text-muted mb-3">
        <i class="bi bi-folder fs-1"></i>
      </div>
      <h5 class="text-dark mb-2">Aucune catégorie</h5>
      <p class="text-muted mb-4">Commencez par créer votre première catégorie.</p>
      <Link
        :href="route('categories.create')"
        class="btn btn-primary"
      >
        <i class="bi bi-plus-circle me-1"></i>
        Créer une catégorie
      </Link>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'

interface Category {
  id: number
  name: string
  description?: string
  color: string
  products_count?: number
}

interface Props {
  categories: Category[]
}

const props = defineProps<Props>()
const page = usePage()

const { success, error, confirm } = useSweetAlert()

const deleteCategory = async (category: Category) => {
  const confirmed = await confirm(`Êtes-vous sûr de vouloir supprimer la catégorie "${category.name}" ?`)
  
  if (confirmed) {
    router.delete(route('categories.destroy', { id: category.id }), {
      onSuccess: (page: any) => {
        if (page.props?.flash?.success) {
          success(page.props.flash.success)
        } else {
          // Message de succès par défaut si aucun message flash
          success(`Catégorie "${category.name}" supprimée avec succès !`)
        }
      },
      onError: (errors) => {
        // Capturer les erreurs de validation depuis la session
        if (errors && typeof errors === 'object') {
          const errorMessage = Object.values(errors)[0] as string
          if (errorMessage) {
            error(errorMessage)
          }
        } else {
          error('Erreur lors de la suppression de la catégorie.')
        }
      }
    })
  }
}
</script>
