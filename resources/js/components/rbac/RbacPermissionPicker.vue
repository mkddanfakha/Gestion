<script setup lang="ts">
import { computed, ref } from 'vue'
import RbacPermissionGroup from '@/components/rbac/RbacPermissionGroup.vue'
import {
  filterPermissions,
  groupPermissionsByModule,
  type PermissionFilter,
  type RbacPermission,
} from '@/utils/rbacUi'

const props = withDefaults(
  defineProps<{
    permissions: RbacPermission[]
    modelValue: number[]
    moduleLabels?: Record<string, string>
    disabled?: boolean
    showFilters?: boolean
  }>(),
  {
    moduleLabels: () => ({}),
    disabled: false,
    showFilters: true,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: number[]]
}>()

const query = ref('')
const filter = ref<PermissionFilter>('all')

const selected = computed({
  get: () => props.modelValue,
  set: (value: number[]) => emit('update:modelValue', value),
})

const filteredPermissions = computed(() =>
  filterPermissions(props.permissions, {
    query: query.value,
    filter: filter.value,
    labels: props.moduleLabels,
  }),
)

const groups = computed(() =>
  groupPermissionsByModule(filteredPermissions.value, props.moduleLabels),
)

const filterOptions: Array<{ id: PermissionFilter; label: string }> = [
  { id: 'all', label: 'Tous' },
  { id: 'view', label: 'Lecture' },
  { id: 'create', label: 'Création' },
  { id: 'update', label: 'Modification' },
  { id: 'delete', label: 'Suppression' },
  { id: 'inventory', label: 'Inventaire' },
  { id: 'admin', label: 'Administration' },
]

function selectAllVisible() {
  const ids = new Set(selected.value)
  for (const permission of filteredPermissions.value) {
    if (typeof permission.id === 'number') {
      ids.add(permission.id)
    }
  }
  selected.value = [...ids]
}

function deselectAllVisible() {
  const visible = new Set(
    filteredPermissions.value
      .map((permission) => permission.id)
      .filter((id): id is number => typeof id === 'number'),
  )
  selected.value = selected.value.filter((id) => !visible.has(id))
}
</script>

<template>
  <div class="rbac-permission-picker">
    <div class="mb-3">
      <label class="form-label" for="rbac-permission-search">
        Rechercher une permission
      </label>
      <div class="input-group">
        <span class="input-group-text" aria-hidden="true">
          <i class="bi bi-search" />
        </span>
        <input
          id="rbac-permission-search"
          v-model="query"
          type="search"
          class="form-control"
          placeholder="Rechercher une permission…"
          autocomplete="off"
        />
      </div>
    </div>

    <div
      v-if="showFilters"
      class="rbac-permission-picker__filters mb-3"
      role="group"
      aria-label="Filtrer les permissions"
    >
      <button
        v-for="option in filterOptions"
        :key="option.id"
        type="button"
        class="btn btn-sm"
        :class="filter === option.id ? 'btn-primary' : 'btn-outline-secondary'"
        @click="filter = option.id"
      >
        {{ option.label }}
      </button>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <button
        type="button"
        class="btn btn-sm btn-outline-primary"
        :disabled="disabled || filteredPermissions.length === 0"
        @click="selectAllVisible"
      >
        Tout sélectionner
      </button>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        :disabled="disabled"
        @click="deselectAllVisible"
      >
        Tout désélectionner
      </button>
    </div>

    <div v-if="groups.length === 0" class="text-muted small py-3">
      Aucune permission ne correspond à la recherche.
    </div>

    <div v-else class="d-flex flex-column gap-3">
      <RbacPermissionGroup
        v-for="group in groups"
        :key="group.module"
        v-model="selected"
        :module-label="group.label"
        :permissions="group.permissions"
        :disabled="disabled"
        value-key="id"
      />
    </div>
  </div>
</template>

<style scoped>
.rbac-permission-picker__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
</style>
