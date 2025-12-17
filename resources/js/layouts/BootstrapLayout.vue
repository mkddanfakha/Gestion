<template>
  <div class="d-flex min-vh-100 bg-light">
    <!-- Sidebar verticale -->
    <!-- IMPORTANT: La sidebar doit TOUJOURS être en mode dark, peu importe le thème de l'application -->
    <!-- Ne jamais ajouter bg-primary à cette div - utiliser uniquement sidebar-dark -->
    <div class="sidebar text-white sidebar-dark">
      <!-- Logo et nom de l'application -->
      <div class="sidebar-header p-3 border-bottom border-white border-opacity-25">
        <Link :href="route('dashboard')" class="d-flex align-items-center text-white text-decoration-none">
          <div class="me-2">
            <img 
              src="/logo.png" 
              alt="Logo" 
              class="logo-img"
              style="width: 120px; height: 120px; object-fit: contain;"
            />
          </div>
          <span class="fw-bold">Gestion</span>
        </Link>
      </div>

      <!-- Menu de navigation -->
      <nav class="sidebar-nav p-3">
        <ul class="nav nav-pills flex-column">
          <li v-if="canView('dashboard')" class="nav-item mb-2">
            <Link :href="route('dashboard')" class="nav-link" :class="{ active: $page.url === '/' }">
              <i class="bi bi-house-door me-2"></i>
              Dashboard
            </Link>
          </li>
          
          <li v-if="canView('products') || canView('categories')" class="nav-divider my-2"></li>
          
          <li v-if="canView('products')" class="nav-item mb-2">
            <Link :href="route('products.index')" class="nav-link" :class="{ active: $page.url.startsWith('/products') }">
              <i class="bi bi-box me-2"></i>
              Produits
            </Link>
          </li>
          <li v-if="canView('categories')" class="nav-item mb-2">
            <Link :href="route('categories.index')" class="nav-link" :class="{ active: $page.url.startsWith('/categories') }">
              <i class="bi bi-tags me-2"></i>
              Catégories
            </Link>
          </li>
          
          <li v-if="canView('customers') || canView('sales') || canView('quotes') || canView('expenses')" class="nav-divider my-2"></li>
          
          <li v-if="canView('customers')" class="nav-item mb-2">
            <Link :href="route('customers.index')" class="nav-link" :class="{ active: $page.url.startsWith('/customers') }">
              <i class="bi bi-people me-2"></i>
              Clients
            </Link>
          </li>
          <li v-if="canView('sales')" class="nav-item mb-2">
            <Link :href="route('sales.index')" class="nav-link" :class="{ active: $page.url.startsWith('/sales') }">
              <i class="bi bi-cart me-2"></i>
              Ventes
            </Link>
          </li>
          <li v-if="canView('quotes')" class="nav-item mb-2">
            <Link :href="route('quotes.index')" class="nav-link" :class="{ active: $page.url.startsWith('/quotes') }">
              <i class="bi bi-file-earmark-check me-2"></i>
              Devis
            </Link>
          </li>
          <li v-if="canView('expenses')" class="nav-item mb-2">
            <Link :href="route('expenses.index')" class="nav-link" :class="{ active: $page.url.startsWith('/expenses') }">
              <i class="bi bi-receipt me-2"></i>
              Dépenses
            </Link>
          </li>
          
          <li v-if="canView('suppliers') || canView('purchase-orders') || canView('delivery-notes')" class="nav-divider my-2"></li>
          
          <li v-if="canView('suppliers')" class="nav-item mb-2">
            <Link :href="route('suppliers.index')" class="nav-link" :class="{ active: $page.url.startsWith('/suppliers') }">
              <i class="bi bi-truck me-2"></i>
              Fournisseurs
            </Link>
          </li>
          <li v-if="canView('purchase-orders')" class="nav-item mb-2">
            <Link :href="route('purchase-orders.index')" class="nav-link" :class="{ active: $page.url.startsWith('/purchase-orders') }">
              <i class="bi bi-file-earmark-text me-2"></i>
              Bons de commande
            </Link>
          </li>
          <li v-if="canView('delivery-notes')" class="nav-item mb-2">
            <Link :href="route('delivery-notes.index')" class="nav-link" :class="{ active: $page.url.startsWith('/delivery-notes') }">
              <i class="bi bi-clipboard-check me-2"></i>
              Bons de livraison
            </Link>
          </li>
          
          <li v-if="canView('company')" class="nav-divider my-2"></li>
          
          <li v-if="canView('company')" class="nav-item mb-2">
            <Link :href="route('company.edit')" class="nav-link" :class="{ active: $page.url.startsWith('/company') }">
              <i class="bi bi-building me-2"></i>
              Entreprise
            </Link>
          </li>
          
          <!-- Menu Administrateur -->
          <li v-if="isAdmin" class="nav-divider my-2"></li>
          <li v-if="isAdmin" class="nav-item mb-2">
            <Link :href="route('admin.users.index')" class="nav-link" :class="{ active: $page.url.startsWith('/admin/users') }">
              <i class="bi bi-shield-lock me-2"></i>
              Utilisateurs
            </Link>
          </li>
          <li v-if="isAdmin && canView('backups')" class="nav-item mb-2">
            <Link :href="route('admin.backups.index')" class="nav-link" :class="{ active: $page.url.startsWith('/admin/backups') }">
              <i class="bi bi-database me-2"></i>
              Sauvegardes
            </Link>
          </li>
        </ul>
      </nav>

    </div>

    <!-- Contenu principal -->
    <div class="main-content flex-grow-1 d-flex flex-column">
      <!-- Header -->
      <header class="bg-white shadow-sm border-bottom">
        <div class="container-fluid px-3 py-2 d-flex justify-content-between align-items-center">
          <!-- Bouton toggle sidebar sur mobile -->
          <button 
            class="btn btn-sm btn-outline-primary d-md-none me-2"
            @click="toggleSidebar"
            type="button"
          >
            <i class="bi bi-list"></i>
          </button>
          
          <!-- Fil d'Ariane -->
          <nav v-if="breadcrumbs && breadcrumbs.length > 0" aria-label="breadcrumb" class="flex-grow-1">
            <ol class="breadcrumb mb-0">
              <li v-if="!page.url.includes('/settings')" class="breadcrumb-item">
                <Link :href="route('dashboard')">
                  <i class="bi bi-house-door me-1"></i>
                  Accueil
                </Link>
              </li>
              <li 
                v-for="(breadcrumb, index) in breadcrumbs" 
                :key="index"
                class="breadcrumb-item"
                :class="{ active: index === breadcrumbs.length - 1 }"
              >
                <Link v-if="breadcrumb.href && index < breadcrumbs.length - 1" :href="breadcrumb.href">
                  {{ breadcrumb.title }}
                </Link>
                <span v-else>{{ breadcrumb.title }}</span>
              </li>
            </ol>
          </nav>
          
          <!-- Notifications, bouton toggle theme et menu utilisateur -->
          <div class="d-flex align-items-center gap-2 ms-auto">
            <NotificationBell :notifications="notifications" />
            <button 
              @click="toggleTheme" 
              class="btn btn-sm btn-outline-primary"
              type="button"
            >
              <i :class="isDark ? 'bi bi-sun' : 'bi bi-moon'"></i>
            </button>
            
            <!-- Menu utilisateur -->
            <div class="dropdown">
              <button 
                class="btn btn-sm btn-outline-primary d-flex align-items-center gap-2" 
                type="button" 
                data-bs-toggle="dropdown"
                aria-expanded="false"
              >
                <i class="bi bi-person-circle"></i>
                <span class="d-none d-md-inline">
                  {{ $page.props.auth.user?.name || 'Utilisateur' }}
                  <span class="badge ms-2" :class="getRoleBadgeClass(($page.props.auth.user as any)?.role)">
                    {{ getRoleLabel(($page.props.auth.user as any)?.role) }}
                  </span>
                </span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow">
                <li class="px-3 py-2">
                  <div class="small text-muted">Rôle</div>
                  <div class="fw-medium">{{ getRoleLabel(($page.props.auth.user as any)?.role) }}</div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <Link :href="route('profile.edit')" class="dropdown-item">
                    <i class="bi bi-person me-2"></i>
                    Profil
                  </Link>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <Link :href="route('logout')" method="post" class="dropdown-item text-danger">
                    <i class="bi bi-box-arrow-right me-2"></i>
                    Déconnexion
                  </Link>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </header>

      <!-- Overlay pour fermer la sidebar sur mobile -->
      <div 
        v-if="sidebarVisible" 
        class="sidebar-overlay d-md-none" 
        @click="toggleSidebar"
      ></div>

      <!-- Contenu de la page -->
      <main class="flex-grow-1 container-fluid py-4">
        <slot />
      </main>
    </div>
    
    <!-- Loading Spinner -->
    <LoadingSpinner />
  </div>
