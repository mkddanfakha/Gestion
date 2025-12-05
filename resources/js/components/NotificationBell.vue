<template>
  <div class="dropdown">
    <button
      class="btn btn-sm btn-outline-primary position-relative notification-bell-btn"
      type="button"
      :id="dropdownId"
      data-bs-toggle="dropdown"
      data-bs-auto-close="outside"
      aria-expanded="false"
      :class="{ 'has-notifications': notificationCount > 0 }"
    >
      <i class="bi bi-bell fs-5"></i>
      <span
        v-if="notificationCount > 0"
        :key="`badge-${notificationCount}-${badgeKey}`"
        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge"
        :class="{ 'pulse': notificationCount > 0 }"
        :data-count="notificationCount"
      >
        {{ notificationCount > 99 ? '99+' : notificationCount }}
      </span>
    </button>

    <!-- Dropdown des notifications -->
    <ul
      class="dropdown-menu dropdown-menu-end shadow-lg notification-dropdown"
      :aria-labelledby="dropdownId"
      @click.stop
    >
      <li class="notification-header">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-light">
          <h6 class="mb-0 fw-bold d-flex align-items-center">
            <i class="bi bi-bell-fill me-2 text-primary"></i>
            Notifications
            <span v-if="notificationCount > 0" class="badge bg-primary ms-2">
              {{ notificationCount }}
            </span>
          </h6>
          <button
            v-if="notificationCount > 0"
            @click="markAllAsRead"
            class="btn btn-sm btn-link text-decoration-none p-0 text-muted"
            type="button"
            title="Tout marquer comme lu"
            :disabled="isMarkingAsRead"
          >
            <span v-if="isMarkingAsRead" class="spinner-border spinner-border-sm me-1" role="status"></span>
            <i v-else class="bi bi-check-all"></i>
          </button>
        </div>
      </li>

      <li v-if="notificationCount === 0" class="notification-empty">
        <div class="p-5 text-center text-muted">
          <i class="bi bi-bell-slash fs-1 d-block mb-3 text-muted opacity-50"></i>
          <p class="mb-0 fw-medium">Aucune notification</p>
          <p class="small mb-0 mt-2">Vous êtes à jour !</p>
        </div>
      </li>

      <template v-else>
        <!-- Ventes avec échéance aujourd'hui -->
        <template v-if="notifications.salesDueToday && notifications.salesDueToday.length > 0">
          <li class="notification-section-header">
            <div class="p-2 bg-warning bg-opacity-10 border-bottom">
              <div class="d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                  <h6 class="mb-1 small fw-bold d-flex align-items-center">
                    <i class="bi bi-calendar-event-fill me-2 text-warning"></i>
                    Ventes avec échéance aujourd'hui
                    <span class="badge bg-warning text-dark ms-2">
                      {{ notifications.salesDueToday.length }}
                    </span>
                  </h6>
                  <p class="mb-0 small text-muted">
                    {{ notifications.salesDueToday.length }} vente(s) non payée(s)
                  </p>
                </div>
                <button
                  @click.stop="markAllOfTypeAsRead('sale_due_today')"
                  class="btn btn-sm btn-link text-muted p-0 ms-2"
                  type="button"
                  title="Marquer toutes comme lues"
                >
                  <i class="bi bi-check-all"></i>
                </button>
              </div>
            </div>
          </li>
          <li v-for="sale in notifications.salesDueToday.slice(0, 3)" :key="sale.id" class="notification-item">
            <div class="dropdown-item notification-link d-flex align-items-start">
              <Link
                :href="route('sales.show', { id: sale.id })"
                class="flex-grow-1 text-decoration-none"
                @click="markNotificationAsRead('sale_due_today', sale.id, $event)"
              >
                <div class="d-flex justify-content-between align-items-start">
                  <div class="flex-grow-1">
                    <div class="fw-medium small d-flex align-items-center">
                      <i class="bi bi-receipt me-2 text-warning"></i>
                      Vente {{ sale.sale_number }}
                    </div>
                    <div class="text-muted small mt-1">
                      <i class="bi bi-person me-1"></i>
                      {{ sale.customer }}
                    </div>
                  </div>
                  <span class="badge bg-warning text-dark ms-2 flex-shrink-0">
                    {{ formatCurrency(sale.remaining_amount) }}
                  </span>
                </div>
              </Link>
              <button
                @click.stop="markNotificationAsRead('sale_due_today', sale.id)"
                class="btn btn-sm btn-link text-muted p-0 ms-2 flex-shrink-0"
                type="button"
                title="Marquer comme lu"
              >
                <i class="bi bi-check-circle"></i>
              </button>
            </div>
          </li>
          <li v-if="notifications.salesDueToday.length > 3" class="notification-more">
            <Link
              :href="route('sales.index', { due_date: new Date().toISOString().split('T')[0] })"
              class="dropdown-item text-center text-primary small fw-medium"
            >
              <i class="bi bi-arrow-right-circle me-1"></i>
              Voir toutes ({{ notifications.salesDueToday.length }})
            </Link>
          </li>
          <li><hr class="dropdown-divider"></li>
        </template>

        <!-- Produits en stock faible -->
        <template v-if="notifications.lowStockProducts && notifications.lowStockProducts.length > 0">
          <li class="notification-section-header">
            <div class="p-2 bg-info bg-opacity-10 border-bottom">
              <div class="d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                  <h6 class="mb-1 small fw-bold d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2 text-info"></i>
                    Stock faible
                    <span class="badge bg-info text-white ms-2">
                      {{ notifications.lowStockProducts.length }}
                    </span>
                  </h6>
                  <p class="mb-0 small text-muted">
                    {{ notifications.lowStockProducts.length }} produit(s) en rupture ou niveau faible
                  </p>
                </div>
                <button
                  @click.stop="markAllOfTypeAsRead('low_stock')"
                  class="btn btn-sm btn-link text-muted p-0 ms-2"
                  type="button"
                  title="Marquer toutes comme lues"
                >
                  <i class="bi bi-check-all"></i>
                </button>
              </div>
            </div>
          </li>
          <li v-for="product in notifications.lowStockProducts.slice(0, 3)" :key="product.id" class="notification-item">
            <div class="dropdown-item notification-link d-flex align-items-start">
              <Link
                :href="route('products.show', { id: product.id })"
                class="flex-grow-1 text-decoration-none"
                @click="markNotificationAsRead('low_stock', product.id, $event)"
              >
                <div class="d-flex align-items-start">
                  <!-- Image du produit -->
                  <div class="me-2 flex-shrink-0">
                    <img
                      v-if="product.image_url"
                      :src="product.image_url"
                      :alt="product.name"
                      class="rounded"
                      style="width: 40px; height: 40px; object-fit: contain;"
                    />
                    <i v-else class="bi bi-box-seam text-muted" style="font-size: 40px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;"></i>
                  </div>
                  
                  <div class="flex-grow-1 d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                      <div class="fw-medium small d-flex align-items-center">
                        {{ product.name }}
                      </div>
                      <div class="text-muted small mt-1">
                        <i class="bi bi-tag me-1"></i>
                        {{ product.category?.name || 'Sans catégorie' }}
                      </div>
                    </div>
                    <span class="badge bg-info text-white ms-2 flex-shrink-0">
                      {{ product.stock_quantity }} {{ product.unit }}
                    </span>
                  </div>
                </div>
              </Link>
              <button
                @click.stop="markNotificationAsRead('low_stock', product.id)"
                class="btn btn-sm btn-link text-muted p-0 ms-2 flex-shrink-0"
                type="button"
                title="Marquer comme lu"
              >
                <i class="bi bi-check-circle"></i>
              </button>
            </div>
          </li>
          <li v-if="notifications.lowStockProducts.length > 3" class="notification-more">
            <Link
              :href="route('products.index')"
              class="dropdown-item text-center text-primary small fw-medium"
            >
              <i class="bi bi-arrow-right-circle me-1"></i>
              Voir tous ({{ notifications.lowStockProducts.length }})
            </Link>
          </li>
          <li><hr class="dropdown-divider"></li>
        </template>

        <!-- Produits expirés ou proches de l'expiration -->
        <template v-if="notifications.expiringProducts && notifications.expiringProducts.length > 0">
          <li class="notification-section-header">
            <div class="p-2 bg-danger bg-opacity-10 border-bottom">
              <div class="d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                  <h6 class="mb-1 small fw-bold d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
                    Produits expirés ou proches de l'expiration
                    <span class="badge bg-danger ms-2">
                      {{ notifications.expiringProductsTotal || notifications.expiringProducts.length }}
                    </span>
                  </h6>
                  <p class="mb-0 small text-muted">
                    {{ notifications.expiringProductsTotal || notifications.expiringProducts.length }} produit(s) concerné(s)
                  </p>
                </div>
                <button
                  @click.stop="markAllOfTypeAsRead('expiring_product')"
                  class="btn btn-sm btn-link text-muted p-0 ms-2"
                  type="button"
                  title="Marquer toutes comme lues"
                >
                  <i class="bi bi-check-all"></i>
                </button>
              </div>
            </div>
          </li>
          <li v-for="product in (notifications.expiringProducts || []).slice(0, 3)" :key="product.id" class="notification-item">
            <div class="dropdown-item notification-link d-flex align-items-start">
              <Link
                :href="route('products.show', { id: product.id })"
                class="flex-grow-1 text-decoration-none"
                @click="markNotificationAsRead('expiring_product', product.id, $event)"
              >
                <div class="d-flex align-items-start">
                  <!-- Image du produit -->
                  <div class="me-2 flex-shrink-0">
                    <img
                      v-if="product.image_url"
                      :src="product.image_url"
                      :alt="product.name"
                      class="rounded"
                      style="width: 40px; height: 40px; object-fit: contain;"
                    />
                    <i v-else class="bi bi-box-seam text-muted" style="font-size: 40px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;"></i>
                  </div>
                  
                  <div class="flex-grow-1 d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                      <div class="fw-medium small d-flex align-items-center">
                        {{ product.name }}
                      </div>
                      <div class="text-muted small mt-1">
                        <span v-if="product.days_until_expiration !== null">
                          <i class="bi bi-calendar3 me-1"></i>
                          <span v-if="product.days_until_expiration < 0" class="text-danger fw-medium">
                            Expiré depuis {{ Math.abs(product.days_until_expiration) }} jour(s)
                          </span>
                          <span v-else-if="product.days_until_expiration === 0" class="text-danger fw-medium">
                            Expire aujourd'hui
                          </span>
                          <span v-else class="text-warning fw-medium">
                            Expire dans {{ product.days_until_expiration }} jour(s)
                          </span>
                        </span>
                      </div>
                    </div>
                    <span
                      v-if="product.days_until_expiration !== null"
                      :class="[
                        'badge ms-2 flex-shrink-0',
                        product.days_until_expiration < 0 ? 'bg-danger' :
                        product.days_until_expiration === 0 ? 'bg-danger' : 'bg-warning text-dark'
                      ]"
                    >
                      {{ formatDate(product.expiration_date) }}
                    </span>
                  </div>
                </div>
              </Link>
              <button
                @click.stop="markNotificationAsRead('expiring_product', product.id)"
                class="btn btn-sm btn-link text-muted p-0 ms-2 flex-shrink-0"
                type="button"
                title="Marquer comme lu"
              >
                <i class="bi bi-check-circle"></i>
              </button>
            </div>
          </li>
          <li v-if="(notifications.expiringProductsTotal || notifications.expiringProducts.length) > 3" class="notification-more">
            <Link
              :href="route('products.index', { expiration_alert: true })"
              class="dropdown-item text-center text-primary small fw-medium"
            >
              <i class="bi bi-arrow-right-circle me-1"></i>
              Voir tous ({{ notifications.expiringProductsTotal || notifications.expiringProducts.length }})
            </Link>
          </li>
        </template>
      </template>

      <li v-if="notificationCount > 0" class="notification-footer">
        <div class="p-2 border-top bg-light text-center">
          <Link :href="route('dashboard')" class="btn btn-sm btn-primary w-100">
            <i class="bi bi-speedometer2 me-1"></i>
            Voir le tableau de bord
          </Link>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch, watchEffect, nextTick } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useRealtimeNotifications } from '@/composables/useRealtimeNotifications'

