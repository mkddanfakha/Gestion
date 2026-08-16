<template>
  <div class="attachment-list">
    <div
      v-for="attachment in attachments"
      :key="attachment.id"
      class="attachment-item border rounded p-2 p-md-3 mb-2"
    >
      <div class="d-flex align-items-start justify-content-between gap-2">
        <div class="d-flex align-items-start gap-2 min-w-0 flex-grow-1">
          <i :class="['bi', attachment.file_icon, 'fs-5 text-primary mt-1']"></i>
          <div class="min-w-0">
            <div class="fw-medium text-truncate">{{ attachment.original_name }}</div>
            <div class="small text-muted">
              {{ attachment.formatted_size }}
              <span v-if="attachment.uploaded_by">
                &bull; Ajouté par {{ attachment.uploaded_by.name }}
              </span>
              <span v-if="attachment.created_at">
                &bull; {{ formatDate(attachment.created_at) }}
              </span>
            </div>
          </div>
        </div>

        <div class="btn-group btn-group-sm flex-shrink-0" role="group">
          <button
            v-if="allowPreview"
            type="button"
            class="btn btn-outline-secondary"
            title="Aperçu"
            @click="emit('preview', attachment)"
          >
            <i class="bi bi-eye"></i>
          </button>
          <a
            :href="attachment.download_url"
            class="btn btn-outline-primary"
            title="Télécharger"
          >
            <i class="bi bi-download"></i>
          </a>
          <button
            v-if="allowDelete"
            type="button"
            class="btn btn-outline-danger"
            title="Supprimer"
            :disabled="deletingId === attachment.id"
            @click="confirmDelete(attachment)"
          >
            <span v-if="deletingId === attachment.id" class="spinner-border spinner-border-sm"></span>
            <i v-else class="bi bi-trash"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from '@/lib/routes'
import { useSweetAlert } from '@/composables/useSweetAlert'
import { formatDate } from '@/utils/dateFormatter'
import type { AttachmentRecord } from '@/types/attachment'

interface Props {
  attachments: AttachmentRecord[]
  allowDelete?: boolean
  allowPreview?: boolean
}

withDefaults(defineProps<Props>(), {
  allowDelete: false,
  allowPreview: true,
})

const emit = defineEmits<{
  preview: [attachment: AttachmentRecord]
  deleted: [attachment: AttachmentRecord]
}>()

const { confirm, success, error } = useSweetAlert()
const deletingId = ref<number | null>(null)

const confirmDelete = async (attachment: AttachmentRecord) => {
  const confirmed = await confirm(
    `Voulez-vous supprimer « ${attachment.original_name} » ?`,
    'Supprimer la pièce jointe ?'
  )

  if (!confirmed) return

  deletingId.value = attachment.id

  router.delete(route('attachments.destroy', { attachment: attachment.id }), {
    preserveScroll: true,
    onSuccess: () => {
      success('Le fichier a été supprimé.')
      emit('deleted', attachment)
    },
    onError: () => {
      error('Impossible de supprimer le fichier.')
    },
    onFinish: () => {
      deletingId.value = null
    },
  })
}
</script>

<style scoped>
.attachment-item:last-child {
  margin-bottom: 0 !important;
}
</style>
