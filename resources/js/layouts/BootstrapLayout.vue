<template>
  <div class="d-flex app-shell">
    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Logo et nom de l'application -->
      <div class="sidebar-header">
        <Link :href="route('dashboard')" class="sidebar-brand text-decoration-none">
          <div class="sidebar-brand-card">
            <img
              src="/logo.png"
              alt="Logo MKD-Pro"
              class="sidebar-brand-logo"
            />
          </div>
          <span class="sidebar-brand-title">Gestion</span>
          <span class="sidebar-brand-subtitle">MKD-Pro</span>
        </Link>
      </div>

      <!-- Menu de navigation -->
      <nav class="sidebar-nav">
        <ul class="nav nav-pills flex-column">
          <li v-if="canView('dashboard')" class="nav-item">
            <Link :href="route('dashboard')" class="nav-link nav-link-pill" :class="{ active: $page.url === '/' }">
              <span class="nav-icon-wrap nav-icon-wrap--home"><i class="bi bi-house-door"></i></span>
              <span class="nav-label">Dashboard</span>
            </Link>
          </li>
          
          <li v-if="canView('products') || canView('categories')" class="nav-group-spacer" aria-hidden="true"></li>
          
          <li v-if="canView('products')" class="nav-item">
            <Link :href="route('products.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/products') }">
              <span class="nav-icon-wrap nav-icon-wrap--catalog"><i class="bi bi-box"></i></span>
              <span class="nav-label">Produits</span>
            </Link>
          </li>
          <li v-if="canView('categories')" class="nav-item">
            <Link :href="route('categories.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/categories') }">
              <span class="nav-icon-wrap nav-icon-wrap--catalog"><i class="bi bi-tags"></i></span>
              <span class="nav-label">Catégories</span>
            </Link>
          </li>
          
          <li v-if="canView('customers') || canView('sales') || canView('quotes') || canView('expenses')" class="nav-group-spacer" aria-hidden="true"></li>
          
          <li v-if="canView('customers')" class="nav-item">
            <Link :href="route('customers.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/customers') }">
              <span class="nav-icon-wrap nav-icon-wrap--commerce"><i class="bi bi-people"></i></span>
              <span class="nav-label">Clients</span>
            </Link>
          </li>
          <li v-if="canView('sales')" class="nav-item">
            <Link :href="route('sales.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/sales') }">
              <span class="nav-icon-wrap nav-icon-wrap--commerce"><i class="bi bi-cart"></i></span>
              <span class="nav-label">Ventes</span>
            </Link>
          </li>
          <li v-if="canView('quotes')" class="nav-item">
            <Link :href="route('quotes.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/quotes') }">
              <span class="nav-icon-wrap nav-icon-wrap--commerce"><i class="bi bi-file-earmark-check"></i></span>
              <span class="nav-label">Devis</span>
            </Link>
          </li>
          <li v-if="canView('expenses')" class="nav-item">
            <Link :href="route('expenses.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/expenses') }">
              <span class="nav-icon-wrap nav-icon-wrap--commerce"><i class="bi bi-receipt"></i></span>
              <span class="nav-label">Dépenses</span>
            </Link>
          </li>
          
          <li v-if="canView('suppliers') || canView('purchase-orders') || canView('delivery-notes')" class="nav-group-spacer" aria-hidden="true"></li>
          
          <li v-if="canView('suppliers')" class="nav-item">
            <Link :href="route('suppliers.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/suppliers') }">
              <span class="nav-icon-wrap nav-icon-wrap--supply"><i class="bi bi-truck"></i></span>
              <span class="nav-label">Fournisseurs</span>
            </Link>
          </li>
          <li v-if="canView('purchase-orders')" class="nav-item">
            <Link :href="route('purchase-orders.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/purchase-orders') }">
              <span class="nav-icon-wrap nav-icon-wrap--supply"><i class="bi bi-file-earmark-text"></i></span>
              <span class="nav-label">Bons de commande</span>
            </Link>
          </li>
          <li v-if="canView('delivery-notes')" class="nav-item">
            <Link :href="route('delivery-notes.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/delivery-notes') }">
              <span class="nav-icon-wrap nav-icon-wrap--supply"><i class="bi bi-clipboard-check"></i></span>
              <span class="nav-label">Bons de livraison</span>
            </Link>
          </li>
          
          <li v-if="canView('company')" class="nav-group-spacer" aria-hidden="true"></li>
          
          <li v-if="canView('company')" class="nav-item">
            <Link :href="route('company.edit')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/company') }">
              <span class="nav-icon-wrap nav-icon-wrap--company"><i class="bi bi-building"></i></span>
              <span class="nav-label">Entreprise</span>
            </Link>
          </li>
          
          <!-- Menu Administrateur -->
          <li v-if="isAdmin" class="nav-group-spacer" aria-hidden="true"></li>
          <li v-if="isAdmin" class="nav-item">
            <Link :href="route('admin.users.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/admin/users') }">
              <span class="nav-icon-wrap nav-icon-wrap--admin"><i class="bi bi-shield-lock"></i></span>
              <span class="nav-label">Utilisateurs</span>
            </Link>
          </li>
          <li v-if="isAdmin && canView('backups')" class="nav-item">
            <Link :href="route('admin.backups.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/admin/backups') }">
              <span class="nav-icon-wrap nav-icon-wrap--admin"><i class="bi bi-database"></i></span>
              <span class="nav-label">Sauvegardes</span>
            </Link>
          </li>
          <li v-if="isAdmin" class="nav-item">
            <Link :href="route('admin.activity-logs.index')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/admin/activity-logs') }">
              <span class="nav-icon-wrap nav-icon-wrap--admin"><i class="bi bi-journal-text"></i></span>
              <span class="nav-label">Journal d'activité</span>
            </Link>
          </li>
          <li v-if="isAdmin" class="nav-item">
            <Link :href="route('admin.settings.notifications')" class="nav-link nav-link-pill" :class="{ active: $page.url.startsWith('/admin/settings/notifications') }">
              <span class="nav-icon-wrap nav-icon-wrap--admin"><i class="bi bi-bell"></i></span>
              <span class="nav-label">Notifications</span>
            </Link>
          </li>
        </ul>
      </nav>

    </div>

    <!-- Contenu principal -->
    <div class="main-content flex-grow-1 d-flex flex-column">
      <!-- Header -->
      <header class="app-header">
        <div class="app-header-inner container-fluid px-3 py-2 d-flex justify-content-between align-items-center">
          <!-- Bouton toggle sidebar sur mobile -->
          <button 
            class="btn btn-sm sidebar-mobile-toggle d-md-none me-2"
            @click="toggleSidebar"
            type="button"
          >
            <i class="bi bi-list"></i>
          </button>
          
          <!-- Fil d'Ariane ou salutation -->
          <nav v-if="breadcrumbs && breadcrumbs.length > 0" aria-label="breadcrumb" class="flex-grow-1 min-w-0">
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
          <div v-else class="header-greeting flex-grow-1 min-w-0 d-none d-sm-block">
            <div class="header-greeting__text">Bonjour, {{ userFirstName }}</div>
            <div class="header-greeting__role">{{ getRoleLabel(($page.props.auth.user as any)?.role) }}</div>
          </div>
          
          <!-- Notifications, bouton toggle theme et menu utilisateur -->
          <div class="header-toolbar ms-auto">
            <div class="header-toolbar-group">
              <div class="header-toolbar-segment">
                <NotificationBell />
              </div>
              <div class="header-toolbar-segment">
                <button 
                  @click="toggleTheme" 
                  class="header-theme-btn"
                  type="button"
                  :aria-label="isDark ? 'Passer en mode clair' : 'Passer en mode sombre'"
                >
                  <i :class="isDark ? 'bi bi-sun' : 'bi bi-moon'"></i>
                </button>
              </div>
            </div>
            
            <!-- Menu utilisateur -->
            <div class="dropdown header-user">
              <button 
                class="header-user-btn" 
                type="button" 
                data-bs-toggle="dropdown"
                aria-expanded="false"
              >
                <span class="header-user-avatar">{{ userInitials }}</span>
                <span class="header-user-info d-none d-md-flex flex-column align-items-start">
                  <span class="header-user-name">{{ $page.props.auth.user?.name || 'Utilisateur' }}</span>
                  <span class="header-user-role badge" :class="getRoleBadgeClass(($page.props.auth.user as any)?.role)">
                    {{ getRoleLabel(($page.props.auth.user as any)?.role) }}
                  </span>
                </span>
                <i class="bi bi-chevron-down header-user-chevron d-none d-md-inline"></i>
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
      <main class="flex-grow-1 app-main">
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
import { ref, onMounted, computed, watch } from 'vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import NotificationBell from '@/components/NotificationBell.vue'
import { usePermissions } from '@/composables/usePermissions'
import { toggleAppearance } from '@/composables/useAppearance'

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

const userInitials = computed(() => {
  const name = page.props.auth.user?.name?.trim()
  if (!name) return 'U'
  const parts = name.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
})

const userFirstName = computed(() => {
  const name = page.props.auth.user?.name?.trim()
  if (!name) return 'Utilisateur'
  return name.split(/\s+/)[0]
})

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
  toggleAppearance()
}

const closeSidebar = () => {
  const sidebar = document.querySelector('.sidebar') as HTMLElement
  if (sidebar?.classList.contains('show')) {
    sidebar.classList.remove('show')
    sidebarVisible.value = false
  }
}

watch(() => page.url, () => {
  closeSidebar()
})

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
