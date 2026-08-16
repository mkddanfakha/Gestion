<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      ref="dialogRef"
      class="doc-preview"
      role="dialog"
      aria-modal="true"
      aria-labelledby="doc-preview-title"
      tabindex="-1"
    >
      <div class="doc-preview__backdrop" @click="close"></div>

      <div class="doc-preview__panel" @click.stop>
        <header class="doc-preview__header">
          <h2 id="doc-preview-title" class="doc-preview__title">{{ documentTitle }}</h2>
          <button
            ref="closeButtonRef"
            type="button"
            class="doc-preview__icon-btn"
            aria-label="Fermer"
            @click="close"
          >
            <i class="bi bi-x-lg"></i>
          </button>
        </header>

        <div class="doc-preview__body">
          <div v-if="isLoading" class="doc-preview__state">
            <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
            <p class="mb-0 mt-3">Chargement de l'aperçu...</p>
          </div>

          <div v-else-if="state === 'error'" class="doc-preview__state">
            <i class="bi bi-exclamation-triangle fs-1 text-warning"></i>
            <p class="mb-0 mt-3">{{ errorMessage }}</p>
          </div>

          <div v-else-if="state === 'fallback'" class="doc-preview__state doc-preview__fallback">
            <i :class="fallbackIconClass"></i>
            <h3 class="doc-preview__fallback-title">{{ fallbackTitle }}</h3>
            <p class="doc-preview__fallback-text mb-0">{{ fallbackMessage }}</p>
          </div>

          <div
            v-else-if="state === 'embedded' && documentUrl && contentKind === 'image'"
            class="doc-preview__viewport doc-preview__viewport--image"
          >
            <img
              :key="documentUrl"
              :src="documentUrl"
              :alt="documentName"
              class="doc-preview__image"
              @load="handleImageLoad"
              @error="handleImageError"
            >
          </div>

          <div
            v-else-if="state === 'embedded' && documentUrl && contentKind === 'pdf'"
            class="doc-preview__viewport"
          >
            <div class="doc-preview__iframe-wrap" :style="iframeWrapStyle">
              <iframe
                :key="documentUrl"
                :src="documentUrl"
                class="doc-preview__iframe"
                title="Aperçu du document PDF"
                @load="handleIframeLoad"
                @error="handleIframeError"
              ></iframe>
            </div>
          </div>
        </div>

        <footer
          v-if="state === 'embedded' && contentKind === 'pdf' && documentUrl"
          class="doc-preview__toolbar"
        >
          <div class="doc-preview__toolbar-group">
            <span class="doc-preview__hint">
              <i class="bi bi-arrows-vertical me-1"></i>
              Faites défiler pour parcourir le document
            </span>
          </div>

          <div class="doc-preview__toolbar-group">
            <button type="button" class="doc-preview__icon-btn" aria-label="Zoom arrière" @click="zoomOut">
              <i class="bi bi-dash-lg"></i>
            </button>
            <span class="doc-preview__zoom-indicator">{{ zoomPercent }} %</span>
            <button type="button" class="doc-preview__icon-btn" aria-label="Zoom avant" @click="zoomIn">
              <i class="bi bi-plus-lg"></i>
            </button>
            <button type="button" class="doc-preview__fit-btn" @click="fitToWidth">
              Ajuster à la largeur
            </button>
          </div>
        </footer>

        <footer v-if="showActionsFooter" class="doc-preview__actions">
          <button v-if="state === 'error'" type="button" class="btn btn-primary" @click="retry">
            Réessayer
          </button>

          <button
            v-if="canOpenDocument"
            type="button"
            class="btn btn-outline-primary"
            @click="openDocumentExternally"
          >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            {{ openButtonLabel }}
          </button>

          <button
            v-if="canDownloadDocument"
            type="button"
            class="btn btn-success"
            @click="downloadDocument"
          >
            <i class="bi bi-download me-1"></i>
            Télécharger
          </button>

          <button
            v-if="state === 'embedded' && contentKind === 'pdf' && documentUrl"
            type="button"
            class="btn btn-outline-primary"
            @click="printDocument"
          >
            <i class="bi bi-printer me-1"></i>
            Imprimer
          </button>

          <button type="button" class="btn btn-outline-secondary" @click="close">
            Fermer
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
/**
 * Preview UI — couche présentation du Document Manager MKD-Pro.
 *
 * Affichage PDF/image, fallback, actions, responsive. Logique d'état dans useDocumentPreview.
 *
 * @see docs/document-manager.md
 */
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useDocumentPreview } from '@/composables/useDocumentPreview'
import { lockModalScroll, unlockModalScroll } from '@/composables/useModalScrollLock'
import { hasFinePointerInput } from '@/composables/usePdfEmbedSupport'