interface NotificationData {
  salesDueToday?: Array<{
    id: number
    sale_number: string
    customer: string
    remaining_amount: number
  }>
  lowStockProducts?: Array<{
    id: number
    name: string
    stock_quantity: number
    unit: string
    image_url?: string | null
    category?: {
      name: string
    }
  }>
  lowStockProductsTotal?: number // Total réel de tous les produits en stock faible
  expiringProducts?: Array<{
    id: number
    name: string
    expiration_date: string
    days_until_expiration: number | null
    image_url?: string | null
  }>
  expiringProductsTotal?: number // Total réel de tous les produits expirés
}

interface Props {
  notifications: NotificationData
}

const props = defineProps<Props>()

// Générer un ID unique et stable pour le dropdown
const dropdownId = ref(`notification-dropdown-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`)

// Clé réactive pour forcer le re-render du badge
const badgeKey = ref(0)

const page = usePage()

// Récupérer le token CSRF depuis les props Inertia
const getCsrfToken = (): string => {
  return (page.props as any).csrf_token || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

// Utiliser une ref réactive pour les notifications pour forcer la mise à jour
const notificationsRef = ref<NotificationData>({
  salesDueToday: [],
  lowStockProducts: [],
  lowStockProductsTotal: undefined,
  expiringProducts: [],
  expiringProductsTotal: undefined
})

// Initialiser avec les props actuelles
const initNotifications = () => {
  const notifs = (page.props.notifications as any) || props.notifications || {
    salesDueToday: [],
    lowStockProducts: [],
    expiringProducts: []
  }
  notificationsRef.value = {
    salesDueToday: notifs.salesDueToday || [],
    lowStockProducts: notifs.lowStockProducts || [],
    lowStockProductsTotal: notifs.lowStockProductsTotal,
    expiringProducts: notifs.expiringProducts || [],
    expiringProductsTotal: notifs.expiringProductsTotal
  }
}

// Initialiser au montage
initNotifications()

// Computed qui utilise la ref réactive
const notifications = computed(() => notificationsRef.value)

// Utiliser un ref pour le compteur pour forcer la réactivité
const notificationCount = ref(0)

// Fonction pour calculer et mettre à jour le compteur
const updateNotificationCount = () => {
  const salesCount = notificationsRef.value.salesDueToday?.length || 0
  // Utiliser le total réel si disponible, sinon utiliser la longueur du tableau
  const stockCount = notificationsRef.value.lowStockProductsTotal ?? (notificationsRef.value.lowStockProducts?.length || 0)
  // Utiliser le total réel si disponible, sinon utiliser la longueur du tableau
  const expiringCount = notificationsRef.value.expiringProductsTotal ?? (notificationsRef.value.expiringProducts?.length || 0)
  
  const newCount = salesCount + stockCount + expiringCount
  const oldCount = notificationCount.value
  
  if (newCount !== oldCount) {
    notificationCount.value = newCount
    
    // Forcer la mise à jour du badge
    nextTick(() => {
      badgeKey.value++
    })
  }
}

// Watch sur notificationsRef pour mettre à jour le compteur
watch(notificationsRef, () => {
  updateNotificationCount()
}, { deep: true, immediate: true })

// Variable pour stocker le handler d'événement
let notificationHandler: ((event: Event) => void) | null = null

// Watch page.props.notifications directement pour mettre à jour la ref
watch(() => page.props.notifications, async (newNotifications) => {
  const newNotifs = newNotifications as any
  if (newNotifs) {
    const oldNotifs = { ...notificationsRef.value }
    
    // Mettre à jour la ref réactive avec de nouveaux tableaux pour forcer la réactivité
    notificationsRef.value = {
      salesDueToday: Array.isArray(newNotifs.salesDueToday) ? [...newNotifs.salesDueToday] : [],
      lowStockProducts: Array.isArray(newNotifs.lowStockProducts) ? [...newNotifs.lowStockProducts] : [],
      lowStockProductsTotal: newNotifs.lowStockProductsTotal,
      expiringProducts: Array.isArray(newNotifs.expiringProducts) ? [...newNotifs.expiringProducts] : [],
      expiringProductsTotal: newNotifs.expiringProductsTotal
    }
    
    // Mettre à jour le compteur
    updateNotificationCount()
  }
}, { deep: true, immediate: true })

const isMarkingAsRead = ref(false)

const markNotificationAsRead = async (type: string, id: number, event?: Event) => {
  if (event) {
    // Si c'est un clic sur le lien, on laisse le comportement par défaut (navigation)
    // On marque juste comme lu en arrière-plan
    const link = event.target as HTMLElement
    if (link.closest('a')) {
      // Ne pas empêcher la navigation, mais marquer comme lu
      fetch(route('notifications.mark-as-read'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': getCsrfToken(),
          'Accept': 'application/json',
        },
        body: JSON.stringify({ type, id }),
      }).catch(error => {
      })
      return
    }
    event.preventDefault()
    event.stopPropagation()
  }

  try {
    const response = await fetch(route('notifications.mark-as-read'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
        'Accept': 'application/json',
      },
      body: JSON.stringify({ type, id }),
    })

    if (response.ok) {
      // Recharger la page pour mettre à jour les notifications
      router.reload({ only: ['notifications'] })
    }
  } catch (error) {
    console.error('Erreur lors du marquage comme lu:', error)
  }
}

