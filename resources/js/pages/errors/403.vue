<template>
  <div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
          <div class="text-center mb-4">
            <div class="error-icon mb-4">
              <svg width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="60" cy="60" r="55" fill="#f8d7da" stroke="#dc3545" stroke-width="2"/>
                <path d="M60 30 L60 70 M60 75 L60 85" stroke="#dc3545" stroke-width="6" stroke-linecap="round"/>
                <circle cx="60" cy="50" r="3" fill="#dc3545"/>
              </svg>
            </div>
            <h1 class="display-1 fw-bold text-danger mb-3">403</h1>
            <h2 class="h3 mb-3">Accès refusé</h2>
            <p class="lead text-muted mb-4">
              {{ message || 'Vous n\'avez pas la permission d\'accéder à cette ressource.' }}
            </p>
            <div class="d-flex gap-3 justify-content-center flex-wrap">
              <Link :href="route('dashboard')" class="btn btn-primary">
                <i class="bi bi-house-door me-2"></i>
                Retour au tableau de bord
              </Link>
              <button @click="goBack" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>
                Retour en arrière
              </button>
            </div>
          </div>
          
          <div class="card mt-4">
            <div class="card-body">
              <h5 class="card-title">
                <i class="bi bi-info-circle me-2 text-primary"></i>
                Que faire maintenant ?
              </h5>
              <ul class="list-unstyled mb-0">
                <li class="mb-2">
                  <i class="bi bi-check-circle text-success me-2"></i>
                  Vérifiez que vous avez les permissions nécessaires pour accéder à cette page
                </li>
                <li class="mb-2">
                  <i class="bi bi-check-circle text-success me-2"></i>
                  Contactez un administrateur si vous pensez qu'il s'agit d'une erreur
                </li>
                <li>
                  <i class="bi bi-check-circle text-success me-2"></i>
                  Retournez au tableau de bord pour continuer votre travail
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { computed } from 'vue'

const page = usePage()

const message = computed(() => {
  // Récupérer le message d'erreur depuis les props de la page
  const error = (page.props as any)?.error
  if (error) {
    return error
  }
  
  // Vérifier s'il y a un message dans les flash messages
  const flash = (page.props as any)?.flash
  if (flash?.error) {
    return flash.error
  }
  
  return null
})

const goBack = () => {
  window.history.back()
}
</script>

<style scoped>
.error-icon {
  animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.05);
    opacity: 0.9;
  }
}

.card {
  border: none;
  box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
</style>

