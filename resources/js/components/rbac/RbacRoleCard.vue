<script setup lang="ts">
import { permissionCountLabel, type RbacRole } from '@/utils/rbacUi'

defineProps<{
  role: RbacRole
}>()

defineEmits<{
  view: []
  users: []
}>()
</script>

<template>
  <article class="rbac-role-card card h-100">
    <div class="card-body d-flex flex-column gap-3">
      <div class="d-flex align-items-start justify-content-between gap-2">
        <div class="d-flex align-items-center gap-3">
          <span class="rbac-role-card__icon" aria-hidden="true">
            <i :class="['bi', role.icon]" />
          </span>
          <div>
            <h2 class="h5 mb-1">{{ role.label }}</h2>
            <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
              {{ role.badge }}
            </span>
          </div>
        </div>
      </div>

      <p class="text-muted mb-0 small">{{ role.description }}</p>

      <div v-if="role.isBypass" class="alert alert-info py-2 px-3 mb-0 small">
        <i class="bi bi-shield-lock me-1" aria-hidden="true" />
        Bypass des permissions — accès complet
      </div>

      <div v-else-if="role.isCustom" class="alert alert-secondary py-2 px-3 mb-0 small">
        <i class="bi bi-sliders me-1" aria-hidden="true" />
        Permissions personnalisées par utilisateur
      </div>

      <div class="d-flex flex-wrap gap-3 small">
        <div>
          <div class="text-muted">Permissions</div>
          <strong>{{ permissionCountLabel(role) }}</strong>
        </div>
        <div>
          <div class="text-muted">Utilisateurs</div>
          <strong>{{ role.usersCount }}</strong>
        </div>
      </div>

      <div v-if="role.modules.length" class="rbac-role-card__modules">
        <div class="text-muted small mb-1">Modules</div>
        <div class="d-flex flex-wrap gap-1">
          <span
            v-for="module in role.modules"
            :key="module.key"
            class="badge rounded-pill text-bg-light border"
          >
            {{ module.label }}
          </span>
        </div>
      </div>

      <div class="mt-auto d-flex flex-wrap gap-2">
        <button
          type="button"
          class="btn btn-sm btn-primary"
          @click="$emit('view')"
        >
          <i class="bi bi-eye me-1" aria-hidden="true" />
          Voir les permissions
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :disabled="role.usersCount === 0"
          @click="$emit('users')"
        >
          <i class="bi bi-people me-1" aria-hidden="true" />
          Utilisateurs
        </button>
      </div>
    </div>
  </article>
</template>

<style scoped>
.rbac-role-card {
  border: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
  border-radius: 1rem;
  box-shadow: none;
  transition: border-color 0.15s ease, transform 0.15s ease;
}

.rbac-role-card:hover {
  border-color: color-mix(in srgb, var(--bs-primary) 35%, var(--color-border, #dee2e6));
  transform: translateY(-1px);
}

.rbac-role-card__icon {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.85rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, var(--bs-primary) 12%, transparent);
  color: var(--bs-primary);
  font-size: 1.25rem;
  flex-shrink: 0;
}

.rbac-role-card__modules .badge {
  font-weight: 500;
}
</style>
