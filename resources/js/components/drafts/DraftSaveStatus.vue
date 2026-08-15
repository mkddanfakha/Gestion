<script setup lang="ts">
import { computed } from 'vue'
import { formatRelativeSavedAt } from '@/drafts/draftUtils'
import type { DraftSaveState } from '@/drafts/types'

const props = defineProps<{
  status: DraftSaveState
  lastSavedAt?: string | null
}>()

const message = computed(() => {
  switch (props.status) {
    case 'saving':
      return 'Enregistrement...'
    case 'saved':
      return props.lastSavedAt
        ? `Enregistré ${formatRelativeSavedAt(props.lastSavedAt) ?? ''}`.trim()
        : 'Enregistré'
    case 'offline':
      return 'Hors connexion — saisie conservée sur cet appareil'
    case 'error':
      return 'Synchronisation en attente'
    case 'restored':
      return 'Brouillon restauré'
    case 'dirty':
      return 'Modifications non enregistrées'
    default:
      return ''
  }
})

const iconClass = computed(() => {
  switch (props.status) {
    case 'saving':
      return 'bi-arrow-repeat draft-save-status__icon--spin'
    case 'saved':
    case 'restored':
      return 'bi-check-circle-fill text-success'
    case 'offline':
    case 'error':
      return 'bi-exclamation-triangle-fill text-warning'
    case 'dirty':
      return 'bi-pencil'
    default:
      return ''
  }
})

const isVisible = computed(() => ['saving', 'saved', 'offline', 'error', 'restored', 'dirty'].includes(props.status))
</script>

<template>
  <div
    v-if="isVisible && message"
    class="draft-save-status"
    :class="`draft-save-status--${status}`"
    role="status"
    aria-live="polite"
  >
    <i class="bi draft-save-status__icon" :class="iconClass" aria-hidden="true"></i>
    <span class="draft-save-status__text">{{ message }}</span>
  </div>
</template>