</template>

<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import type { BreadcrumbItemType } from '@/types'
import { ref, onMounted, computed } from 'vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import NotificationBell from '@/components/NotificationBell.vue'
import { usePermissions } from '@/composables/usePermissions'

const { canView, isAdmin: isAdminFromPermissions } = usePermissions()

interface Props {
  breadcrumbs?: BreadcrumbItemType[]
}

withDefaults(defineProps<Props>(), {
  breadcrumbs: () => []
})

const page = usePage()

// Vérifier si l'utilisateur est administrateur (utiliser le composable pour la cohérence)
const isAdmin = computed(() => isAdminFromPermissions.value)

// Flag pour éviter les rechargements multiples
const authReloadAttempted = ref(false)

// Vérifier une seule fois au montage si le rôle est manquant
// Le middleware HandleInertiaRequests devrait toujours fournir un rôle par défaut
onMounted(() => {
  // Attendre un court délai pour laisser le temps aux props de se charger
  setTimeout(() => {
    const user = (page.props.auth as any)?.user
    // Vérifier si l'utilisateur existe mais n'a vraiment pas de rôle
    // (le middleware devrait toujours définir un rôle par défaut 'user')
    if (user && user.id && !authReloadAttempted.value) {
      // Si le rôle est vraiment absent (pas juste une chaîne vide), recharger une seule fois
      if (user.role === undefined || user.role === null) {
        authReloadAttempted.value = true
        router.reload({ only: ['auth'] })
      }
    }
  }, 100)
})

