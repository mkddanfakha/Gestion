<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { matchesAuditFilter, type AuditFilter } from '@/utils/rbacUi'

export type RbacAuditEvent = {
  id: number
  action: string
  actionLabel: string
  description: string
  actorName: string | null
  createdAt: string | null
}

const props = defineProps<{
  events: RbacAuditEvent[]
}>()

const filter = ref<AuditFilter>('all')

const filtered = computed(() =>
  props.events.filter((event) => matchesAuditFilter(event.action, filter.value)),
)

function formatDate(value: string | null): string {
  if (!value) {
    return '—'
  }
  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const filters: Array<{ id: AuditFilter; label: string }> = [
  { id: 'all', label: 'Tous' },
  { id: 'roles', label: 'Rôles' },
  { id: 'permissions', label: 'Permissions' },
  { id: 'activation', label: 'Activation' },
  { id: 'admins', label: 'Administrateurs' },
  { id: 'denied', label: 'Refus de sécurité' },
]
</script>

<template>
  <section class="rbac-audit card">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h5 mb-1">Journal RBAC récent</h2>
          <p class="text-muted small mb-0">
            Événements issus du journal d’activité (module RBAC).
          </p>
        </div>
        <Link
          :href="route('admin.activity-logs.index', { module: 'RBAC' })"
          class="btn btn-sm btn-outline-secondary"
        >
          Voir tout
        </Link>
      </div>

      <div class="rbac-audit__filters mb-3" role="group" aria-label="Filtrer le journal RBAC">
        <button
          v-for="item in filters"
          :key="item.id"
          type="button"
          class="btn btn-sm"
          :class="filter === item.id ? 'btn-primary' : 'btn-outline-secondary'"
          @click="filter = item.id"
        >
          {{ item.label }}
        </button>
      </div>

      <div v-if="filtered.length === 0" class="text-muted small py-3">
        Aucun événement pour ce filtre.
      </div>

      <ol v-else class="rbac-audit__list">
        <li v-for="event in filtered" :key="event.id" class="rbac-audit__item">
          <div class="rbac-audit__dot" aria-hidden="true" />
          <div>
            <div class="fw-medium">{{ event.description }}</div>
            <div class="small text-muted">
              <span>{{ event.actionLabel }}</span>
              <span v-if="event.actorName"> · {{ event.actorName }}</span>
              <span> · {{ formatDate(event.createdAt) }}</span>
            </div>
          </div>
        </li>
      </ol>
    </div>
  </section>
</template>

<style scoped>
.rbac-audit {
  border-radius: 1rem;
  border: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
}

.rbac-audit__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.rbac-audit__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.rbac-audit__item {
  display: grid;
  grid-template-columns: 0.75rem 1fr;
  gap: 0.75rem;
  align-items: start;
}

.rbac-audit__dot {
  width: 0.55rem;
  height: 0.55rem;
  margin-top: 0.45rem;
  border-radius: 999px;
  background: var(--bs-primary);
}
</style>