const markAllOfTypeAsRead = async (type: string) => {
  if (isMarkingAsRead.value) return
  
  isMarkingAsRead.value = true

  try {
    const response = await fetch(route('notifications.mark-all-as-read'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
        'Accept': 'application/json',
      },
      body: JSON.stringify({ type }),
    })

    if (response.ok) {
      // Recharger la page pour mettre à jour les notifications
      router.reload({ only: ['notifications'] })
    }
  } catch (error) {
    console.error('Erreur lors du marquage de toutes les notifications comme lues:', error)
  } finally {
    isMarkingAsRead.value = false
  }
}

const markAllAsRead = async (event: Event) => {
  event.preventDefault()
  event.stopPropagation()
  
  if (isMarkingAsRead.value) return
  
  isMarkingAsRead.value = true

  try {
    const response = await fetch(route('notifications.mark-all-as-read'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
        'Accept': 'application/json',
      },
      body: JSON.stringify({ type: 'all' }),
    })

    if (response.ok) {
      // Fermer le dropdown
      const elementId = dropdownId.value
      const element = document.getElementById(elementId)
      if (element) {
        const bootstrap = (window as any).bootstrap
        if (bootstrap && bootstrap.Dropdown) {
          const dropdown = bootstrap.Dropdown.getInstance(element)
          if (dropdown) {
            dropdown.hide()
          }
        }
      }
      
      // Recharger la page pour mettre à jour les notifications
      router.reload({ only: ['notifications'] })
    }
  } catch (error) {
    console.error('Erreur lors du marquage de toutes les notifications comme lues:', error)
  } finally {
    isMarkingAsRead.value = false
  }
}

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('fr-FR').format(amount) + ' Fcfa'
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  })
}

