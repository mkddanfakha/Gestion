<template>
  <AppLayout>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
      <div>
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item text-muted">Paramètres</li>
            <li class="breadcrumb-item active" aria-current="page">Notifications</li>
          </ol>
        </nav>
        <h1 class="h2 mb-1">
          <i class="bi bi-bell me-2"></i>
          Configuration des notifications
        </h1>
        <p class="text-muted mb-0">
          Définissez qui reçoit quoi, les canaux, priorités et maintenance — sans modifier le code.
        </p>
      </div>
      <button class="btn btn-primary" :disabled="saving" @click="saveSettings">
        <span v-if="saving" class="spinner-border spinner-border-sm me-1" />
        <i v-else class="bi bi-check-lg me-1" />
        Enregistrer
      </button>
    </div>

    <div v-if="message" class="alert alert-success alert-dismissible fade show" role="alert">
      {{ message }}
      <button type="button" class="btn-close" @click="message = ''" />
    </div>
    <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show" role="alert">
      {{ errorMessage }}
      <button type="button" class="btn-close" @click="errorMessage = ''" />
    </div>

    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-4" role="tablist">
      <li v-for="tab in tabs" :key="tab.id" class="nav-item" role="presentation">
        <button
          class="nav-link text-nowrap"
          :class="{ active: activeTab === tab.id }"
          type="button"
          role="tab"
          @click="activeTab = tab.id"
        >
          <i :class="`${tab.icon} me-1`" />
          {{ tab.label }}
        </button>
      </li>
    </ul>

    <!-- Général -->
    <div v-show="activeTab === 'general'" class="card shadow-sm">
      <div class="card-header">
        <h2 class="h5 mb-0">Notifications générales</h2>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div v-for="toggle in generalToggles" :key="toggle.key" class="col-md-6">
            <div class="d-flex justify-content-between align-items-start border rounded p-3 h-100">
              <div class="pe-3">
                <div class="fw-semibold">{{ toggle.label }}</div>
                <small class="text-muted">{{ toggle.description }}</small>
              </div>
              <div class="form-check form-switch mb-0">
                <input
                  :id="toggle.key"
                  v-model="form.global[toggle.key]"
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Canaux -->
    <div v-show="activeTab === 'channels'" class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h2 class="h5 mb-0">Canaux de diffusion</h2>
          <small class="text-muted">Seule l'application est active aujourd'hui — les autres canaux sont prêts pour extension.</small>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Type</th>
                <th v-for="channel in channelLabels" :key="channel.key" class="text-center">
                  <span :title="channel.description">{{ channel.label }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="typeRow in form.types" :key="typeRow.type">
                <td>
                  <div class="fw-medium">{{ typeRow.label }}</div>
                  <small class="text-muted">{{ typeRow.type }}</small>
                </td>
                <td v-for="channel in channelLabels" :key="channel.key" class="text-center">
                  <input
                    v-model="typeRow.channels[channel.key]"
                    class="form-check-input"
                    type="checkbox"
                    :disabled="channel.key !== 'app'"
                    :title="channel.key !== 'app' ? 'Canal prêt — non activé pour le moment' : ''"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Destinataires -->
    <div v-show="activeTab === 'recipients'" class="card shadow-sm">
      <div class="card-header">
        <h2 class="h5 mb-0">Destinataires par type</h2>
        <small class="text-muted">Cochez les rôles qui doivent recevoir chaque notification.</small>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Type</th>
                <th class="text-center">Administrateur</th>
                <th class="text-center">Gestionnaire</th>
                <th class="text-center">Vendeur</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="typeRow in form.types" :key="typeRow.type">
                <td>{{ typeRow.label }}</td>
                <td v-for="role in recipientKeys" :key="role" class="text-center">
                  <input v-model="typeRow.recipients[role]" class="form-check-input" type="checkbox" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Priorités -->
    <div v-show="activeTab === 'priorities'" class="card shadow-sm">
      <div class="card-header">
        <h2 class="h5 mb-0">Priorités</h2>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div v-for="typeRow in form.types" :key="typeRow.type" class="col-md-6 col-lg-4">
            <label class="form-label fw-medium">{{ typeRow.label }}</label>
            <select v-model="typeRow.priority" class="form-select">
              <option value="critical">Critique</option>
              <option value="warning">Avertissement</option>
              <option value="info">Information</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Sons -->
    <div v-show="activeTab === 'sounds'" class="card shadow-sm">
      <div class="card-header">
        <h2 class="h5 mb-0">Sons de notification</h2>
        <small class="text-muted">Profils disponibles — extensible sans modification du code métier.</small>
      </div>
      <div class="card-body">
        <div class="row g-4">
          <div class="col-md-6">
            <label class="form-label fw-medium">Son par défaut (global)</label>
            <select v-model="form.global.default_sound" class="form-select">
              <option v-for="sound in soundProfiles" :key="sound.key" :value="sound.key">
                {{ sound.label }}
              </option>
            </select>
          </div>
          <div class="col-12">
            <div class="row g-3">
              <div v-for="sound in soundProfiles" :key="sound.key" class="col-md-6 col-lg-3">
                <div class="border rounded p-3 h-100">
                  <div class="fw-semibold">{{ sound.label }}</div>
                  <small class="text-muted d-block mb-2">{{ sound.description }}</small>
                  <span class="badge text-bg-light">{{ sound.key }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Temps réel -->
    <div v-show="activeTab === 'realtime'" class="row g-4">
      <div class="col-lg-7">
        <div class="card shadow-sm h-100">
          <div class="card-header">
            <h2 class="h5 mb-0">État Pusher / temps réel</h2>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-5">État Pusher</dt>
              <dd class="col-sm-7">
                <span :class="realtime.pusher_configured ? 'badge text-bg-success' : 'badge text-bg-danger'">
                  {{ realtime.pusher_configured ? 'Configuré' : 'Non configuré' }}
                </span>
              </dd>
              <dt class="col-sm-5">Canal utilisé</dt>
              <dd class="col-sm-7"><code>{{ realtime.channel }}</code></dd>
              <dt class="col-sm-5">Événement</dt>
              <dd class="col-sm-7"><code>{{ realtime.event }}</code></dd>
              <dt class="col-sm-5">Dernier événement</dt>
              <dd class="col-sm-7">{{ realtime.meta?.last_event_at || '—' }}</dd>
              <dt class="col-sm-5">Type du dernier événement</dt>
              <dd class="col-sm-7">{{ realtime.meta?.last_event_type || '—' }}</dd>
              <dt class="col-sm-5">Utilisateur connecté</dt>
              <dd class="col-sm-7">
                {{ realtime.connected_user?.name }} ({{ realtime.connected_user?.email }})
              </dd>
            </dl>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card shadow-sm h-100">
          <div class="card-header">
            <h2 class="h5 mb-0">Test de démonstration</h2>
          </div>
          <div class="card-body d-flex flex-column">
            <p class="text-muted">
              Envoie une notification temps réel via Pusher sur votre session actuelle.
            </p>
            <button class="btn btn-outline-primary mt-auto" :disabled="testingRealtime" @click="sendTestNotification">
              <span v-if="testingRealtime" class="spinner-border spinner-border-sm me-1" />
              <i v-else class="bi bi-broadcast me-1" />
              Envoyer une notification test
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Monitoring -->
    <div v-show="activeTab === 'monitoring'" class="row g-4">
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">Tableau de bord opérationnel</h2>
          <button class="btn btn-outline-secondary btn-sm" :disabled="monitoringLoading" @click="loadMonitoring">
            <span v-if="monitoringLoading" class="spinner-border spinner-border-sm me-1" />
            Actualiser
          </button>
        </div>
        <div class="row g-3">
          <div v-for="stat in maintenanceStats" :key="stat.key" class="col-6 col-md-4 col-xl-2">
            <div class="card shadow-sm text-center h-100">
              <div class="card-body">
                <div class="display-6 fw-bold text-primary">{{ monitoring.stats?.[stat.key] ?? form.maintenance[stat.key] ?? 0 }}</div>
                <div class="text-muted small">{{ stat.label }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><h3 class="h6 mb-0">Métriques d'envoi</h3></div>
          <div class="card-body">
            <dl class="row mb-0 small">
              <dt class="col-sm-6">Dernière notification</dt>
              <dd class="col-sm-6">{{ monitoring.metrics?.last_notification_sent_at || '—' }}</dd>
              <dt class="col-sm-6">Temps moyen traitement</dt>
              <dd class="col-sm-6">{{ formatMs(monitoring.metrics?.avg_processing_ms) }}</dd>
              <dt class="col-sm-6">Temps moyen envoi</dt>
              <dd class="col-sm-6">{{ formatMs(monitoring.metrics?.avg_send_duration_ms ?? monitoring.metrics?.last_send_duration_ms) }}</dd>
              <dt class="col-sm-6">Dernière erreur</dt>
              <dd class="col-sm-6 text-danger">{{ monitoring.metrics?.last_error || '—' }}</dd>
              <dt class="col-sm-6">Date dernière erreur</dt>
              <dd class="col-sm-6">{{ monitoring.metrics?.last_error_at || '—' }}</dd>
            </dl>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card shadow-sm h-100">
          <div class="card-header"><h3 class="h6 mb-0">État Pusher</h3></div>
          <div class="card-body">
            <dl class="row mb-0 small">
              <dt class="col-sm-5">Configuré</dt>
              <dd class="col-sm-7">
                <span :class="monitoring.realtime?.pusher_configured ? 'badge text-bg-success' : 'badge text-bg-danger'">
                  {{ monitoring.realtime?.pusher_configured ? 'Oui' : 'Non' }}
                </span>
              </dd>
              <dt class="col-sm-5">Dernier événement</dt>
              <dd class="col-sm-7">{{ monitoring.realtime?.last_event_at || '—' }}</dd>
              <dt class="col-sm-5">Type</dt>
              <dd class="col-sm-7">{{ monitoring.realtime?.last_event_type || '—' }}</dd>
              <dt class="col-sm-5">Taille table (MB)</dt>
              <dd class="col-sm-7">{{ monitoring.performance?.table_size_mb ?? '—' }}</dd>
            </dl>
          </div>
        </div>
      </div>
    </div>

    <!-- Health Check -->
    <div v-show="activeTab === 'health'" class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Health Check</h2>
        <button class="btn btn-outline-secondary btn-sm" :disabled="healthLoading" @click="loadHealth">
          <span v-if="healthLoading" class="spinner-border spinner-border-sm me-1" />
          Vérifier
        </button>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <span class="badge me-2" :class="healthStatusClass">{{ health.status?.toUpperCase() || '—' }}</span>
          <small class="text-muted">Vérifié le {{ health.checked_at || '—' }}</small>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Composant</th>
                <th>Statut</th>
                <th>Message</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(check, key) in health.checks" :key="key">
                <td class="text-capitalize">{{ String(key).replace('_', ' ') }}</td>
                <td><span class="badge" :class="checkBadgeClass(check.status)">{{ check.status }}</span></td>
                <td>{{ check.message }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Maintenance -->
    <div v-show="activeTab === 'maintenance'" class="row g-4">
      <div class="col-12">
        <div class="row g-3">
          <div v-for="stat in maintenanceStats" :key="stat.key" class="col-6 col-md-4 col-xl-2">
            <div class="card shadow-sm text-center h-100">
              <div class="card-body">
                <div class="display-6 fw-bold text-primary">{{ form.maintenance[stat.key] ?? 0 }}</div>
                <div class="text-muted small">{{ stat.label }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-header">
            <h2 class="h5 mb-0">Actions de maintenance</h2>
          </div>
          <div class="card-body d-flex flex-column gap-3">
            <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 border rounded p-3">
              <div class="flex-grow-1">
                <div class="fw-semibold">Archiver les anciennes notifications</div>
                <small class="text-muted">Passe en statut « archivé » les notifications actives de plus de N jours.</small>
              </div>
              <div class="d-flex gap-2 align-items-center">
                <input v-model.number="archiveDays" type="number" min="1" class="form-control" style="width: 5rem" />
                <button class="btn btn-outline-secondary" :disabled="maintenanceLoading" @click="runMaintenance('archive')">
                  Archiver
                </button>
              </div>
            </div>
            <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 border rounded p-3">
              <div class="flex-grow-1">
                <div class="fw-semibold">Supprimer les notifications archivées</div>
                <small class="text-muted">Suppression définitive de toutes les notifications archivées.</small>
              </div>
              <button class="btn btn-outline-danger" :disabled="maintenanceLoading" @click="runMaintenance('delete-archived')">
                Supprimer
              </button>
            </div>
            <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 border rounded p-3">
              <div class="flex-grow-1">
                <div class="fw-semibold">Nettoyage automatique</div>
                <small class="text-muted">
                  Supprime les notifications de plus de {{ form.global.maintenance_cleanup_days }} jours (tâche planifiée quotidienne).
                </small>
              </div>
              <div class="d-flex gap-2 align-items-center">
                <input
                  v-model.number="form.global.maintenance_cleanup_days"
                  type="number"
                  min="1"
                  class="form-control"
                  style="width: 5rem"
                />
                <button class="btn btn-outline-warning" :disabled="maintenanceLoading" @click="runMaintenance('cleanup')">
                  Nettoyer maintenant
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { getCsrfToken } from '@/lib/csrf'
import { route } from '@/lib/routes'

interface TypeSetting {
  type: string
  label: string
  priority: string
  recipients: Record<string, boolean>
  channels: Record<string, boolean>
  enabled: boolean
}

interface SettingsPayload {
  global: Record<string, unknown>
  types: TypeSetting[]
  maintenance: Record<string, number>
  realtime: Record<string, unknown>
  sound_profiles: string[]
  channels: string[]
  recipient_keys: string[]
}

const props = defineProps<{
  settings: SettingsPayload
}>()

const tabs = [
  { id: 'general', label: 'Général', icon: 'bi bi-sliders' },
  { id: 'channels', label: 'Canaux', icon: 'bi bi-broadcast-pin' },
  { id: 'recipients', label: 'Destinataires', icon: 'bi bi-people' },
  { id: 'priorities', label: 'Priorités', icon: 'bi bi-flag' },
  { id: 'sounds', label: 'Sons', icon: 'bi bi-volume-up' },
  { id: 'realtime', label: 'Temps réel', icon: 'bi bi-lightning' },
  { id: 'monitoring', label: 'Monitoring', icon: 'bi bi-graph-up' },
  { id: 'health', label: 'Health Check', icon: 'bi bi-heart-pulse' },
  { id: 'maintenance', label: 'Maintenance', icon: 'bi bi-tools' },
]

const generalToggles = [
  { key: 'enabled', label: 'Activer les notifications', description: 'Interrupteur global du système.' },
  { key: 'realtime_enabled', label: 'Activer les notifications temps réel', description: 'Broadcast Pusher lors des événements.' },
  { key: 'browser_enabled', label: 'Activer les notifications navigateur', description: 'Notifications push du navigateur (si autorisées).' },
  { key: 'sound_enabled', label: 'Activer les notifications sonores', description: 'Sons lors de la réception (selon profil).' },
  { key: 'toasts_enabled', label: 'Afficher les toasts', description: 'Messages éphémères dans l’interface.' },
  { key: 'badge_enabled', label: 'Afficher le badge sur la cloche', description: 'Compteur sur l’icône de notifications.' },
  { key: 'grouping_enabled', label: 'Grouper les notifications similaires', description: 'Regroupe les alertes stock / factures, etc.' },
  { key: 'auto_mark_read_on_open', label: 'Marquer automatiquement comme lues après ouverture', description: 'À l’ouverture du panneau de notifications.' },
]

const channelLabels = [
  { key: 'app', label: 'Application', description: 'Notification in-app (actif)' },
  { key: 'email', label: 'Email', description: 'Canal email (futur)' },
  { key: 'sms', label: 'SMS', description: 'Canal SMS (futur)' },
  { key: 'whatsapp', label: 'WhatsApp', description: 'Canal WhatsApp (futur)' },
  { key: 'push', label: 'Push', description: 'Push mobile (futur)' },
]

const soundProfiles = [
  { key: 'silent', label: 'Silencieux', description: 'Aucun son émis.' },
  { key: 'discrete', label: 'Son discret', description: 'Signal léger et bref.' },
  { key: 'classic', label: 'Son classique', description: 'Son standard de l’application.' },
  { key: 'critical', label: 'Son critique', description: 'Alerte forte pour priorités élevées.' },
]

const maintenanceStats = [
  { key: 'total', label: 'Total' },
  { key: 'active', label: 'Actives' },
  { key: 'archived', label: 'Archivées' },
  { key: 'unread', label: 'Non lues' },
  { key: 'critical', label: 'Critiques' },
]

const activeTab = ref('general')
const saving = ref(false)
const testingRealtime = ref(false)
const maintenanceLoading = ref(false)
const monitoringLoading = ref(false)
const healthLoading = ref(false)
const message = ref('')
const errorMessage = ref('')
const archiveDays = ref(30)

const form = reactive({
  global: { ...props.settings.global },
  types: props.settings.types.map((t) => ({ ...t, recipients: { ...t.recipients }, channels: { ...t.channels } })),
  maintenance: { ...props.settings.maintenance },
})

const realtime = reactive({ ...props.settings.realtime })
const monitoringLoaded = ref(false)
const monitoring = reactive<Record<string, unknown>>({
  stats: props.settings.maintenance,
  metrics: (props.settings as SettingsPayload & { monitoring?: Record<string, unknown> }).monitoring ?? {},
  realtime: {},
  performance: {},
})
const health = reactive<Record<string, unknown>>({ status: 'unknown', checks: {} })
const recipientKeys = props.settings.recipient_keys ?? ['admin', 'manager', 'seller']

const healthStatusClass = computed(() => {
  const status = health.status as string
  if (status === 'ok') return 'text-bg-success'
  if (status === 'warn') return 'text-bg-warning'
  if (status === 'fail') return 'text-bg-danger'
  return 'text-bg-secondary'
})

function checkBadgeClass(status?: string) {
  if (status === 'ok') return 'text-bg-success'
  if (status === 'warn') return 'text-bg-warning'
  if (status === 'fail') return 'text-bg-danger'
  return 'text-bg-secondary'
}

function formatMs(value?: number | null) {
  if (value == null) return '—'
  return `${value} ms`
}

watch(activeTab, (tab) => {
  if (tab === 'monitoring' && !monitoringLoaded.value) loadMonitoring()
  if (tab === 'health' && !health.checked_at) loadHealth()
})

async function apiRequest(url: string, method = 'GET', body?: unknown) {
  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken(),
    },
    body: body ? JSON.stringify(body) : undefined,
  })

  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new Error(data.message || `Erreur ${response.status}`)
  }

  return response.json()
}

async function saveSettings() {
  saving.value = true
  errorMessage.value = ''
  message.value = ''

  try {
    const payload = {
      global: form.global,
      types: form.types.map(({ type, priority, recipients, channels, enabled }) => ({
        type,
        priority,
        recipients,
        channels,
        enabled,
      })),
    }

    const result = await apiRequest(route('notification-center.settings.update'), 'PUT', payload)
    Object.assign(form.maintenance, result.data.maintenance)
    Object.assign(realtime, result.data.realtime)
    message.value = 'Paramètres enregistrés avec succès.'
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Erreur lors de l’enregistrement.'
  } finally {
    saving.value = false
  }
}

async function sendTestNotification() {
  testingRealtime.value = true
  errorMessage.value = ''

  try {
    await apiRequest(route('notification-center.test'), 'POST')
    message.value = 'Notification de test envoyée — vérifiez la cloche ou la console Pusher.'
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Échec de l’envoi test.'
  } finally {
    testingRealtime.value = false
  }
}

async function loadMonitoring() {
  monitoringLoading.value = true
  errorMessage.value = ''

  try {
    const [dashboard, performance] = await Promise.all([
      apiRequest(route('notification-center.settings.monitoring')),
      apiRequest(route('notification-center.settings.performance')),
    ])
    Object.assign(monitoring, dashboard, { performance })
    Object.assign(form.maintenance, dashboard.stats ?? {})
    monitoringLoaded.value = true
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Erreur monitoring.'
  } finally {
    monitoringLoading.value = false
  }
}

async function loadHealth() {
  healthLoading.value = true
  errorMessage.value = ''

  try {
    const result = await apiRequest(route('notification-center.settings.health'))
    Object.assign(health, result)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Erreur health check.'
  } finally {
    healthLoading.value = false
  }
}

async function runMaintenance(action: 'archive' | 'delete-archived' | 'cleanup') {
  maintenanceLoading.value = true
  errorMessage.value = ''

  const routes: Record<string, string> = {
    archive: route('notification-center.settings.maintenance-archive'),
    'delete-archived': route('notification-center.settings.maintenance-delete-archived'),
    cleanup: route('notification-center.settings.maintenance-cleanup'),
  }

  try {
    const body = action === 'archive' ? { days: archiveDays.value } : undefined
    const result = await apiRequest(routes[action], 'POST', body)
    const count = result.archived ?? result.deleted ?? 0
    message.value = `Opération terminée (${count} enregistrement(s) affecté(s)).`
    const refreshed = await apiRequest(route('notification-center.settings.show'))
    Object.assign(form.maintenance, refreshed.maintenance)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Erreur de maintenance.'
  } finally {
    maintenanceLoading.value = false
  }
}
</script>