const {
  state,
  isOpen,
  isLoading,
  error,
  documentUrl,
  downloadUrl,
  documentName,
  documentTitle,
  contentKind,
  close,
  retry,
  enterFallback,
  confirmEmbedded,
  enterError,
} = useDocumentPreview()

const dialogRef = ref<HTMLElement | null>(null)
const closeButtonRef = ref<HTMLButtonElement | null>(null)
const zoomScale = ref(1)

const EMBED_UNCERTAINTY_TIMEOUT_MS = 6000

let embedUncertaintyTimer: number | null = null

const zoomPercent = computed(() => Math.round(zoomScale.value * 100))

const errorMessage = computed(
  () => error.value ?? 'Impossible de générer l\'aperçu du document.',
)

const showActionsFooter = computed(
  () => state.value === 'embedded' || state.value === 'fallback' || state.value === 'error',
)

const canOpenDocument = computed(() => Boolean(documentUrl.value))
const canDownloadDocument = computed(() => Boolean(downloadUrl.value))

const openButtonLabel = computed(() =>
  contentKind.value === 'pdf' ? 'Ouvrir le PDF' : 'Ouvrir',
)

const fallbackTitle = computed(() => {
  if (contentKind.value === 'pdf') {
    return 'Prévisualisation PDF'
  }

  return 'Aperçu non disponible'
})

const fallbackMessage = computed(() => {
  if (contentKind.value === 'pdf') {
    return 'Ce navigateur ne permet pas d\'afficher directement ce document dans la fenêtre d\'aperçu.'
  }

  return 'Ce type de fichier ne peut pas être prévisualisé directement.'
})

const fallbackIconClass = computed(() => {
  if (contentKind.value === 'pdf') {
    return 'bi bi-file-earmark-pdf fs-1 text-primary'
  }

  return 'bi bi-paperclip fs-1 text-primary'
})

const iframeWrapStyle = computed(() => ({
  transform: `scale(${zoomScale.value})`,
  transformOrigin: 'top center',
}))

const clearEmbedUncertaintyTimer = () => {
  if (embedUncertaintyTimer !== null) {
    window.clearTimeout(embedUncertaintyTimer)
    embedUncertaintyTimer = null
  }
}

const startEmbedUncertaintyTimer = () => {
  if (contentKind.value !== 'pdf') {
    return
  }

  clearEmbedUncertaintyTimer()

  embedUncertaintyTimer = window.setTimeout(() => {
    if (state.value === 'embedded') {
      enterFallback()
    }
  }, EMBED_UNCERTAINTY_TIMEOUT_MS)
}

const handleIframeLoad = () => {
  if (hasFinePointerInput()) {
    clearEmbedUncertaintyTimer()
    confirmEmbedded()
  }
}

const handleIframeError = () => {
  clearEmbedUncertaintyTimer()
  enterFallback()
}

const handleImageLoad = () => {
  // Image intégrée confirmée.
}

const handleImageError = () => {
  enterError('Impossible d\'afficher ce fichier.')
}

const handleEscape = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || !isOpen.value) {
    return
  }

  event.preventDefault()
  event.stopPropagation()
  close()
}

watch(isOpen, (open) => {
  if (open) {
    lockModalScroll()
    window.addEventListener('keydown', handleEscape, true)

    nextTick(() => {
      closeButtonRef.value?.focus()
      dialogRef.value?.focus()
    })

    return
  }

  window.removeEventListener('keydown', handleEscape, true)
  unlockModalScroll()
  zoomScale.value = 1
  clearEmbedUncertaintyTimer()
})

watch(
  () => [state.value, documentUrl.value, contentKind.value] as const,
  ([currentState, url, kind]) => {
    clearEmbedUncertaintyTimer()

    if (currentState === 'embedded' && url && kind === 'pdf') {
      startEmbedUncertaintyTimer()
    }
  },
)