const getExpirationIconClass = (daysUntilExpiration: number | null) => {
  if (daysUntilExpiration === null) return 'text-muted'
  if (daysUntilExpiration < 0) return 'text-danger'
  if (daysUntilExpiration === 0) return 'text-danger'
  return 'text-warning'
}

// Initialiser les notifications en temps réel
const { startListening, stopListening, testSound } = useRealtimeNotifications()

// Fonction pour tester les notifications (exposée globalement pour debug)
const testNotification = async () => {
  try {
    const response = await fetch(route('notifications.test'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        'Accept': 'application/json',
      },
    })
    
    if (response.ok) {
      // Jouer le son immédiatement pour tester
      testSound()
    }
  } catch (error) {
    // Erreur silencieuse
  }
}

// Exposer la fonction globalement pour test
if (typeof window !== 'undefined') {
  (window as any).testRealtimeNotification = testNotification
}

onMounted(() => {
  // Bootstrap gère automatiquement le dropdown via data-bs-toggle
  // Démarrer l'écoute des notifications en temps réel
  startListening()
  
  // Écouter les notifications en temps réel pour mettre à jour le compteur
  notificationHandler = () => {
    // Le rechargement est déjà géré dans useRealtimeNotifications
  }
  
  // Écouter l'événement de mise à jour des notifications
  const updateHandler = () => {
    // Attendre que le watch mette à jour notificationsRef, puis mettre à jour le compteur
    nextTick(() => {
      updateNotificationCount()
    })
  }
  
  window.addEventListener('notification-received', notificationHandler)
  window.addEventListener('notifications-updated', updateHandler)
  
  onUnmounted(() => {
    window.removeEventListener('notification-received', notificationHandler!)
    window.removeEventListener('notifications-updated', updateHandler)
  })
})

