<template>
  <AppLayout>
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-journal-text me-2"></i>
          Journal d'activité
        </h1>
        <p class="text-muted mb-0">Historique des actions effectuées dans l'application</p>
      </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Recherche</label>
            <input
              v-model="filters.search"
              type="text"
              class="form-control"
              placeholder="Description, valeur, champ, IP..."
              @input="search"
            />
          </div>
          <div class="col-md-2">
            <label class="form-label">Utilisateur</label>
            <select v-model="filters.user_id" class="form-select" @change="search">
              <option value="">Tous</option>
              <option v-for="user in users" :key="user.id" :value="user.id">
                {{ user.name }}
              </option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Module</label>
            <select v-model="filters.module" class="form-select" @change="search">
              <option value="">Tous</option>
              <option v-for="module in modules" :key="module" :value="module">
                {{ module }}
              </option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Du</label>
            <input v-model="filters.date_from" type="date" class="form-control" @change="search" />
          </div>
          <div class="col-md-2">
            <label class="form-label">Au</label>
            <input v-model="filters.date_to" type="date" class="form-control" @change="search" />
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <button
              type="button"
              class="btn btn-outline-secondary w-100"
              @click="clearFilters"
            >
              <i class="bi bi-x-circle me-1"></i>
              Effacer
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tableau -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Utilisateur</th>
              <th>Module</th>
              <th>Action</th>
              <th>Description</th>
              <th>Adresse IP</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in activityLogs.data" :key="log.id">
              <td>
                <div class="fw-medium">{{ log.created_at }}</div>
              </td>
              <td>
                <span v-if="log.user" class="fw-medium">{{ log.user.name }}</span>
                <span v-else class="text-muted">Système</span>
              </td>
              <td>
                <span class="badge bg-secondary">{{ log.module }}</span>
              </td>
              <td>
                <span class="badge" :class="getActionBadgeClass(log.action)">
                  {{ log.action_label }}
                </span>
              </td>
              <td>
                <span v-if="log.user">{{ log.user.name }}</span>
                <span v-else class="text-muted">Système</span>
                {{ ' ' + log.description }}
              </td>
              <td>
                <code class="small">{{ log.ip_address || '—' }}</code>
              </td>
              <td class="text-end">
                <Link
                  :href="route('admin.activity-logs.show', log.id)"
                  class="btn btn-sm btn-outline-primary"
                  title="Voir les détails"
                >
                  <i class="bi bi-eye"></i>
                </Link>
              </td>
            </tr>
            <tr v-if="activityLogs.data.length === 0">
              <td colspan="7" class="text-center text-muted py-4">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Aucune activité trouvée
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="activityLogs.links && activityLogs.links.length > 3" class="card-footer">
        <div class="d-flex justify-content-center">
          <nav>
            <ul class="pagination mb-0">
              <li
                v-for="(link, index) in activityLogs.links"
                :key="index"
                class="page-item"
                :class="{ active: link.active, disabled: !link.url }"
              >
                <Link
                  v-if="link.url"
                  :href="link.url"
                  class="page-link"
                  v-html="link.label"
                />
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
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { route } from '@/lib/routes'

interface UserOption {
  id: number
  name: string
}

interface ActivityLogItem {
  id: number
  action: string
  action_label: string
  module: string
  description: string
  ip_address: string | null
  created_at: string
  user: { id: number; name: string } | null
}

interface PaginatedLogs {
  data: ActivityLogItem[]
  links: Array<{ url: string | null; label: string; active: boolean }>
}

interface Props {
  activityLogs: PaginatedLogs
  users: UserOption[]
  modules: string[]
  filters: {
    search?: string
    user_id?: string
    module?: string
    date_from?: string
    date_to?: string
  }
}

const props = defineProps<Props>()

const filters = ref({ ...props.filters })

const search = () => {
  router.get(route('admin.activity-logs.index'), filters.value, {
    preserveState: true,
    replace: true,
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const getActionBadgeClass = (action: string): string => {
  const classes: Record<string, string> = {
    create: 'bg-success',
    update: 'bg-primary',
    delete: 'bg-danger',
    validate: 'bg-info text-dark',
    cancel: 'bg-warning text-dark',
    payment: 'bg-success',
    login: 'bg-secondary',
    logout: 'bg-secondary',
  }
  return classes[action] || 'bg-secondary'
}
</script>
