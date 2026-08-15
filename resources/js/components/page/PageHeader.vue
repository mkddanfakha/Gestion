<script setup lang="ts">
import { computed, useSlots } from 'vue'

interface Props {
  title: string
  subtitle?: string
  icon?: string
}

const props = defineProps<Props>()
const slots = useSlots()

const iconClass = computed(() => {
  if (!props.icon) {
    return null
  }

  if (props.icon.startsWith('bi ')) {
    return props.icon
  }

  if (props.icon.startsWith('bi-')) {
    return `bi ${props.icon}`
  }

  return `bi bi-${props.icon}`
})

const hasActions = computed(() =>
  Boolean(slots['actions-primary'] || slots['actions-secondary'] || slots.actions),
)
</script>

<template>
  <header class="page-header">
    <div class="page-header__intro">
      <slot name="intro">
        <h1 class="h2 mb-1">
          <i v-if="iconClass" :class="[iconClass, 'me-2']" aria-hidden="true"></i>
          {{ title }}
        </h1>
        <p v-if="subtitle" class="page-header__subtitle text-muted mb-0">
          {{ subtitle }}
        </p>
      </slot>
    </div>

    <div v-if="hasActions" class="page-header__actions">
      <div v-if="$slots['actions-primary'] || $slots.actions" class="page-header__actions-primary">
        <slot name="actions-primary">
          <slot name="actions" />
        </slot>
      </div>
      <div v-if="$slots['actions-secondary']" class="page-header__actions-secondary">
        <slot name="actions-secondary" />
      </div>
    </div>
  </header>
</template>
