<template>
  <div class="loading-overlay" v-if="isLoading">
    <div class="loading-content">
      <div class="spinner-container">
        <div class="spinner-grow text-primary" role="status" style="animation-delay: 0s;">
          <span class="visually-hidden">Loading...</span>
        </div>
        <div class="spinner-grow text-success" role="status" style="animation-delay: 0.2s;">
          <span class="visually-hidden">Loading...</span>
        </div>
        <div class="spinner-grow text-warning" role="status" style="animation-delay: 0.4s;">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

const isLoading = ref(false)

const handleLoadingStateChange = (event: Event) => {
  isLoading.value = (event as CustomEvent).detail
}

onMounted(() => {
  // Écouter les événements personnalisés
  window.addEventListener('loading-state-changed', handleLoadingStateChange)
  
  // Écouter aussi les événements Inertia natifs
  window.addEventListener('inertia:start', () => {
    isLoading.value = true
  })
  
  window.addEventListener('inertia:finish', () => {
    setTimeout(() => {
      isLoading.value = false
    }, 300)
  })
})

onUnmounted(() => {
  window.removeEventListener('loading-state-changed', handleLoadingStateChange)
  window.removeEventListener('inertia:start', () => {})
  window.removeEventListener('inertia:finish', () => {})
})
</script>

<style scoped>
.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  animation: fadeIn 0.3s ease-in-out;
}

.loading-content {
  background-color: transparent;
  padding: 0;
}

.spinner-container {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  justify-content: center;
}

.spinner-container .spinner-grow {
  width: 2.5rem;
  height: 2.5rem;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* Dark mode styles */
:deep(.dark) .loading-overlay {
  background-color: transparent;
}

/* Responsive styles */
@media (max-width: 768px) {
  .spinner-container .spinner-grow {
    width: 2rem;
    height: 2rem;
  }
}
</style>