// Notifications depuis les props partagées
const notifications = computed(() => {
  const notifs = (page.props.notifications as any) || {
    salesDueToday: [],
    lowStockProducts: [],
    expiringProducts: []
  }
  
  return notifs
})

// Gestion du thème
const isDark = ref(false)

// Gestion de la sidebar sur mobile
const sidebarVisible = ref(false)

onMounted(() => {
  // Vérifier le thème actuel
  isDark.value = document.documentElement.classList.contains('dark')
  
  // Observer les changements de classe
  const observer = new MutationObserver(() => {
    isDark.value = document.documentElement.classList.contains('dark')
  })
  
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class']
  })
})

const toggleTheme = () => {
  const currentTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light'
  const newTheme = currentTheme === 'dark' ? 'light' : 'dark'
  
  // Mettre à jour la classe
  if (newTheme === 'dark') {
    document.documentElement.classList.add('dark')
  } else {
    document.documentElement.classList.remove('dark')
  }
  
  // Sauvegarder le préférence
  localStorage.setItem('appearance', newTheme)
  isDark.value = newTheme === 'dark'
}

const toggleSidebar = () => {
  const sidebar = document.querySelector('.sidebar') as HTMLElement
  if (sidebar) {
    sidebar.classList.toggle('show')
    sidebarVisible.value = sidebar.classList.contains('show')
  }
}

// Fonction pour obtenir le label du rôle
const getRoleLabel = (role?: string): string => {
  const roleLabels: Record<string, string> = {
    'admin': 'Administrateur',
    'vendeur': 'Vendeur',
    'gestionnaire': 'Gestionnaire',
    'user': 'Utilisateur'
  }
  return roleLabels[role || 'user'] || 'Utilisateur'
}

// Fonction pour obtenir la classe du badge selon le rôle
const getRoleBadgeClass = (role?: string): string => {
  const roleClasses: Record<string, string> = {
    'admin': 'bg-danger',
    'vendeur': 'bg-primary',
    'gestionnaire': 'bg-info',
    'user': 'bg-secondary'
  }
  return roleClasses[role || 'user'] || 'bg-secondary'
}
</script>

<style scoped>
.sidebar {
  width: 250px;
  min-width: 250px;
  display: flex;
  flex-direction: column;
  height: 100vh;
  position: fixed;
  box-shadow: 2px 0 8px rgba(0, 0, 0, 0.1);
  z-index: 1000;
  transition: background-color 0.3s ease;
}

/* Règle globale : la sidebar doit TOUJOURS être dark */
.sidebar {
  background-color: #1e293b !important;
}

:deep(.dark) .sidebar {
  background-color: #1e293b !important;
  box-shadow: 2px 0 8px rgba(0, 0, 0, 0.5);
}

.sidebar-header {
  flex-shrink: 0;
}

.sidebar-header .logo-img {
  width: 120px;
  height: 120px;
  object-fit: contain;
  display: block;
}

.sidebar-nav {
  flex: 1 1 auto;
  overflow-y: auto;
}


.sidebar .nav-link {
  color: rgba(255, 255, 255, 0.8);
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  transition: all 0.2s ease;
}

.sidebar .nav-link:hover {
  color: white;
  background-color: rgba(255, 255, 255, 0.1);
}

.sidebar .nav-link.active {
  background-color: rgba(255, 255, 255, 0.2);
  color: white;
}

.sidebar .nav-link i {
  width: 20px;
  text-align: center;
}

