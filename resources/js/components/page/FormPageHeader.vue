<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

withDefaults(
  defineProps<{
    title: string
    subtitle?: string
    backHref: string
    backLabel?: string
  }>(),
  {
    backLabel: 'Retour',
  },
)
</script>

<template>
  <header class="form-page-header">
    <div class="form-page-header__main">
      <Link
        :href="backHref"
        class="form-page-header__back btn btn-outline-secondary"
        :aria-label="backLabel"
      >
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <span class="d-none d-sm-inline ms-1">{{ backLabel }}</span>
      </Link>

      <div class="form-page-header__intro">
        <h1 class="h2 mb-0">{{ title }}</h1>
        <p v-if="subtitle" class="form-page-header__subtitle text-muted mb-0">
          {{ subtitle }}
        </p>
        <div v-if="$slots.meta" class="form-page-header__meta">
          <slot name="meta" />
        </div>
      </div>
    </div>

    <div v-if="$slots.actions" class="form-page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>
