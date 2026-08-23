<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'
import RbacPermissionGroup from '@/components/rbac/RbacPermissionGroup.vue'
import {
  groupPermissionsByModule,
  permissionCountLabel,
  roleHasPermission,
  type RbacPermission,
  type RbacRole,
} from '@/utils/rbacUi'

const props = defineProps<{
  open: boolean
  role: RbacRole | null
  permissions: RbacPermission[]
  moduleLabels: Record<string, string>
}>()

const emit = defineEmits<{
  close: []
}>()

const groups = computed(() => {
  if (!props.role) {
    return []
  }

  if (props.role.isBypass) {
    return groupPermissionsByModule(props.permissions, props.moduleLabels)
  }

  if (props.role.isCustom) {
    return []
  }

  const granted = props.permissions.filter((permission) =>
    roleHasPermission(props.role as RbacRole, permission.name),
  )
  const missingModules = props.permissions
    .filter((permission) => !roleHasPermission(props.role as RbacRole, permission.name))
    .reduce<Set<string>>((set, permission) => {
      set.add(permission.module)
      return set
    }, new Set())

  const grantedGroups = groupPermissionsByModule(granted, props.moduleLabels)
  const emptyModules = [...missingModules]
    .filter((module) => !grantedGroups.some((group) => group.module === module))
    .map((module) => ({
      module,
      label: props.moduleLabels[module] ?? module,
      permissions: [] as RbacPermission[],
    }))

  return [...grantedGroups, ...emptyModules].sort((a, b) =>
    a.label.localeCompare(b.label, 'fr'),
  )
})

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.open) {
    emit('close')
  }
}

watch(
  () => props.open,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
  },
)

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && role"
      class="rbac-detail"
      role="dialog"
      aria-modal="true"
      :aria-label="`Permissions — ${role.label}`"
    >
      <button
        type="button"
        class="rbac-detail__backdrop"
        aria-label="Fermer"
        @click="emit('close')"
      />
      <div class="rbac-detail__panel">
        <header class="rbac-detail__header">
          <div>
            <p class="text-muted small mb-1">Détail du rôle</p>
            <h2 class="h4 mb-1">{{ role.label }}</h2>
            <p class="text-muted small mb-0">{{ permissionCountLabel(role) }}</p>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            aria-label="Fermer le panneau"
            @click="emit('close')"
          >
            <i class="bi bi-x-lg" aria-hidden="true" />
          </button>
        </header>

        <div class="rbac-detail__body">
          <p class="mb-3">{{ role.detail }}</p>

          <div v-if="role.isBypass" class="alert alert-info">
            L’administrateur n’a pas de permissions attachées en base :
            l’accès est global (bypass).
          </div>

          <div v-else-if="role.isCustom" class="alert alert-secondary">
            Ce rôle n’a pas de preset. Les permissions sont définies
            individuellement dans
            <strong>Administration → Utilisateurs</strong>.
          </div>

          <div v-else class="d-flex flex-column gap-3">
            <template v-for="group in groups" :key="group.module">
              <RbacPermissionGroup
                v-if="group.permissions.length"
                :module-label="group.label"
                :permissions="group.permissions"
                :selectable="false"
              />
              <div v-else class="rbac-detail__empty-module">
                <strong>{{ group.label }}</strong>
                <span class="text-muted">Aucun accès</span>
              </div>
            </template>
          </div>

          <div v-if="role.isBypass" class="d-flex flex-column gap-3 mt-3">
            <p class="small text-muted mb-0">
              Aperçu des modules couverts par le catalogue (référence) :
            </p>
            <RbacPermissionGroup
              v-for="group in groups"
              :key="group.module"
              :module-label="group.label"
              :permissions="group.permissions"
              :selectable="false"
            />
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.rbac-detail {
  position: fixed;
  inset: 0;
  z-index: 1080;
  display: flex;
  justify-content: flex-end;
}

.rbac-detail__backdrop {
  position: absolute;
  inset: 0;
  border: 0;
  background: rgba(15, 23, 42, 0.45);
  cursor: pointer;
}

.rbac-detail__panel {
  position: relative;
  width: min(560px, 100%);
  height: 100%;
  background: var(--color-surface, var(--bs-body-bg));
  color: var(--color-text, var(--bs-body-color));
  box-shadow: -8px 0 32px rgba(0, 0, 0, 0.18);
  display: flex;
  flex-direction: column;
  animation: rbac-slide-in 0.2s ease;
}

.rbac-detail__header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.25rem 1rem;
  border-bottom: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
}

.rbac-detail__body {
  padding: 1.25rem;
  overflow: auto;
  flex: 1;
}

.rbac-detail__empty-module {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.85rem 1rem;
  border: 1px dashed var(--color-border, rgba(0, 0, 0, 0.15));
  border-radius: 0.75rem;
}

@keyframes rbac-slide-in {
  from {
    transform: translateX(12px);
    opacity: 0.7;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

@media (max-width: 575.98px) {
  .rbac-detail__panel {
    width: 100%;
  }
}
</style>
