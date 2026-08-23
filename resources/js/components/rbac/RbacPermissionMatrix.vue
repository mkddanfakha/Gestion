<script setup lang="ts">
import { computed } from 'vue'
import {
  buildPermissionMatrix,
  groupPermissionsByModule,
  isSensitivePermissionUi,
  type RbacPermission,
  type RbacRole,
} from '@/utils/rbacUi'

const props = defineProps<{
  permissions: RbacPermission[]
  roles: RbacRole[]
  selectedRoleNames: string[]
  differencesOnly: boolean
  moduleLabels: Record<string, string>
}>()

const selectedRoles = computed(() =>
  props.roles.filter((role) => props.selectedRoleNames.includes(role.name)),
)

const matrixRows = computed(() =>
  buildPermissionMatrix(props.permissions, selectedRoles.value, {
    differencesOnly: props.differencesOnly,
  }),
)

const groupedRows = computed(() => {
  const byModule = groupPermissionsByModule(
    matrixRows.value.map((row) => row.permission),
    props.moduleLabels,
  )

  return byModule.map((group) => ({
    ...group,
    rows: matrixRows.value.filter((row) => row.permission.module === group.module),
  }))
})
</script>

<template>
  <div class="rbac-matrix">
    <div v-if="selectedRoles.length < 2" class="alert alert-secondary mb-0">
      Sélectionnez au moins deux rôles pour comparer.
    </div>

    <div v-else-if="matrixRows.length === 0" class="alert alert-light border mb-0">
      Aucune différence entre les rôles sélectionnés.
    </div>

    <div v-else class="rbac-matrix__scroll table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">Permission</th>
            <th
              v-for="role in selectedRoles"
              :key="role.name"
              scope="col"
              class="text-center"
            >
              {{ role.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in groupedRows" :key="group.module">
            <tr class="rbac-matrix__module">
              <th :colspan="selectedRoles.length + 1">{{ group.label }}</th>
            </tr>
            <tr v-for="row in group.rows" :key="row.permission.name">
              <td>
                <div class="fw-medium">{{ row.permission.label }}</div>
                <small class="text-muted">{{ row.permission.name }}</small>
                <span
                  v-if="isSensitivePermissionUi(row.permission)"
                  class="badge text-bg-warning-subtle text-warning-emphasis ms-1"
                >
                  Sensible
                </span>
              </td>
              <td
                v-for="role in selectedRoles"
                :key="`${row.permission.name}-${role.name}`"
                class="text-center"
              >
                <span class="visually-hidden">
                  {{ role.label }} :
                  {{ row.cells[role.name] ? 'autorisé' : 'refusé' }}
                </span>
                <i
                  class="bi"
                  :class="
                    row.cells[role.name]
                      ? 'bi-check-lg text-success'
                      : 'bi-x-lg text-danger'
                  "
                  aria-hidden="true"
                />
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.rbac-matrix__scroll {
  border: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
  border-radius: 0.75rem;
  max-height: 28rem;
}

.rbac-matrix__module th {
  background: color-mix(in srgb, var(--bs-secondary-bg) 80%, transparent);
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--bs-secondary-color);
}

@media (max-width: 767.98px) {
  .rbac-matrix__scroll {
    max-height: none;
  }

  .rbac-matrix td,
  .rbac-matrix th {
    white-space: nowrap;
  }
}
</style>
