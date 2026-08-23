<script setup lang="ts">
import { computed } from 'vue'
import {
  isSensitivePermissionUi,
  type RbacPermission,
} from '@/utils/rbacUi'
import { permissionActionLabel } from '@/utils/rbacPermissions'

const props = withDefaults(
  defineProps<{
    moduleLabel: string
    permissions: RbacPermission[]
    modelValue?: number[] | string[]
    valueKey?: 'id' | 'name'
    disabled?: boolean
    selectable?: boolean
    showSensitiveBadge?: boolean
  }>(),
  {
    modelValue: () => [],
    valueKey: 'id',
    disabled: false,
    selectable: true,
    showSensitiveBadge: true,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: number[] | string[]]
}>()

const selected = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

function itemValue(permission: RbacPermission): number | string {
  if (props.valueKey === 'name') {
    return permission.name
  }
  return permission.id as number
}

function isGranted(permission: RbacPermission): boolean {
  if (!props.selectable) {
    return true
  }
  return (selected.value as Array<number | string>).includes(itemValue(permission))
}
</script>

<template>
  <section class="rbac-perm-group">
    <header class="rbac-perm-group__header">
      <h3 class="rbac-perm-group__title">{{ moduleLabel }}</h3>
      <span class="rbac-perm-group__count text-muted">
        {{ permissions.length }}
      </span>
    </header>

    <ul class="rbac-perm-group__list" role="list">
      <li
        v-for="permission in permissions"
        :key="permission.name"
        class="rbac-perm-group__item"
        :class="{
          'rbac-perm-group__item--selected': selectable && isGranted(permission),
          'rbac-perm-group__item--disabled': disabled,
        }"
      >
        <template v-if="selectable">
          <div class="form-check mb-0">
            <input
              :id="`rbac-perm-${permission.name}`"
              v-model="selected"
              class="form-check-input"
              type="checkbox"
              :value="itemValue(permission)"
              :disabled="disabled"
            />
            <label
              class="form-check-label rbac-perm-group__label"
              :for="`rbac-perm-${permission.name}`"
            >
              <span class="rbac-perm-group__name">
                {{ permission.label || permissionActionLabel(permission.action) }}
              </span>
              <span
                v-if="showSensitiveBadge && isSensitivePermissionUi(permission)"
                class="badge text-bg-warning-subtle text-warning-emphasis ms-2"
              >
                Sensible
              </span>
              <small
                v-if="permission.description"
                class="rbac-perm-group__desc d-block text-muted"
              >
                {{ permission.description }}
              </small>
            </label>
          </div>
        </template>
        <template v-else>
          <div class="rbac-perm-group__readonly">
            <i
              class="bi me-2"
              :class="isGranted(permission) ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-muted'"
              aria-hidden="true"
            />
            <div>
              <span class="rbac-perm-group__name">
                {{ permission.label || permissionActionLabel(permission.action) }}
              </span>
              <span
                v-if="showSensitiveBadge && isSensitivePermissionUi(permission)"
                class="badge text-bg-warning-subtle text-warning-emphasis ms-2"
              >
                Sensible
              </span>
              <small
                v-if="permission.description"
                class="rbac-perm-group__desc d-block text-muted"
              >
                {{ permission.description }}
              </small>
            </div>
          </div>
        </template>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.rbac-perm-group {
  border: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
  border-radius: 0.75rem;
  background: var(--color-surface, var(--bs-body-bg));
  overflow: hidden;
}

.rbac-perm-group__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--color-border, rgba(0, 0, 0, 0.08));
  background: color-mix(in srgb, var(--color-surface, var(--bs-body-bg)) 92%, var(--bs-secondary-bg));
}

.rbac-perm-group__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}

.rbac-perm-group__count {
  font-size: 0.8rem;
}

.rbac-perm-group__list {
  list-style: none;
  margin: 0;
  padding: 0.35rem 0;
}

.rbac-perm-group__item {
  padding: 0.55rem 1rem;
  transition: background-color 0.15s ease;
}

.rbac-perm-group__item--selected {
  background: color-mix(in srgb, var(--bs-primary) 8%, transparent);
}

.rbac-perm-group__item--disabled {
  opacity: 0.72;
}

.rbac-perm-group__readonly {
  display: flex;
  align-items: flex-start;
}

.rbac-perm-group__name {
  font-weight: 500;
}

.rbac-perm-group__desc {
  margin-top: 0.15rem;
  line-height: 1.35;
}
</style>