onUnmounted(() => {
  window.removeEventListener('keydown', handleEscape, true)
  clearEmbedUncertaintyTimer()

  if (isOpen.value) {
    unlockModalScroll()
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

const downloadDocument = () => {
  if (!downloadUrl.value) {
    return
  }

  const link = document.createElement('a')
  link.href = downloadUrl.value
  link.download = documentName.value
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const openDocumentExternally = () => {
  if (!documentUrl.value) {
    return
  }

  window.open(documentUrl.value, '_blank', 'noopener,noreferrer')
}

const printDocument = () => {
  if (!documentUrl.value || contentKind.value !== 'pdf') {
    return
  }

  const printWindow = window.open(documentUrl.value, '_blank', 'noopener,noreferrer')

  if (printWindow) {
    printWindow.onload = () => {
      printWindow.focus()
      printWindow.print()
    }
  }
}
</script>

<style scoped>
.doc-preview {
  position: fixed;
  inset: 0;
  z-index: 1060;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem;
}

.doc-preview__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  pointer-events: auto;
}

.doc-preview__panel {
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
  pointer-events: auto;
}

.doc-preview__header,
.doc-preview__toolbar,
.doc-preview__actions {
  position: relative;
  z-index: 3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.875rem 1rem;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.doc-preview__actions {
  border-bottom: 0;
  border-top: 1px solid var(--color-border);
  flex-wrap: wrap;
  justify-content: center;
}

.doc-preview__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-text-primary);
}

.doc-preview__body {
  position: relative;
  z-index: 1;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  background: #3a3f47;
}

.doc-preview__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 420px;
  color: var(--color-text-secondary);
  padding: 2rem;
  background: var(--color-surface);
  text-align: center;
}

.doc-preview__fallback-title {
  margin: 1rem 0 0.5rem;
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text-primary);
}

.doc-preview__fallback-text {
  max-width: 28rem;
  line-height: 1.5;
}

.doc-preview__viewport {
  position: relative;
  z-index: 1;
  height: min(68vh, 720px);
  overflow: auto;
  overscroll-behavior: contain;
  padding: 1.5rem 1rem;
  -webkit-overflow-scrolling: touch;
}

.doc-preview__viewport--image {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface);
}

.doc-preview__image {
  display: block;
  max-width: 100%;
  max-height: min(68vh, 720px);
  width: auto;
  height: auto;
  object-fit: contain;
}

.doc-preview__iframe-wrap {
  display: flex;
  justify-content: center;
  width: 100%;
  margin: 0 auto;
  will-change: transform;
}

.doc-preview__iframe {
  display: block;
  width: 100%;
  min-height: min(68vh, 720px);
  border: 0;
  background: #fff;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
  pointer-events: auto;
}

.doc-preview__toolbar-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.doc-preview__hint {
  font-size: 0.8125rem;
  color: var(--color-text-secondary);
}

.doc-preview__zoom-indicator {
  min-width: 4.5rem;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-text-secondary);
}

.doc-preview__icon-btn {
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

.doc-preview__icon-btn:hover:not(:disabled) {
  background: var(--color-surface-hover);
}

.doc-preview__fit-btn {
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
  background: var(--color-surface-elevated);
  color: var(--color-text-secondary);
  font-size: 0.8125rem;
  padding: 0.35rem 0.65rem;
}

.doc-preview__fit-btn:hover {
  background: var(--color-surface-hover);
  color: var(--color-text-primary);
}

@media (max-width: 768px) {
  .doc-preview {
    padding: 0;
  }

  .doc-preview__panel {
    width: 100%;
    max-height: 100vh;
    border-radius: 0;
  }

  .doc-preview__viewport {
    height: calc(100vh - 240px);
  }

  .doc-preview__iframe {
    min-height: calc(100vh - 280px);
  }

  .doc-preview__image {
    max-height: calc(100vh - 280px);
  }

  .doc-preview__toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .doc-preview__actions {
    flex-direction: column;
    align-items: stretch;
  }

  .doc-preview__actions .btn {
    width: 100%;
    min-height: 2.75rem;
  }
}
</style>