onUnmounted(() => {
  // Arrêter l'écoute des notifications
  stopListening()
  
  // Nettoyer l'écouteur d'événement
  if (notificationHandler) {
    window.removeEventListener('notification-received', notificationHandler)
  }
})
</script>

<style scoped>
/* Bouton de notification */
.notification-bell-btn {
  transition: all 0.3s ease;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.notification-bell-btn:hover {
  transform: scale(1.05);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.notification-bell-btn.has-notifications {
  animation: shake 0.5s ease-in-out;
}

.notification-badge {
  font-size: 0.65rem;
  padding: 0.25em 0.5em;
  font-weight: 600;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% {
    transform: translate(-50%, -50%) scale(1);
    opacity: 1;
  }
  50% {
    transform: translate(-50%, -50%) scale(1.1);
    opacity: 0.8;
  }
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-3px) rotate(-5deg); }
  75% { transform: translateX(3px) rotate(5deg); }
}

/* Dropdown */
.notification-dropdown {
  z-index: 1050;
  min-width: 350px;
  max-width: 400px;
  max-height: 500px;
  overflow-y: auto;
  border-radius: 0.5rem;
  border: 1px solid rgba(0, 0, 0, 0.1);
  margin-top: 0.5rem;
}

.notification-header {
  position: sticky;
  top: 0;
  z-index: 10;
  background-color: white;
}

