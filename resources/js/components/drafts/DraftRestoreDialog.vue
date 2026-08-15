<script setup lang="ts">
import { computed } from 'vue'
import { formatDraftDate } from '@/drafts/draftUtils'
import type { DraftFormConfig, DraftMode, FormDraftRecord } from '@/drafts/types'

const props = defineProps<{
  visible: boolean
  mode: DraftMode
  config: DraftFormConfig
  draft: FormDraftRecord | null
}>()

const emit = defineEmits<{
  restore: []
  dismiss: []
}>()

const title = computed(() =>
  props.mode === 'create' ? props.config.createRestoreTitle : props.config.editRestoreTitle,
)

const continueLabel = computed(() =>
  props.mode === 'create' ? props.config.createRestoreContinue : props.config.editRestoreContinue,
)

const discardLabel = computed(() =>
  props.mode === 'create' ? props.config.createRestoreDiscard : props.config.editRestoreDiscard,
)

const formattedDate = computed(() =>
  props.draft?.updatedAt ? formatDraftDate(props.draft.updatedAt) : '',
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible && draft"
      class="draft-restore-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="draft-restore-title"
    >
      <div class="draft-restore-dialog__backdrop" @click="emit('dismiss')"></div>
      <div class="draft-restore-dialog__panel">
        <h2 id="draft-restore-title" class="draft-restore-dialog__title">
          Brouillon retrouvé
        </h2>
        <p class="draft-restore-dialog__message">{{ title }}</p>
        <p v-if="formattedDate" class="draft-restore-dialog__date text-muted">
          Dernière modification : {{ formattedDate }}
        </p>
        <div class="draft-restore-dialog__actions">
          <button type="button" class="btn btn-primary" @click="emit('restore')">
            {{ continueLabel }}
          </button>
          <button type="button" class="btn btn-outline-secondary" @click="emit('dismiss')">
            {{ discardLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
