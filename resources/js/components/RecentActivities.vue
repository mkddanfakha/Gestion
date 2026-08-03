<template>
  <div v-if="isAdmin" class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="card-title mb-0">
        <i class="bi bi-journal-text me-2"></i>
        Activité récente
      </h5>
      <Link :href="route('admin.activity-logs.index')" class="btn btn-sm btn-outline-primary">
        Voir tout
      </Link>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="border rounded p-3 text-center">
            <div class="fs-4 fw-bold text-primary">{{ stats.actions_today }}</div>
            <div class="text-muted small">Actions aujourd'hui</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 text-center">
            <div class="fs-4 fw-bold text-success">{{ stats.logins_today }}</div>
            <div class="text-muted small">Connexions aujourd'hui</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 text-center">
            <div class="fs-4 fw-bold text-danger">{{ stats.deletions_today }}</div>
            <div class="text-muted small">Suppressions aujourd'hui</div>
          </div>
        </div>
      </div>

      <ul v-if="activities.length > 0" class="list-unstyled mb-0">
        <li
          v-for="activity in activities"
          :key="activity.id"
          class="d-flex align-items-start mb-3 pb-3 border-bottom"
        >
          <span class="me-2 fs-5">{{ getActionIcon(activity.action) }}</span>
          <div class="flex-grow-1">
            <div class="small">
              <span class="fw-medium">{{ activity.user_name }}</span>
              {{ ' ' + activity.description }}
            </div>
            <div class="text-muted small">{{ activity.created_at }}</div>
          </div>
        </li>
      </ul>
      <p v-else class="text-muted mb-0 text-center py-3">Aucune activité récente</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { route } from '@/lib/routes'

interface ActivityItem {
  id: number
  action: string
  description: string
  user_name: string
  created_at: string
}

interface ActivityStats {
  actions_today: number
  logins_today: number
  deletions_today: number
}

interface Props {
  activities: ActivityItem[]
  stats: ActivityStats
}

defineProps<Props>()

const page = usePage()
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin')

const getActionIcon = (action: string): string => {
  const icons: Record<string, string> = {
    create: '🟢',
    update: '🔵',
    delete: '🟠',
    validate: '🔵',
    cancel: '🟠',
    payment: '🟢',
    login: '🔵',
    logout: '⚪',
  }
  return icons[action] || '⚪'
}
</script>