.sidebar .nav-divider {
  height: 1px;
  background-color: rgba(255, 255, 255, 0.2);
  margin: 0.5rem 0;
  list-style: none;
  border: none;
}

.main-content {
  overflow-x: hidden;
  margin-left: 250px;
  width: calc(100% - 250px);
}

/* Pleine largeur lorsqu'affiché dans un iframe (modale) */
/* (classe supprimée) */

/* Responsive: masquer la sidebar sur mobile */
@media (max-width: 768px) {
  .sidebar {
    left: -250px;
    transition: left 0.3s ease;
  }

  .sidebar.show {
    left: 0;
  }

  .main-content {
    margin-left: 0 !important;
    width: 100% !important;
  }
}

/* Scroll personnalisé pour la sidebar */
.sidebar-nav {
  scrollbar-width: thin;
  scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
}

.sidebar-nav::-webkit-scrollbar {
  width: 6px;
}

.sidebar-nav::-webkit-scrollbar-track {
  background: transparent;
}

.sidebar-nav::-webkit-scrollbar-thumb {
  background-color: rgba(255, 255, 255, 0.3);
  border-radius: 3px;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
  background-color: rgba(255, 255, 255, 0.5);
}

/* Dark mode styles */
:deep(.dark) {
  background-color: #0f172a !important;
}

:deep(.dark) .main-content {
  background-color: #0f172a !important;
}

:deep(.dark) .bg-white {
  background-color: #1e293b !important;
  border-color: #334155 !important;
}

:deep(.dark) .bg-light {
  background-color: #0f172a !important;
}

:deep(.dark) .border-bottom {
  border-color: #334155 !important;
}

:deep(.dark) .breadcrumb-item a {
  color: #60a5fa !important;
}

:deep(.dark) .breadcrumb-item.active {
  color: #94a3b8 !important;
}

:deep(.dark) .card {
  background-color: #1e293b !important;
  border-color: #334155 !important;
}

:deep(.dark) .text-dark {
  color: #e2e8f0 !important;
}

:deep(.dark) .text-muted {
  color: #94a3b8 !important;
}

:deep(.dark) .table {
  background-color: #1e293b !important;
  color: #e2e8f0 !important;
}

:deep(.dark) .table td,
:deep(.dark) .table th {
  border-color: #334155 !important;
}

:deep(.dark) .table-hover tbody tr:hover {
  background-color: #334155 !important;
}

:deep(.dark) .btn-outline-primary {
  color: #60a5fa !important;
  border-color: #60a5fa !important;
}

:deep(.dark) .btn-outline-primary:hover {
  background-color: #60a5fa !important;
  border-color: #60a5fa !important;
  color: white !important;
}

:deep(.dark) .badge {
  color: #e2e8f0 !important;
}

/* Sidebar dark mode styles */
:deep(.dark) .sidebar .nav-link {
  color: rgba(255, 255, 255, 0.8) !important;
}

:deep(.dark) .sidebar .nav-link:hover {
  color: white !important;
  background-color: rgba(255, 255, 255, 0.1) !important;
}

:deep(.dark) .sidebar .nav-link.active {
  background-color: rgba(255, 255, 255, 0.2) !important;
  color: white !important;
}

:deep(.dark) .sidebar .nav-divider {
  background-color: rgba(255, 255, 255, 0.15) !important;
}


:deep(.dark) .sidebar .dropdown-menu {
  background-color: #1e293b !important;
  border-color: #334155 !important;
}

:deep(.dark) .sidebar .dropdown-item {
  color: #e2e8f0 !important;
}

:deep(.dark) .sidebar .dropdown-item:hover {
  background-color: #334155 !important;
  color: #e2e8f0 !important;
}

/* Règle robuste : sidebar-dark doit TOUJOURS avoir la priorité */
.sidebar-dark {
  background-color: #1e293b !important;
  border-color: #334155;
}

/* Forcer la sidebar à être dark même si bg-primary est ajouté par erreur */
.sidebar.sidebar-dark,
.sidebar.bg-primary.sidebar-dark {
  background-color: #1e293b !important;
}

/* Empêcher bg-primary d'être appliqué à la sidebar */
.sidebar.bg-primary {
  background-color: #1e293b !important;
}

.sidebar-dark .sidebar-header {
  border-bottom-color: rgba(255, 255, 255, 0.1) !important;
}


/* Overlay pour mobile */
.sidebar-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  z-index: 999;
}

/* Amélioration responsive pour la sidebar */
@media (max-width: 768px) {
  .sidebar {
    z-index: 1000;
    box-shadow: 2px 0 16px rgba(0, 0, 0, 0.3);
  }
}
</style>
