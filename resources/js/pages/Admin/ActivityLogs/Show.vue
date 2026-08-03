<template>
  <AppLayout>
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h2 mb-1">
          <i class="bi bi-journal-text me-2"></i>
          Détail de l'activité
        </h1>
        <p class="text-muted mb-0">Informations complètes sur cette action</p>
      </div>
      <Link :href="route('admin.activity-logs.index')" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Retour au journal
      </Link>
    </div>

    <div class="row">
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations générales</h5>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-4">Utilisateur</dt>
              <dd class="col-sm-8">
                <span v-if="activityLog.user" class="fw-medium">{{ activityLog.user.name }}</span>
                <span v-else class="text-muted">Système</span>
                <div v-if="activityLog.user" class="text-muted small">{{ activityLog.user.email }}</div>
              </dd>

              <dt class="col-sm-4">Date</dt>
              <dd class="col-sm-8">{{ activityLog.created_at }}</dd>

              <dt class="col-sm-4">Action</dt>
              <dd class="col-sm-8">
                <span class="badge" :class="getActionBadgeClass(activityLog.action)">
                  {{ activityLog.action_label }}
                </span>
              </dd>

              <dt class="col-sm-4">Module</dt>
              <dd class="col-sm-8">
                <span class="badge bg-secondary">{{ activityLog.module }}</span>
              </dd>

              <dt class="col-sm-4">Description</dt>
              <dd class="col-sm-8">
                <span v-if="activityLog.user">{{ activityLog.user.name }}</span>
                <span v-else class="text-muted">Système</span>
                {{ ' ' + activityLog.description }}
              </dd>
            </dl>
          </div>
        </div>

        <div v-if="activityLog.subject" class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Objet concerné</h5>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-4">{{ activityLog.subject.module }}</dt>
              <dd class="col-sm-8 fw-medium">{{ activityLog.subject.label }}</dd>

              <dt class="col-sm-4">ID</dt>
              <dd class="col-sm-8">{{ activityLog.subject.id }}</dd>

              <dt class="col-sm-4">Type</dt>
              <dd class="col-sm-8"><code>{{ activityLog.subject.type }}</code></dd>
            </dl>
          </div>
        </div>

        <div v-if="activityLog.changes && activityLog.changes.length > 0" class="card mb-4">
          <div class="card-header">
            <h5 class="card-title mb-0">Historique des modifications</h5>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-bordered mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Champ</th>
                    <th>Ancienne valeur</th>
                    <th>Nouvelle valeur</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="change in activityLog.changes"
                    :key="change.field_key"
                    class="table-warning bg-opacity-10"
                  >
                    <td class="fw-medium">{{ change.field }}</td>
                    <td>
                      <span class="text-danger fw-medium">{{ change.old }}</span>
                    </td>
                    <td>
                      <span class="text-success fw-medium">{{ change.new }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div
          v-else-if="isModificationAction(activityLog.action) && !activityLog.has_change_data"
          class="alert alert-info mb-4"
        >
          <i class="bi bi-info-circle me-2"></i>
          Aucun détail de modification enregistré pour ce log. Il a probablement été créé
          <strong>avant l'activation du suivi des changements</strong>. Effectuez une
          nouvelle modification pour générer un historique complet.
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0">Informations techniques</h5>
          </div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-sm-5">Adresse IP</dt>
              <dd class="col-sm-7">
                <code>{{ activityLog.ip_address || '—' }}</code>
              </dd>

              <dt class="col-sm-5">Navigateur</dt>
              <dd class="col-sm-7">{{ activityLog.browser || '—' }}</dd>

              <dt class="col-sm-5">User Agent</dt>
              <dd class="col-sm-7">
                <small class="text-muted text-break">{{ activityLog.user_agent || '—' }}</small>
              </dd>
            </dl>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import { route } from '@/lib/routes'

interface ChangeRow {
  field: string
  field_key: string
  old: string
  new: string
}

interface ActivityLogDetail {
  id: number
  action: string
  action_label: string
  module: string
  description: string
  ip_address: string | null
  user_agent: string | null
  browser: string | null
  created_at: string
  user: { id: number; name: string; email: string } | null
  subject: { type: string; module: string; label: string; id: number } | null
  changes: ChangeRow[]
  has_change_data: boolean
}

interface Props {
  activityLog: ActivityLogDetail
}

defineProps<Props>()

const isModificationAction = (action: string): boolean => {
  return ['update', 'validate', 'cancel', 'payment', 'restore'].includes(action)
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
