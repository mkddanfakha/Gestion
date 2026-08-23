<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/BootstrapLayout.vue'
import IndexPageLayout from '@/components/page/IndexPageLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import RbacRoleCard from '@/components/rbac/RbacRoleCard.vue'
import RbacPermissionDetail from '@/components/rbac/RbacPermissionDetail.vue'
import RbacPermissionMatrix from '@/components/rbac/RbacPermissionMatrix.vue'
import RbacAuditTimeline from '@/components/rbac/RbacAuditTimeline.vue'
import { route } from '@/lib/routes'
import type { RbacPermission, RbacRole } from '@/utils/rbacUi'

type AuditEvent = {
  id: number
  action: string
  actionLabel: string
  description: string
  actorName: string | null
  createdAt: string | null
}

const props = defineProps<{
  stats: { roles: number; permissions: number; users: number }
  roles: RbacRole[]
  permissions: RbacPermission[]
  legacyPermissions: RbacPermission[]
  modules: Array<{ key: string; label: string }>
  moduleLabels: Record<string, string>
  auditEvents: AuditEvent[]
}>()

const detailRole = ref<RbacRole | null>(null)
const detailOpen = ref(false)
const showLegacy = ref(false)
const differencesOnly = ref(true)

const compareableRoles = computed(() =>
  props.roles.filter((role) => !role.isCustom),
)

const selectedCompareRoles = ref<string[]>(
  compareableRoles.value.slice(0, 3).map((role) => role.name),
)

function openDetail(role: RbacRole) {
  detailRole.value = role
  detailOpen.value = true
}

function goToUsers(role: RbacRole) {
  router.get(route('admin.users.index'), { role: role.name })
}

function toggleCompareRole(roleName: string) {
  const current = [...selectedCompareRoles.value]
  const index = current.indexOf(roleName)
  if (index >= 0) {
    current.splice(index, 1)
  } else if (current.length < 3) {
    current.push(roleName)
  }
  selectedCompareRoles.value = current
}
</script>

<template>
  <AppLayout>
    <IndexPageLayout>
      <PageHeader
        title="Rôles & permissions"
        subtitle="Gérez les niveaux d’accès et les permissions de votre équipe."
        icon="bi-shield-check"
      >
        <template #actions-primary>
          <Link :href="route('admin.users.index')" class="btn btn-outline-secondary">
            <i class="bi bi-people me-1" aria-hidden="true" />
            Utilisateurs
          </Link>
        </template>
      </PageHeader>

      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="rbac-stat card h-100">
            <div class="card-body">
              <div class="text-muted small">Rôles</div>
              <div class="h3 mb-0">{{ stats.roles }}</div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="rbac-stat card h-100">
            <div class="card-body">
              <div class="text-muted small">Permissions (catalogue)</div>
              <div class="h3 mb-0">{{ stats.permissions }}</div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="rbac-stat card h-100">
            <div class="card-body">
              <div class="text-muted small">Utilisateurs</div>
              <div class="h3 mb-0">{{ stats.users }}</div>
            </div>
          </div>
        </div>
      </div>

      <section class="mb-4">
        <div class="d-flex justify-content-between align-items-end mb-3">
          <div>
            <h2 class="h5 mb-1">Rôles disponibles</h2>
            <p class="text-muted small mb-0">
              Les presets viennent du backend (RolePresets). L’administrateur utilise un bypass.
            </p>
          </div>
        </div>
        <div class="row g-3">
          <div
            v-for="role in roles"
            :key="role.name"
            class="col-12 col-md-6 col-xl-3"
          >
            <RbacRoleCard
              :role="role"
              @view="openDetail(role)"
              @users="goToUsers(role)"
            />
          </div>
        </div>
      </section>

      <section class="card rbac-compare mb-4">
        <div class="card-body">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1">Comparer les rôles</h2>
              <p class="text-muted small mb-0">
                Matrice générée dynamiquement depuis le catalogue et les presets.
              </p>
            </div>
            <div class="form-check form-switch">
              <input
                id="differences-only"
                v-model="differencesOnly"
                class="form-check-input"
                type="checkbox"
              />
              <label class="form-check-label" for="differences-only">
                Afficher uniquement les différences
              </label>
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Rôles à comparer">
            <button
              v-for="role in compareableRoles"
              :key="role.name"
              type="button"
              class="btn btn-sm"
              :class="
                selectedCompareRoles.includes(role.name)
                  ? 'btn-primary'
                  : 'btn-outline-secondary'
              "
              :aria-pressed="selectedCompareRoles.includes(role.name)"
              @click="toggleCompareRole(role.name)"
            >
              {{ role.label }}
            </button>
          </div>

          <RbacPermissionMatrix
            :permissions="permissions"
            :roles="roles"
            :selected-role-names="selectedCompareRoles"
            :differences-only="differencesOnly"
            :module-labels="moduleLabels"
          />
        </div>
      </section>

      <div class="row g-3 mb-4">
        <div class="col-lg-7">
          <RbacAuditTimeline :events="auditEvents" />
        </div>
        <div class="col-lg-5">
          <section class="card h-100 rbac-legacy">
            <div class="card-body">
              <h2 class="h5 mb-1">Compatibilité legacy</h2>
              <p class="text-muted small">
                Les permissions <code>*.edit</code> et <code>inventory.review</code>
                restent disponibles pour compatibilité de lecture uniquement.
                Les nouvelles attributions utilisent exclusivement
                <code>*.update</code> et <code>inventory.reopen</code>.
              </p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary mb-3"
                @click="showLegacy = !showLegacy"
              >
                {{ showLegacy ? 'Masquer' : 'Afficher' }} les permissions legacy
              </button>
              <ul v-if="showLegacy" class="small mb-0 ps-3">
                <li v-for="permission in legacyPermissions" :key="permission.name">
                  <code>{{ permission.name }}</code>
                  →
                  <code>{{ permission.canonicalName }}</code>
                </li>
              </ul>
            </div>
          </section>
        </div>
      </div>
    </IndexPageLayout>

    <RbacPermissionDetail
      :open="detailOpen"
      :role="detailRole"
      :permissions="permissions"
      :module-labels="moduleLabels"
      @close="detailOpen = false"
    />
  </AppLayout>
</template>

<style scoped>
.rbac-stat,
.rbac-compare,
.rbac-legacy {
  border-radius: 1rem;
  border: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
}
</style>