.notification-empty {
  min-height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.notification-section-header {
  background-color: rgba(0, 0, 0, 0.02);
}

.notification-item {
  transition: background-color 0.2s ease;
}

.notification-link {
  padding: 0.75rem 1rem;
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
}

.notification-link:hover {
  background-color: rgba(0, 0, 0, 0.05);
  border-left-color: var(--bs-primary);
  padding-left: calc(1rem - 3px);
}

.notification-link .btn-link {
  opacity: 0;
  transition: opacity 0.2s ease;
}

.notification-link:hover .btn-link {
  opacity: 1;
}

.notification-link .btn-link:hover {
  color: var(--bs-primary) !important;
}

.notification-more {
  background-color: rgba(0, 0, 0, 0.02);
}

.notification-footer {
  position: sticky;
  bottom: 0;
  background-color: white;
}

/* Scrollbar personnalisée */
.notification-dropdown::-webkit-scrollbar {
  width: 6px;
}

.notification-dropdown::-webkit-scrollbar-track {
  background: rgba(0, 0, 0, 0.05);
  border-radius: 3px;
}

.notification-dropdown::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.2);
  border-radius: 3px;
}

.notification-dropdown::-webkit-scrollbar-thumb:hover {
  background: rgba(0, 0, 0, 0.3);
}

/* Dark mode */
:deep(.dark) .notification-dropdown {
  background-color: #1e293b;
  border-color: #334155;
}

:deep(.dark) .notification-header,
:deep(.dark) .notification-footer {
  background-color: #1e293b !important;
}

:deep(.dark) .notification-link:hover {
  background-color: rgba(255, 255, 255, 0.05);
  border-left-color: #60a5fa;
}

:deep(.dark) .notification-section-header {
  background-color: rgba(255, 255, 255, 0.02);
}

:deep(.dark) .notification-more {
  background-color: rgba(255, 255, 255, 0.02);
}

:deep(.dark) .text-muted {
  color: #94a3b8 !important;
}

:deep(.dark) .bg-light {
  background-color: #1e293b !important;
}

/* Responsive */
@media (max-width: 576px) {
  .notification-dropdown {
    min-width: 300px;
    max-width: calc(100vw - 2rem);
  }
}

/* Animation d'entrée */
@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.notification-item {
  animation: slideIn 0.3s ease-out;
}

.notification-item:nth-child(1) { animation-delay: 0.05s; }
.notification-item:nth-child(2) { animation-delay: 0.1s; }
.notification-item:nth-child(3) { animation-delay: 0.15s; }
</style>

