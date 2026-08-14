<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      class="doc-pdf-preview"
      role="dialog"
      aria-modal="true"
      aria-labelledby="doc-pdf-preview-title"
      @keydown.esc="close"
    >
      <div class="doc-pdf-preview__backdrop" @click="close"></div>

      <div class="doc-pdf-preview__panel">
        <header class="doc-pdf-preview__header">
          <h2 id="doc-pdf-preview-title" class="doc-pdf-preview__title">Aperçu du document</h2>
          <button type="button" class="doc-pdf-preview__icon-btn" aria-label="Fermer" @click="close">
            <i class="bi bi-x-lg"></i>
          </button>
        </header>

        <div class="doc-pdf-preview__body">
          <div v-if="isLoading" class="doc-pdf-preview__state">
            <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
            <p class="mb-0 mt-3">Génération de l'aperçu...</p>
          </div>

          <div v-else-if="error" class="doc-pdf-preview__state">
            <i class="bi bi-exclamation-triangle fs-1 text-warning"></i>
            <p class="mb-3 mt-3">{{ error }}</p>
            <button type="button" class="btn btn-primary" @click="retry">Réessayer</button>
          </div>

          <div v-else ref="viewportRef" class="doc-pdf-preview__viewport">
            <div class="doc-pdf-preview__iframe-wrap" :style="iframeWrapStyle">
              <iframe
                v-if="previewUrl"
                :src="previewUrl"
                class="doc-pdf-preview__iframe"
                title="Aperçu du document PDF"
              ></iframe>
            </div>
          </div>
        </div>

        <footer v-if="!isLoading && !error && previewUrl" class="doc-pdf-preview__toolbar">
          <div class="doc-pdf-preview__toolbar-group">
            <span class="doc-pdf-preview__hint">
              <i class="bi bi-arrows-vertical me-1"></i>
              Faites défiler pour parcourir le document
            </span>
          </div>

          <div class="doc-pdf-preview__toolbar-group">
            <button type="button" class="doc-pdf-preview__icon-btn" aria-label="Zoom arrière" @click="zoomOut">
              <i class="bi bi-dash-lg"></i>
            </button>
            <span class="doc-pdf-preview__zoom-indicator">{{ zoomPercent }} %</span>
            <button type="button" class="doc-pdf-preview__icon-btn" aria-label="Zoom avant" @click="zoomIn">
              <i class="bi bi-plus-lg"></i>
            </button>
            <button type="button" class="doc-pdf-preview__fit-btn" @click="fitToWidth">
              Ajuster à la largeur
            </button>
          </div>
        </footer>

        <footer v-if="!isLoading && !error && previewUrl" class="doc-pdf-preview__actions">
          <button type="button" class="btn btn-outline-secondary" @click="close">Fermer</button>
          <div class="doc-pdf-preview__actions-main">
            <button type="button" class="btn btn-outline-primary" @click="printPdf">
              <i class="bi bi-printer me-1"></i>
              Imprimer
            </button>
            <button type="button" class="btn btn-success" @click="downloadPdf">
              <i class="bi bi-download me-1"></i>
              Télécharger PDF
            </button>
          </div>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useDocumentPdfPreview } from '@/composables/useDocumentPdfPreview'

const { isOpen, isLoading, error, previewUrl, filename, close, retry } = useDocumentPdfPreview()

const viewportRef = ref<HTMLElement | null>(null)
const zoomScale = ref(1)

const zoomPercent = computed(() => Math.round(zoomScale.value * 100))

const iframeWrapStyle = computed(() => ({
  transform: `scale(${zoomScale.value})`,
  transformOrigin: 'top center',
}))

watch(isOpen, (open) => {
  if (!open) {
    zoomScale.value = 1
  }
})

const zoomIn = () => {
  zoomScale.value = Math.min(zoomScale.value + 0.1, 3)
}

const zoomOut = () => {
  zoomScale.value = Math.max(zoomScale.value - 0.1, 0.5)
}

const fitToWidth = () => {
  zoomScale.value = 1
}

const buildDownloadUrl = (url: string): string => {
  const parsed = new URL(url, window.location.origin)

  if (parsed.pathname.startsWith('/documents/preview/')) {
    parsed.searchParams.set('download', '1')
  }

  return parsed.toString()
}

const downloadPdf = () => {
  if (!previewUrl.value) {
    return
  }

  const link = document.createElement('a')
  link.href = buildDownloadUrl(previewUrl.value)
  link.download = filename.value
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const printPdf = () => {
  if (!previewUrl.value) {
    return
  }

  const printWindow = window.open(previewUrl.value, '_blank')

  if (printWindow) {
    printWindow.onload = () => {
      printWindow.focus()
      printWindow.print()
    }
  }
}
</script>

<style scoped>
.doc-pdf-preview {
  position: fixed;
  inset: 0;
  z-index: 1080;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
}

.doc-pdf-preview__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
}

.doc-pdf-preview__panel {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  width: min(1100px, 100%);
  max-height: calc(100vh - 1.5rem);
  background: var(--color-surface-elevated);
  border: 1px solid var(--color-border);
  border-radius: 0.75rem;
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

.doc-pdf-preview__header,
.doc-pdf-preview__toolbar,
.doc-pdf-preview__actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.875rem 1rem;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.doc-pdf-preview__actions {
  border-bottom: 0;
  border-top: 1px solid var(--color-border);
  flex-wrap: wrap;
}

.doc-pdf-preview__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-text-primary);
}

.doc-pdf-preview__body {
  flex: 1;
  min-height: 0;
  background: #3a3f47;
}

.doc-pdf-preview__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 420px;
  color: var(--color-text-secondary);
  padding: 2rem;
  background: var(--color-surface);
}

.doc-pdf-preview__viewport {
  height: min(68vh, 720px);
  overflow: auto;
  padding: 1.5rem 1rem;
}

.doc-pdf-preview__iframe-wrap {
  display: flex;
  justify-content: center;
  width: 100%;
  margin: 0 auto;
}

.doc-pdf-preview__iframe {
  display: block;
  width: 100%;
  min-height: min(68vh, 720px);
  border: 0;
  background: #fff;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
}

.doc-pdf-preview__toolbar-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.doc-pdf-preview__hint {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
}

.doc-pdf-preview__zoom-indicator {
  min-width: 4.5rem;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-text-secondary);
}

.doc-pdf-preview__icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
  background: var(--color-surface-elevated);
  color: var(--color-text-primary);
}

.doc-pdf-preview__icon-btn:hover:not(:disabled) {
  background: var(--color-surface-hover);
}

.doc-pdf-preview__fit-btn {
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
  background: var(--color-surface-elevated);
  color: var(--color-text-secondary);
  font-size: 0.8125rem;
  padding: 0.35rem 0.65rem;
}

.doc-pdf-preview__fit-btn:hover {
  background: var(--color-surface-hover);
  color: var(--color-text-primary);
}

.doc-pdf-preview__actions-main {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

@media (max-width: 768px) {
  .doc-pdf-preview {
    padding: 0;
  }

  .doc-pdf-preview__panel {
    width: 100%;
    max-height: 100vh;
    border-radius: 0;
  }

  .doc-pdf-preview__viewport {
    height: calc(100vh - 220px);
  }

  .doc-pdf-preview__toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .doc-pdf-preview__actions {
    flex-direction: column;
    align-items: stretch;
  }

  .doc-pdf-preview__actions-main {
    width: 100%;
  }

  .doc-pdf-preview__actions-main .btn {
    flex: 1;
  }
}
</style>
