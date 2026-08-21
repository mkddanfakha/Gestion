<template>
  <div class="barcode-scanner">
    <label v-if="label" :for="inputId" class="form-label barcode-scanner__label">
      <i class="bi bi-upc-scan me-1 text-muted" aria-hidden="true"></i>
      {{ label }}
    </label>

    <div class="input-group barcode-scanner__input-group">
      <span class="input-group-text barcode-scanner__icon" aria-hidden="true">
        <i class="bi bi-upc-scan"></i>
      </span>
      <input
        :id="inputId"
        ref="scanInputRef"
        v-model="scanBuffer"
        type="text"
        class="form-control barcode-scanner__input"
        :placeholder="placeholder"
        :disabled="disabled || processing"
        inputmode="text"
        autocomplete="off"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="done"
        aria-label="Scanner ou saisir un code-barres"
        @keydown.enter.prevent="submitHardwareScan"
      />
      <button
        type="button"
        class="btn btn-outline-primary barcode-scanner__submit-btn"
        :disabled="disabled || processing || !scanBuffer.trim()"
        aria-label="Valider le code-barres saisi"
        @click="submitHardwareScan"
      >
        <i class="bi bi-check2" aria-hidden="true"></i>
        <span class="barcode-scanner__btn-label ms-1">Valider</span>
      </button>
      <button
        v-if="showCameraButton && isDev"
        type="button"
        class="btn btn-outline-secondary barcode-scanner__camera-btn"
        :disabled="disabled || processing"
        aria-label="Ouvrir le scanner caméra"
        @click="openCamera"
      >
        <i class="bi bi-camera" aria-hidden="true"></i>
        <span class="barcode-scanner__btn-label ms-1">Scanner</span>
      </button>
    </div>

    <p class="form-text barcode-scanner__hint mb-0 mt-2">
      Douchette USB/Bluetooth (mode clavier) ou saisie manuelle + Entrée.
      <span v-if="isDev && showCameraButton"> Caméra disponible en DEV uniquement.</span>
    </p>

    <div
      v-if="capabilityNotice"
      class="alert alert-info barcode-scanner__capability-notice mt-2 mb-0"
      role="status"
      aria-live="polite"
    >
      {{ capabilityNotice }}
    </div>

    <div
      v-if="statusMessage"
      class="barcode-scanner__status mt-2"
      :class="`barcode-scanner__status--${statusVariant}`"
      role="status"
      aria-live="polite"
    >
      {{ statusMessage }}
    </div>

    <div
      v-if="notFoundBarcode"
      class="alert alert-warning barcode-scanner__not-found mt-2 mb-0"
      role="alert"
    >
      <div class="fw-semibold">Produit introuvable</div>
      <div class="small mt-1">Code-barres : <span class="font-monospace">{{ notFoundBarcode }}</span></div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-sm btn-outline-primary" @click="emitSearchRequest">
          Rechercher le produit
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="emitDismissNotFound">
          Fermer
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="cameraOpen"
        class="barcode-scanner-modal-root"
        role="dialog"
        aria-modal="true"
        aria-labelledby="barcode-scanner-modal-title"
      >
        <div class="barcode-scanner-modal-root__backdrop" @click="closeCamera"></div>
        <div class="barcode-scanner-modal">
          <div class="barcode-scanner-modal__header">
            <h2 id="barcode-scanner-modal-title" class="h6 mb-0">Scanner un code-barres</h2>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              aria-label="Fermer le scanner"
              @click="closeCamera"
            >
              <i class="bi bi-x-lg" aria-hidden="true"></i>
              Fermer
            </button>
          </div>

          <div class="barcode-scanner-modal__body">
            <div v-if="cameraPreviewActive" class="barcode-scanner-modal__viewport">
              <video
                ref="videoRef"
                class="barcode-scanner-modal__video"
                playsinline
                muted
                aria-hidden="true"
              ></video>
              <div class="barcode-scanner-modal__frame" aria-hidden="true"></div>

              <div
                v-if="showDiagnosticPanel"
                class="barcode-scanner-modal__diagnostic"
                aria-live="polite"
              >
                <div class="barcode-scanner-modal__diagnostic-header">
                  <span class="barcode-scanner-modal__diagnostic-title">Diagnostic scanner DEV</span>
                  <div class="barcode-scanner-modal__diagnostic-actions">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      @click="resetDiagnosticCounters"
                    >
                      Réinitialiser
                    </button>
                    <button
                      v-if="canCopyDiagnostic"
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      @click="copyDiagnosticSummary"
                    >
                      Copier
                    </button>
                    <button
                      v-if="showDiagnosticPanel"
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="scannerPhase !== 'scanning' || diagnosticImageTestRunning"
                      @click="testCurrentCameraFrame"
                    >
                      Test image caméra
                    </button>
                    <button
                      v-if="showDiagnosticPanel"
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="scannerPhase !== 'scanning' || diagnosticImageTestRunning"
                      @click="testMultipleCameraVariants"
                    >
                      Test multi-images
                    </button>
                    <button
                      v-if="showDiagnosticPanel"
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="scannerPhase !== 'scanning' || diagnosticImageTestRunning"
                      @click="testBarcodeDetector"
                    >
                      Test BarcodeDetector
                    </button>
                    <button
                      v-if="showDiagnosticPanel && nativeBarcodeDetectorAvailable"
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="scannerPhase !== 'scanning' || diagnosticImageTestRunning"
                      @click="testBarcodeDetectorPolyfill"
                    >
                      Test BarcodeDetector polyfill
                    </button>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-reader-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test vue-qrcode-reader
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/native-barcode-detector-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test BarcodeDetector natif
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/native-camera-minimal-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test caméra minimal
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/native-camera-visual-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test caméra visuel
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-engine-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test moteurs code-barres
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/native-barcode-detector-live-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test BarcodeDetector live
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-detector-resolution-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test résolution BarcodeDetector
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-detector-roi-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test ROI / Zoom BarcodeDetector
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-detector-camera-controls-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test Focus / Zoom matériel
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-detector-focus-sharpness-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test Focus + Netteté
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-detector-manual-focus-test"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Test Focus manuel réel
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/barcode-detector-manual-focus-experiment"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Expérience Focus × Zoom
                    </a>
                    <a
                      v-if="showDiagnosticPanel"
                      href="/dev/native-camera-stream-diagnostic"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-outline-secondary"
                    >
                      Diagnostic flux caméra
                    </a>
                  </div>
                </div>

                <dl class="barcode-scanner-modal__diagnostic-grid">
                  <div><dt>Caméra</dt><dd>{{ diagnostic.cameraState }}</dd></div>
                  <div><dt>Stream</dt><dd>{{ diagnostic.streamActive ? 'actif' : 'inactif' }}</dd></div>
                  <div><dt>Vidéo</dt><dd>{{ diagnostic.videoWidth }} × {{ diagnostic.videoHeight }}</dd></div>
                  <div><dt>ReadyState</dt><dd>{{ diagnostic.readyState }}</dd></div>
                  <div><dt>Frames</dt><dd>{{ diagnostic.frameCount }}</dd></div>
                  <div><dt>Tracks</dt><dd>{{ diagnostic.trackCount }}</dd></div>
                  <div><dt>Video tracks</dt><dd>{{ diagnostic.videoTrackCount }}</dd></div>
                  <div><dt>Track</dt><dd>{{ diagnostic.trackReadyState || '—' }}</dd></div>
                  <div><dt>Facing</dt><dd>{{ diagnostic.facingMode || '—' }}</dd></div>
                  <div><dt>Résolution</dt><dd>{{ diagnostic.trackWidth }} × {{ diagnostic.trackHeight }}</dd></div>
                </dl>

                <div class="barcode-scanner-modal__diagnostic-section">Moteur caméra</div>
                <dl class="barcode-scanner-modal__diagnostic-grid">
                  <div><dt>Camera engine</dt><dd>{{ cameraEngineLabel }}</dd></div>
                  <div><dt>Native BarcodeDetector</dt><dd>{{ nativeDetectorAvailabilityLabel }}</dd></div>
                  <div><dt>Fallback ZXing</dt><dd>available</dd></div>
                  <div><dt>Fallback used</dt><dd>{{ fallbackUsedLabel }}</dd></div>
                  <div v-if="cameraEngineFallbackReason">
                    <dt>Fallback reason</dt>
                    <dd>{{ cameraEngineFallbackReason }}</dd>
                  </div>
                </dl>

                <template v-if="activeCameraEngine === 'native'">
                  <div class="barcode-scanner-modal__diagnostic-section">BarcodeDetector natif</div>
                  <dl class="barcode-scanner-modal__diagnostic-grid">
                    <div><dt>Native detections</dt><dd>{{ nativeEngineStats.success }}</dd></div>
                    <div><dt>Native not found</dt><dd>{{ nativeEngineStats.notFound }}</dd></div>
                    <div><dt>Native errors</dt><dd>{{ nativeEngineStats.errors }}</dd></div>
                    <div><dt>Native attempts</dt><dd>{{ nativeEngineStats.attempts }}</dd></div>
                  </dl>
                  <dl class="barcode-scanner-modal__diagnostic-grid barcode-scanner-modal__diagnostic-grid--full">
                    <div><dt>Last native result</dt><dd class="font-monospace">{{ nativeEngineStats.lastResult || '—' }}</dd></div>
                    <div><dt>Last native format</dt><dd>{{ nativeEngineStats.lastFormat || '—' }}</dd></div>
                  </dl>
                </template>

                <div class="barcode-scanner-modal__diagnostic-section">ZXing</div>
                <dl class="barcode-scanner-modal__diagnostic-grid">
                  <div><dt>Callbacks</dt><dd>{{ diagnostic.callbacks }}</dd></div>
                  <div><dt>NotFound</dt><dd>{{ diagnostic.notFound }}</dd></div>
                  <div><dt>Résultats</dt><dd>{{ diagnostic.results }}</dd></div>
                  <div><dt>Erreurs</dt><dd>{{ diagnostic.fatalErrors }}</dd></div>
                </dl>

                <dl class="barcode-scanner-modal__diagnostic-grid barcode-scanner-modal__diagnostic-grid--full">
                  <div><dt>Dernier code</dt><dd class="font-monospace">{{ diagnostic.lastResult || '—' }}</dd></div>
                  <div><dt>Format</dt><dd>{{ diagnostic.lastFormat || '—' }}</dd></div>
                  <div><dt>Dernière err.</dt><dd>{{ diagnostic.lastError || '—' }}</dd></div>
                </dl>

                <div class="barcode-scanner-modal__diagnostic-section">Image test (DEV temp)</div>
                <dl class="barcode-scanner-modal__diagnostic-grid barcode-scanner-modal__diagnostic-grid--full">
                  <div><dt>Status</dt><dd>{{ diagnosticImageStatusLabel() }}</dd></div>
                  <div><dt>Result</dt><dd class="font-monospace">{{ diagnosticImageResult || '—' }}</dd></div>
                  <div><dt>Format</dt><dd>{{ diagnosticImageFormat || '—' }}</dd></div>
                  <div><dt>Error</dt><dd>{{ diagnosticImageError || '—' }}</dd></div>
                </dl>

                <dl class="barcode-scanner-modal__diagnostic-grid">
                  <div><dt>Frame W</dt><dd>{{ diagnosticFrameWidth || '—' }}</dd></div>
                  <div><dt>Frame H</dt><dd>{{ diagnosticFrameHeight || '—' }}</dd></div>
                  <div><dt>ReadyState</dt><dd>{{ diagnosticFrameReadyState || '—' }}</dd></div>
                  <div><dt>CurrentTime</dt><dd>{{ diagnosticFrameCurrentTime || '—' }}</dd></div>
                </dl>

                <dl class="barcode-scanner-modal__diagnostic-grid">
                  <div><dt>Cam. width</dt><dd>{{ diagnosticCaptureTrackWidth || '—' }}</dd></div>
                  <div><dt>Cam. height</dt><dd>{{ diagnosticCaptureTrackHeight || '—' }}</dd></div>
                  <div><dt>Cam. facing</dt><dd>{{ diagnosticCaptureFacingMode || '—' }}</dd></div>
                  <div><dt>Cam. FPS</dt><dd>{{ diagnosticCaptureFrameRate || '—' }}</dd></div>
                </dl>

                <p v-if="diagnosticFrameWidth && diagnosticFrameHeight" class="barcode-scanner-modal__diagnostic-notice mb-0">
                  Frame capturée : {{ diagnosticFrameWidth }} × {{ diagnosticFrameHeight }}
                </p>

                <img
                  v-if="showDiagnosticPanel && diagnosticFrameUrl"
                  :src="diagnosticFrameUrl"
                  alt="Frame caméra capturée pour diagnostic"
                  class="barcode-scanner-modal__diagnostic-frame"
                />

                <div
                  v-if="showDiagnosticPanel && diagnosticVariantResults.length > 0"
                  class="barcode-scanner-modal__diagnostic-section"
                >
                  Multi-images (DEV temp)
                </div>

                <div
                  v-if="showDiagnosticPanel && diagnosticVariantResults.length > 0"
                  class="barcode-scanner-modal__diagnostic-variant-list"
                >
                  <div
                    v-for="variant in diagnosticVariantResults"
                    :key="variant.name"
                    class="barcode-scanner-modal__diagnostic-variant-card"
                  >
                    <div class="barcode-scanner-modal__diagnostic-variant-header">
                      <strong>{{ variant.label }}</strong>
                      <span>{{ formatDiagnosticVariantStatus(variant.status) }}</span>
                    </div>
                    <div class="barcode-scanner-modal__diagnostic-variant-meta">
                      {{ variant.width }} × {{ variant.height }}
                      <span v-if="variant.result" class="font-monospace"> · {{ variant.result }}</span>
                      <span v-if="variant.format"> · {{ variant.format }}</span>
                    </div>
                    <div v-if="variant.error" class="barcode-scanner-modal__diagnostic-variant-error">
                      {{ variant.error }}
                    </div>
                    <img
                      v-if="diagnosticVariantPreviewsExpanded && variant.previewUrl"
                      :src="variant.previewUrl"
                      :alt="`Aperçu ${variant.label}`"
                      class="barcode-scanner-modal__diagnostic-frame"
                    />
                  </div>
                </div>

                <div
                  v-if="showDiagnosticPanel && diagnosticMultiTestSummary"
                  class="barcode-scanner-modal__diagnostic-summary"
                >
                  <div class="barcode-scanner-modal__diagnostic-section">Résultat du test</div>
                  <p class="mb-1">Variantes testées : {{ diagnosticMultiTestSummary.tested }}</p>
                  <p class="mb-1">Succès : {{ diagnosticMultiTestSummary.success }}</p>
                  <p class="mb-1">Not Found : {{ diagnosticMultiTestSummary.notFound }}</p>
                  <p class="mb-1">Erreurs : {{ diagnosticMultiTestSummary.errors }}</p>
                  <template v-if="diagnosticMultiTestSummary.bestVariant">
                    <p class="mb-1 mt-2">Meilleure variante : {{ diagnosticMultiTestSummary.bestVariant.label }}</p>
                    <p class="mb-1 font-monospace">Résultat : {{ diagnosticMultiTestSummary.bestVariant.result }}</p>
                    <p class="mb-0">Format : {{ diagnosticMultiTestSummary.bestVariant.format || '—' }}</p>
                  </template>
                  <p v-else class="mb-0 mt-2">Aucune variante n'a été décodée.</p>
                </div>

                <div
                  v-if="showDiagnosticPanel && diagnosticBarcodeDetectorResult"
                  class="barcode-scanner-modal__diagnostic-summary"
                >
                  <div class="barcode-scanner-modal__diagnostic-section">BarcodeDetector test</div>
                  <dl class="barcode-scanner-modal__diagnostic-grid barcode-scanner-modal__diagnostic-grid--full">
                    <div><dt>Engine</dt><dd>{{ diagnosticBarcodeDetectorResult.engineLabel }}</dd></div>
                    <div><dt>Status</dt><dd>{{ diagnosticBarcodeDetectorResult.status }}</dd></div>
                    <div><dt>Result</dt><dd class="font-monospace">{{ diagnosticBarcodeDetectorResult.result || '—' }}</dd></div>
                    <div><dt>Format</dt><dd>{{ diagnosticBarcodeDetectorResult.format || '—' }}</dd></div>
                    <div><dt>Duration</dt><dd>{{ diagnosticBarcodeDetectorDurationLabel }}</dd></div>
                    <div><dt>Image</dt><dd>{{ diagnosticBarcodeDetectorImageLabel }}</dd></div>
                  </dl>
                  <dl class="barcode-scanner-modal__diagnostic-grid">
                    <div><dt>Video</dt><dd>{{ diagnosticBarcodeDetectorVideoLabel }}</dd></div>
                    <div><dt>ReadyState</dt><dd>{{ diagnosticBarcodeDetectorResult.videoReadyState ?? '—' }}</dd></div>
                    <div><dt>Paused</dt><dd>{{ diagnosticBarcodeDetectorResult.videoPaused ? 'oui' : 'non' }}</dd></div>
                    <div><dt>Camera</dt><dd>{{ diagnosticBarcodeDetectorCameraLabel }}</dd></div>
                  </dl>
                  <p v-if="diagnosticBarcodeDetectorResult.error" class="barcode-scanner-modal__diagnostic-variant-error mb-2">
                    {{ diagnosticBarcodeDetectorResult.error }}
                  </p>
                  <p class="barcode-scanner-modal__diagnostic-section mb-1">Formats supportés</p>
                  <p v-if="diagnosticBarcodeDetectorSupportedFormatLabels.length === 0" class="mb-0">—</p>
                  <ul v-else class="barcode-scanner-modal__diagnostic-format-list mb-0">
                    <li v-for="formatLabel in diagnosticBarcodeDetectorSupportedFormatLabels" :key="formatLabel">
                      {{ formatLabel }}
                    </li>
                  </ul>
                </div>

                <p v-if="diagnosticCopyNotice" class="barcode-scanner-modal__diagnostic-notice mb-0">
                  {{ diagnosticCopyNotice }}
                </p>
              </div>
            </div>

            <p class="barcode-scanner-modal__hint mb-0" :class="{ 'mt-3': !cameraPreviewActive }">
              {{ cameraStatus }}
            </p>

            <div
              v-if="cameraError"
              class="alert alert-danger barcode-scanner-modal__error mt-3 mb-0"
              role="alert"
            >
              {{ cameraError }}
            </div>

            <div v-if="cameraError" class="d-flex justify-content-end mt-3">
              <button type="button" class="btn btn-outline-secondary" @click="closeCamera">
                Retour au champ manuel
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { BrowserMultiFormatReader, type IScannerControls } from '@zxing/browser'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import {
  applyPreferredCameraTrackSettings,
  getBarcodeCameraAvailability,
  getBarcodeCameraConstraints,
  getBarcodeCameraErrorMessage,
  hasLiveMediaStreamTracks,
  isFatalBarcodeScanError,
  isTransientBarcodeScanError,
  releaseAllBarcodeCameraStreams,
  stopMediaStreamTracks,
  waitForVideoFrameReady,
} from '@/utils/barcodeCamera'
import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'
import {
  buildDiagnosticMultiTestSummary,
  buildDiagnosticVariantCanvases,
  captureSourceCanvasFromVideo,
  createBarcodeDecodeStatsTracker,
  createInitialDiagnosticPanelState,
  decodeDiagnosticVariantCanvas,
  formatBarcodeFormatValue,
  formatDiagnosticPanelForClipboard,
  formatDiagnosticVariantStatus,
  logVideoDiagnostics,
  resetDiagnosticPanelCounters,
  startVideoFrameFlowMonitor,
  syncDiagnosticPanelFromMedia,
  syncDiagnosticPanelFromStats,
  type DiagnosticMultiTestSummary,
  type DiagnosticVariantResult,
} from '@/utils/barcodeScannerDiagnostics'
import {
  createNativeBarcodeDetector,
  formatNativeBarcodeFormat,
  isNativeBarcodeDetectorAvailable,
  NATIVE_DETECTION_INTERVAL_MS,
  NATIVE_FATAL_ERROR_THRESHOLD,
  pickBestNativeBarcode,
  type BarcodeDetectorLike,
} from '@/utils/nativeBarcodeScannerEngine'

type ScannerPhase = 'idle' | 'starting' | 'scanning' | 'stopping'
type CameraBarcodeEngine = 'native' | 'zxing'

const props = withDefaults(
  defineProps<{
    disabled?: boolean
    processing?: boolean
    placeholder?: string
    label?: string
    showCameraButton?: boolean
    notFoundBarcode?: string | null
    statusMessage?: string
    statusVariant?: 'info' | 'success' | 'warning' | 'danger'
    autofocus?: boolean
  }>(),
  {
    disabled: false,
    processing: false,
    placeholder: 'Scanner ou saisir un code-barres...',
    label: 'Code-barres',
    showCameraButton: false,
    notFoundBarcode: null,
    statusMessage: '',
    statusVariant: 'info',
    autofocus: true,
  },
)

const emit = defineEmits<{
  'barcode-detected': [barcode: string]
  'search-request': [barcode: string]
  'dismiss-not-found': []
}>()

const DEBUG = import.meta.env.DEV
const isDev = import.meta.env.DEV
const showDiagnosticPanel = DEBUG
const nativeBarcodeDetectorAvailable = DEBUG
  && typeof window !== 'undefined'
  && 'BarcodeDetector' in window
const canCopyDiagnostic = typeof navigator !== 'undefined' && Boolean(navigator.clipboard?.writeText)
const diagnostic = reactive(createInitialDiagnosticPanelState())
const diagnosticCopyNotice = ref('')
const diagnosticFrameUrl = ref<string | null>(null)
const diagnosticFrameWidth = ref(0)
const diagnosticFrameHeight = ref(0)
const diagnosticFrameReadyState = ref(0)
const diagnosticFrameCurrentTime = ref(0)
const diagnosticCaptureTrackWidth = ref(0)
const diagnosticCaptureTrackHeight = ref(0)
const diagnosticCaptureFacingMode = ref('')
const diagnosticCaptureFrameRate = ref(0)
const diagnosticImageStatus = ref<'idle' | 'testing' | 'success' | 'not-found' | 'error'>('idle')
const diagnosticImageResult = ref<string | null>(null)
const diagnosticImageFormat = ref<string | null>(null)
const diagnosticImageError = ref<string | null>(null)
const diagnosticImageTestRunning = ref(false)
const diagnosticVariantResults = ref<DiagnosticVariantResult[]>([])
const diagnosticMultiTestSummary = ref<DiagnosticMultiTestSummary | null>(null)
const diagnosticVariantPreviewsExpanded = ref(false)
const diagnosticBarcodeDetectorResult = ref<import('@/utils/barcodeDetectorDiagnostics').BarcodeDetectorDiagnosticResult | null>(null)
const activeCameraEngine = ref<CameraBarcodeEngine | null>(null)
const cameraEngineFallbackReason = ref<string | null>(null)
const fallbackUsed = ref(false)
const nativeEngineStats = reactive({
  attempts: 0,
  success: 0,
  notFound: 0,
  errors: 0,
  lastResult: '',
  lastFormat: '',
})

const cameraEngineLabel = computed(() => {
  if (activeCameraEngine.value === 'native') {
    return 'BarcodeDetector'
  }

  if (activeCameraEngine.value === 'zxing') {
    return fallbackUsed.value ? 'ZXing fallback' : 'ZXing'
  }

  return '—'
})

const nativeDetectorAvailabilityLabel = computed(() => {
  return isNativeBarcodeDetectorAvailable() ? 'available' : 'unavailable'
})

const fallbackUsedLabel = computed(() => {
  return fallbackUsed.value ? 'yes' : 'no'
})

const diagnosticBarcodeDetectorDurationLabel = computed(() => {
  const durationMs = diagnosticBarcodeDetectorResult.value?.durationMs

  if (durationMs == null) {
    return '—'
  }

  return `${durationMs} ms`
})

const diagnosticBarcodeDetectorImageLabel = computed(() => {
  const result = diagnosticBarcodeDetectorResult.value

  if (!result?.imageWidth || !result.imageHeight) {
    return '—'
  }

  return `${result.imageWidth} × ${result.imageHeight}`
})

const diagnosticBarcodeDetectorVideoLabel = computed(() => {
  const result = diagnosticBarcodeDetectorResult.value

  if (!result?.videoWidth || !result.videoHeight) {
    return '—'
  }

  return `${result.videoWidth} × ${result.videoHeight}`
})

const diagnosticBarcodeDetectorCameraLabel = computed(() => {
  const result = diagnosticBarcodeDetectorResult.value

  if (!result) {
    return '—'
  }

  const parts = [
    result.cameraFacingMode || '—',
    result.cameraWidth && result.cameraHeight
      ? `${result.cameraWidth} × ${result.cameraHeight}`
      : '—',
    result.cameraFrameRate ? `${result.cameraFrameRate} fps` : '—',
  ]

  return parts.join(' · ')
})

const diagnosticBarcodeDetectorSupportedFormatLabels = computed(() => {
  const formats = diagnosticBarcodeDetectorResult.value?.supportedFormats ?? []

  return formats.map((format) => {
    switch (format) {
      case 'ean_13':
        return 'EAN-13'
      case 'ean_8':
        return 'EAN-8'
      case 'upc_a':
        return 'UPC-A'
      case 'upc_e':
        return 'UPC-E'
      case 'code_128':
        return 'Code 128'
      case 'code_39':
        return 'Code 39'
      default:
        return format
    }
  })
})

const inputId = `barcode-scanner-${Math.random().toString(36).slice(2, 9)}`
const scanInputRef = ref<HTMLInputElement | null>(null)
const scanBuffer = ref('')
const cameraOpen = ref(false)
const cameraPreviewActive = ref(false)
const cameraError = ref('')
const cameraStatus = ref('Placez le code-barres devant la caméra')
const capabilityNotice = ref('')
const videoRef = ref<HTMLVideoElement | null>(null)

let barcodeReader: BrowserMultiFormatReader | null = null
let scannerControls: IScannerControls | null = null
let activeMediaStream: MediaStream | null = null
let pendingScannerStart: Promise<IScannerControls> | null = null
let lastHardwareEmit: { barcode: string; at: number } | null = null
let scanSessionId = 0
let cleanupCameraPromise: Promise<void> | null = null
let hasEmittedFromCamera = false
let isComponentMounted = true
let stopDecodeStats: (() => void) | null = null
let stopFrameFlowMonitor: (() => void) | null = null
let nativeBarcodeDetector: BarcodeDetectorLike | null = null
let nativeDetectionLoopId: number | null = null
let nativeDetectionInProgress = false
let nativeLastDetectionTime = 0
let nativeFatalErrorCount = 0
let sessionUsesZxingFallback = false
let activeStatsTracker: ReturnType<typeof createBarcodeDecodeStatsTracker> | null = null
const scannerPhase = ref<ScannerPhase>('idle')

const DUPLICATE_EVENT_WINDOW_MS = 150

const debugLog = (...args: unknown[]) => {
  if (DEBUG) {
    console.info('[BarcodeScanner]', ...args)
  }
}

const syncDiagnosticPanel = () => {
  if (!DEBUG) {
    return
  }

  syncDiagnosticPanelFromMedia(
    diagnostic,
    videoRef.value,
    captureActiveMediaStream(),
    scannerPhase.value,
  )
}

const resetDiagnosticCounters = () => {
  if (!DEBUG) {
    return
  }

  resetDiagnosticPanelCounters(diagnostic)
  nativeEngineStats.attempts = 0
  nativeEngineStats.success = 0
  nativeEngineStats.notFound = 0
  nativeEngineStats.errors = 0
  nativeEngineStats.lastResult = ''
  nativeEngineStats.lastFormat = ''
  diagnosticCopyNotice.value = ''
}

const resetCameraEngineState = () => {
  activeCameraEngine.value = null
  cameraEngineFallbackReason.value = null
  fallbackUsed.value = false
  sessionUsesZxingFallback = false
  nativeFatalErrorCount = 0

  if (DEBUG) {
    nativeEngineStats.attempts = 0
    nativeEngineStats.success = 0
    nativeEngineStats.notFound = 0
    nativeEngineStats.errors = 0
    nativeEngineStats.lastResult = ''
    nativeEngineStats.lastFormat = ''
  }
}

const isVideoReadyForNativeDetection = (video: HTMLVideoElement | null): boolean => {
  if (!video) {
    return false
  }

  return (
    video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA
    && video.videoWidth > 0
    && video.videoHeight > 0
  )
}

const stopNativeBarcodeDetectionLoop = (): void => {
  if (nativeDetectionLoopId != null) {
    cancelAnimationFrame(nativeDetectionLoopId)
    nativeDetectionLoopId = null
  }

  nativeDetectionInProgress = false
  debugLog('native detection stopped')
}

const stopNativeBarcodeDetection = (): void => {
  stopNativeBarcodeDetectionLoop()
  nativeBarcodeDetector = null
}

const runNativeDetectionTick = async (sessionId: number): Promise<void> => {
  if (
    nativeDetectionInProgress
    || !nativeBarcodeDetector
    || !videoRef.value
    || hasEmittedFromCamera
    || !isSessionScanning(sessionId)
    || activeCameraEngine.value !== 'native'
  ) {
    return
  }

  const now = performance.now()

  if (now - nativeLastDetectionTime < NATIVE_DETECTION_INTERVAL_MS) {
    return
  }

  nativeLastDetectionTime = now
  nativeDetectionInProgress = true
  const detectStartedAt = DEBUG ? performance.now() : 0

  try {
    if (DEBUG) {
      nativeEngineStats.attempts += 1
    }

    const barcodes = await nativeBarcodeDetector.detect(videoRef.value)

    if (!isSessionScanning(sessionId) || hasEmittedFromCamera) {
      return
    }

    const best = pickBestNativeBarcode(barcodes)

    if (!best?.rawValue?.trim()) {
      if (DEBUG) {
        nativeEngineStats.notFound += 1
      }

      return
    }

    if (DEBUG) {
      nativeEngineStats.success += 1
      nativeEngineStats.lastResult = best.rawValue
      nativeEngineStats.lastFormat = formatNativeBarcodeFormat(best.format)
      diagnostic.lastResult = best.rawValue
      diagnostic.lastFormat = formatNativeBarcodeFormat(best.format)
      syncDiagnosticPanel()

    }

    debugLog('native result', { rawValue: best.rawValue, format: best.format })
    void handleCameraScanResult(best.rawValue, sessionId)
  } catch (error) {
    if (DEBUG) {
      nativeEngineStats.errors += 1
    }

    nativeFatalErrorCount += 1
    debugLog('native detection error', error)

    if (
      nativeFatalErrorCount >= NATIVE_FATAL_ERROR_THRESHOLD
      && isSessionScanning(sessionId)
      && activeMediaStream
      && !sessionUsesZxingFallback
      && activeStatsTracker
    ) {
      const reason = error instanceof Error ? error.message : String(error)

      try {
        await switchToZxingFallback(sessionId, activeMediaStream, reason, activeStatsTracker)
      } catch (fallbackError) {
        debugLog('ZXing fallback after native errors failed', fallbackError)
      }
    }
  } finally {
    nativeDetectionInProgress = false
  }
}

const startNativeBarcodeDetection = (sessionId: number): void => {
  stopNativeBarcodeDetectionLoop()
  nativeFatalErrorCount = 0
  nativeLastDetectionTime = 0
  debugLog('native detection started')

  const loop = (): void => {
    if (
      !isSessionScanning(sessionId)
      || activeCameraEngine.value !== 'native'
      || hasEmittedFromCamera
    ) {
      nativeDetectionLoopId = null
      return
    }

    void runNativeDetectionTick(sessionId)
    nativeDetectionLoopId = requestAnimationFrame(loop)
  }

  nativeDetectionLoopId = requestAnimationFrame(loop)
}

const startZxingDecodeForSession = async (
  sessionId: number,
  stream: MediaStream,
  statsTracker: ReturnType<typeof createBarcodeDecodeStatsTracker>,
): Promise<void> => {
  if (!isSessionActive(sessionId) || !videoRef.value) {
    return
  }

  activeCameraEngine.value = 'zxing'

  barcodeReader = new BrowserMultiFormatReader(undefined, {
    delayBetweenScanAttempts: 100,
    delayBetweenScanSuccess: 250,
    tryPlayVideoTimeout: 8000,
  })

  debugLog('starting ZXing decode', {
    sessionId,
    readerOptions: {
      delayBetweenScanAttempts: 100,
      delayBetweenScanSuccess: 250,
      tryPlayVideoTimeout: 8000,
    },
    formats: 'BrowserMultiFormatReader default multi-format (EAN-13, EAN-8, UPC-A, UPC-E, Code 128, Code 39, ...)',
  })

  const startPromise = barcodeReader.decodeFromStream(
    stream,
    videoRef.value,
    createScanCallback(sessionId, statsTracker),
  )
  pendingScannerStart = startPromise

  scannerControls = await startPromise
  pendingScannerStart = null
}

const switchToZxingFallback = async (
  sessionId: number,
  stream: MediaStream,
  reason: string,
  statsTracker: ReturnType<typeof createBarcodeDecodeStatsTracker>,
): Promise<void> => {
  if (!isSessionScanning(sessionId) || sessionUsesZxingFallback) {
    return
  }

  sessionUsesZxingFallback = true
  fallbackUsed.value = true
  cameraEngineFallbackReason.value = reason
  stopNativeBarcodeDetection()
  debugLog('switching to ZXing fallback')
  debugLog('fallback reason', reason)

  await startZxingDecodeForSession(sessionId, stream, statsTracker)
}

const tryStartNativeEngine = async (sessionId: number): Promise<boolean> => {
  if (!isNativeBarcodeDetectorAvailable()) {
    debugLog('native BarcodeDetector unavailable')
    return false
  }

  if (!isVideoReadyForNativeDetection(videoRef.value) || !hasLiveMediaStreamTracks(activeMediaStream)) {
    debugLog('video not ready for native detection')
    return false
  }

  try {
    debugLog('native BarcodeDetector available')

    const created = await createNativeBarcodeDetector()

    if (!isSessionActive(sessionId) || !videoRef.value) {
      return false
    }

    nativeBarcodeDetector = created.detector
    activeCameraEngine.value = 'native'
    debugLog('native BarcodeDetector initialized', { formats: created.formatsUsed })
    startNativeBarcodeDetection(sessionId)

    return true
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error)
    cameraEngineFallbackReason.value = reason
    debugLog('native BarcodeDetector initialization failed', error)
    nativeBarcodeDetector = null

    return false
  }
}

const copyDiagnosticSummary = async () => {
  if (!DEBUG || !canCopyDiagnostic) {
    return
  }

  syncDiagnosticPanel()

  try {
    await navigator.clipboard.writeText(formatDiagnosticPanelForClipboard(diagnostic))
    diagnosticCopyNotice.value = 'Diagnostic copié.'
  } catch {
    diagnosticCopyNotice.value = 'Copie impossible sur cet appareil.'
  }
}

/** DEV TEMP — test ponctuel frame caméra → ZXing canvas (supprimable) */
const resetDiagnosticImageTestState = () => {
  diagnosticImageResult.value = null
  diagnosticImageFormat.value = null
  diagnosticImageError.value = null
  diagnosticImageStatus.value = 'testing'
}

const diagnosticImageStatusLabel = (): string => {
  switch (diagnosticImageStatus.value) {
    case 'testing':
      return 'TESTING'
    case 'success':
      return 'SUCCESS'
    case 'not-found':
      return 'NOT FOUND'
    case 'error':
      return 'ERROR'
    default:
      return '—'
  }
}

const testCurrentCameraFrame = async (): Promise<void> => {
  if (!DEBUG) {
    return
  }

  diagnosticImageTestRunning.value = true
  resetDiagnosticImageTestState()

  try {
    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo indisponible.')
    }

    if (!(video.srcObject instanceof MediaStream)) {
      throw new Error('Flux caméra indisponible.')
    }

    if (
      video.videoWidth <= 0
      || video.videoHeight <= 0
      || video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA
    ) {
      throw new Error('Frame vidéo non prête pour capture.')
    }

    console.info('[BarcodeScanner] IMAGE TEST START', {
      videoWidth: video.videoWidth,
      videoHeight: video.videoHeight,
      readyState: video.readyState,
      currentTime: video.currentTime,
    })

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')

    if (!context) {
      throw new Error('Impossible de créer le contexte canvas.')
    }

    context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight)

    diagnosticFrameUrl.value = canvas.toDataURL('image/jpeg', 0.85)
    diagnosticFrameWidth.value = canvas.width
    diagnosticFrameHeight.value = canvas.height
    diagnosticFrameReadyState.value = video.readyState
    diagnosticFrameCurrentTime.value = video.currentTime

    const track = video.srcObject.getVideoTracks()[0]
    const settings = track?.getSettings?.()

    diagnosticCaptureTrackWidth.value = settings?.width ?? 0
    diagnosticCaptureTrackHeight.value = settings?.height ?? 0
    diagnosticCaptureFacingMode.value = settings?.facingMode ?? ''
    diagnosticCaptureFrameRate.value = settings?.frameRate ?? 0

    console.info('[BarcodeScanner] IMAGE TEST FRAME CAPTURED', {
      width: canvas.width,
      height: canvas.height,
      trackSettings: settings,
    })

    const diagnosticReader = new BrowserMultiFormatReader()

    try {
      const result = diagnosticReader.decodeFromCanvas(canvas)
      const text = result.getText()
      const format = formatBarcodeFormatValue(result.getBarcodeFormat())

      diagnosticImageStatus.value = 'success'
      diagnosticImageResult.value = text
      diagnosticImageFormat.value = format || null
      diagnosticImageError.value = null

      console.info('[BarcodeScanner] IMAGE TEST RESULT', {
        text,
        format,
        width: canvas.width,
        height: canvas.height,
      })
    } catch (decodeError) {
      if (isTransientBarcodeScanError(decodeError)) {
        diagnosticImageStatus.value = 'not-found'
        diagnosticImageResult.value = null
        diagnosticImageFormat.value = null
        diagnosticImageError.value = 'Aucun code-barres détecté dans cette frame.'

        console.info('[BarcodeScanner] IMAGE TEST NOT FOUND', {
          width: canvas.width,
          height: canvas.height,
        })
      } else {
        diagnosticImageStatus.value = 'error'
        diagnosticImageResult.value = null
        diagnosticImageFormat.value = null
        diagnosticImageError.value = decodeError instanceof Error
          ? decodeError.message
          : String(decodeError)

        console.error('[BarcodeScanner] IMAGE TEST ERROR', decodeError)
      }
    }
  } catch (error) {
    diagnosticImageStatus.value = 'error'
    diagnosticImageResult.value = null
    diagnosticImageFormat.value = null
    diagnosticImageError.value = error instanceof Error ? error.message : String(error)

    console.error('[BarcodeScanner] IMAGE TEST ERROR', error)
  } finally {
    diagnosticImageTestRunning.value = false
  }
}

/** DEV TEMP — test multi-variantes sur une même frame caméra (supprimable) */
const testMultipleCameraVariants = async (): Promise<void> => {
  if (!DEBUG) {
    return
  }

  diagnosticImageTestRunning.value = true
  diagnosticVariantResults.value = []
  diagnosticMultiTestSummary.value = null
  diagnosticVariantPreviewsExpanded.value = true

  try {
    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo indisponible.')
    }

    if (video.videoWidth <= 0 || video.videoHeight <= 0) {
      throw new Error('Dimensions vidéo invalides.')
    }

    console.info('[BarcodeScanner] MULTI IMAGE TEST START', {
      videoWidth: video.videoWidth,
      videoHeight: video.videoHeight,
    })

    const sourceCanvas = captureSourceCanvasFromVideo(video)
    const variants = buildDiagnosticVariantCanvases(sourceCanvas)
    const results: DiagnosticVariantResult[] = variants.map((variant) => ({
      name: variant.name,
      label: variant.label,
      width: variant.canvas.width,
      height: variant.canvas.height,
      status: 'testing',
      result: null,
      format: null,
      error: null,
      previewUrl: variant.canvas.toDataURL('image/jpeg', 0.85),
    }))

    diagnosticVariantResults.value = results

    for (let index = 0; index < variants.length; index += 1) {
      const variant = variants[index]
      const resultEntry = results[index]

      console.info('[BarcodeScanner] MULTI IMAGE VARIANT', {
        name: variant.name,
        width: variant.canvas.width,
        height: variant.canvas.height,
      })

      const outcome = decodeDiagnosticVariantCanvas(variant.canvas)

      if (outcome.status === 'success') {
        resultEntry.status = 'success'
        resultEntry.result = outcome.result
        resultEntry.format = outcome.format || null
        resultEntry.error = null

        console.info('[BarcodeScanner] MULTI IMAGE RESULT', {
          name: variant.name,
          text: outcome.result,
          format: outcome.format,
        })
      } else if (outcome.status === 'not-found') {
        resultEntry.status = 'not-found'
        resultEntry.result = null
        resultEntry.format = null
        resultEntry.error = outcome.error

        console.info('[BarcodeScanner] MULTI IMAGE NOT FOUND', {
          name: variant.name,
          width: variant.canvas.width,
          height: variant.canvas.height,
        })
      } else {
        resultEntry.status = 'error'
        resultEntry.result = null
        resultEntry.format = null
        resultEntry.error = outcome.error

        console.error('[BarcodeScanner] MULTI IMAGE ERROR', {
          name: variant.name,
          error: outcome.error,
        })
      }

      diagnosticVariantResults.value = [...results]
    }

    diagnosticMultiTestSummary.value = buildDiagnosticMultiTestSummary(results)
  } catch (error) {
    console.error('[BarcodeScanner] MULTI IMAGE ERROR', error)
    diagnosticVariantResults.value = [{
      name: 'capture',
      label: 'Capture',
      width: 0,
      height: 0,
      status: 'error',
      result: null,
      format: null,
      error: error instanceof Error ? error.message : String(error),
      previewUrl: null,
    }]
    diagnosticMultiTestSummary.value = buildDiagnosticMultiTestSummary(diagnosticVariantResults.value)
  } finally {
    diagnosticImageTestRunning.value = false
  }
}

/** DEV TEMP — test BarcodeDetector natif ou polyfill sur la frame caméra actuelle (supprimable) */
const runBarcodeDetectorDiagnosticTest = async (
  engine: 'auto' | 'native' | 'polyfill',
): Promise<void> => {
  if (!DEBUG) {
    return
  }

  diagnosticImageTestRunning.value = true
  diagnosticBarcodeDetectorResult.value = null

  try {
    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo indisponible.')
    }

    if (video.videoWidth <= 0 || video.videoHeight <= 0) {
      throw new Error('Dimensions vidéo invalides.')
    }

    if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
      throw new Error('Frame vidéo non prête pour détection.')
    }

    const { runBarcodeDetectorDiagnostic } = await import('@/utils/barcodeDetectorDiagnostics')
    diagnosticBarcodeDetectorResult.value = await runBarcodeDetectorDiagnostic(video, engine)
  } catch (error) {
    diagnosticBarcodeDetectorResult.value = {
      supported: false,
      native: false,
      polyfill: false,
      status: 'ERROR',
      engineLabel: '—',
      supportedFormats: [],
      videoWidth: videoRef.value?.videoWidth,
      videoHeight: videoRef.value?.videoHeight,
      videoReadyState: videoRef.value?.readyState,
      videoPaused: videoRef.value?.paused,
      error: error instanceof Error ? error.message : String(error),
    }

    console.error('[BarcodeDetectorDiagnostic] ERROR', error)
  } finally {
    diagnosticImageTestRunning.value = false
  }
}

const testBarcodeDetector = async (): Promise<void> => {
  await runBarcodeDetectorDiagnosticTest('auto')
}

const testBarcodeDetectorPolyfill = async (): Promise<void> => {
  await runBarcodeDetectorDiagnosticTest('polyfill')
}

const stopDecodeDiagnostics = () => {
  stopDecodeStats?.()
  stopDecodeStats = null
  stopFrameFlowMonitor?.()
  stopFrameFlowMonitor = null
  activeStatsTracker = null

  if (DEBUG) {
    diagnosticFrameUrl.value = null
    diagnosticImageStatus.value = 'idle'
    diagnosticImageResult.value = null
    diagnosticImageFormat.value = null
    diagnosticImageError.value = null
    diagnosticImageTestRunning.value = false
    diagnosticVariantResults.value = []
    diagnosticMultiTestSummary.value = null
    diagnosticVariantPreviewsExpanded.value = false
    diagnosticBarcodeDetectorResult.value = null
  }
}

const startDecodeDiagnostics = (sessionId: number, video: HTMLVideoElement, stream: MediaStream | null) => {
  stopDecodeDiagnostics()

  const isDiagnosticsActive = () => isSessionScanning(sessionId)

  stopFrameFlowMonitor = startVideoFrameFlowMonitor(video, isDiagnosticsActive, () => {
    if (DEBUG) {
      diagnostic.frameCount += 1
    }
  })

  const statsTracker = createBarcodeDecodeStatsTracker(
    (stats) => {
      if (DEBUG) {
        syncDiagnosticPanelFromStats(diagnostic, stats)
        syncDiagnosticPanel()
      }

      debugLog('[DIAG] stats', stats)
    },
    isDiagnosticsActive,
  )

  stopDecodeStats = () => {
    statsTracker.stop()
  }

  activeStatsTracker = statsTracker

  if (DEBUG) {
    syncDiagnosticPanel()
    debugLog('[DIAG] video ready')
  }

  return statsTracker
}

const refocusScanInput = () => {
  if (!props.autofocus || props.disabled || cameraOpen.value) {
    return
  }

  nextTick(() => {
    scanInputRef.value?.focus()
    scanInputRef.value?.select()
  })
}

const emitBarcode = (rawBarcode: string) => {
  const barcode = normalizeBarcode(rawBarcode)

  if (!isValidBarcode(barcode)) {
    capabilityNotice.value = 'Code-barres invalide. Utilisez uniquement des caractères alphanumériques.'
    debugLog('invalid barcode', rawBarcode)
    return
  }

  const now = Date.now()
  const previousHardwareEmit = lastHardwareEmit
  const elapsedSincePreviousMs = previousHardwareEmit != null ? now - previousHardwareEmit.at : null
  const isDuplicateWithinWindow = (
    previousHardwareEmit?.barcode === barcode
    && elapsedSincePreviousMs != null
    && elapsedSincePreviousMs < DUPLICATE_EVENT_WINDOW_MS
  )

  if (isDuplicateWithinWindow) {
    refocusScanInput()
    return
  }

  lastHardwareEmit = { barcode, at: now }
  capabilityNotice.value = ''
  debugLog('emitting barcode', { value: barcode })

  emit('barcode-detected', barcode)
  refocusScanInput()
}

const submitHardwareScan = () => {
  emitBarcode(scanBuffer.value)
  scanBuffer.value = ''
}

const emitSearchRequest = () => {
  if (props.notFoundBarcode) {
    emit('search-request', props.notFoundBarcode)
  }
}

const emitDismissNotFound = () => {
  emit('dismiss-not-found')
}

const getVideoElementStream = (): MediaStream | null => {
  const stream = videoRef.value?.srcObject

  return stream instanceof MediaStream ? stream : null
}

const captureActiveMediaStream = (): MediaStream | null => {
  return activeMediaStream ?? getVideoElementStream()
}

const invalidateScanSession = (): number => {
  scanSessionId += 1
  return scanSessionId
}

const beginScanSession = (): number => {
  scanSessionId += 1
  return scanSessionId
}

const isSessionActive = (sessionId: number): boolean => {
  return isComponentMounted && sessionId === scanSessionId && cameraOpen.value
}

const isSessionScanning = (sessionId: number): boolean => {
  return (
    isSessionActive(sessionId)
    && scannerPhase.value !== 'idle'
    && scannerPhase.value !== 'stopping'
  )
}

const cleanupCameraResources = (options?: { invalidateSession?: boolean }): Promise<void> => {
  if (cleanupCameraPromise) {
    return cleanupCameraPromise
  }

  cleanupCameraPromise = (async () => {
    if (options?.invalidateSession !== false) {
      invalidateScanSession()
    }

    scannerPhase.value = 'stopping'
    debugLog('cleanup started')

    const controls = scannerControls
    const pendingStart = pendingScannerStart
    const streamToStop = captureActiveMediaStream()
    const video = videoRef.value

    scannerControls = null
    pendingScannerStart = null
    activeMediaStream = null
    barcodeReader = null
    activeStatsTracker = null

    stopNativeBarcodeDetection()

    try {
      controls?.stop()
    } catch (error) {
      debugLog('controls.stop ignored', error)
    }

    if (pendingStart) {
      pendingStart
        .then((pendingControls) => {
          try {
            pendingControls.stop()
          } catch (error) {
            debugLog('pending controls.stop ignored', error)
          }
        })
        .catch((error) => {
          debugLog('pending scanner start ignored', error)
        })
    }

    stopMediaStreamTracks(streamToStop)
    stopMediaStreamTracks(getVideoElementStream())
    releaseAllBarcodeCameraStreams()

    if (video) {
      try {
        video.pause()
      } catch (error) {
        debugLog('video.pause ignored', error)
      }

      video.srcObject = null
    }

    debugLog('cleanup completed', {
      liveTracksRemaining: hasLiveMediaStreamTracks(getVideoElementStream()),
    })
  })()
    .catch((error) => {
      debugLog('cleanup error ignored', error)
    })
    .finally(() => {
      cameraPreviewActive.value = false
      scannerPhase.value = 'idle'
      cleanupCameraPromise = null
    })

  return cleanupCameraPromise
}

const closeCamera = async (): Promise<void> => {
  await cleanupCameraResources()

  stopDecodeDiagnostics()

  cameraOpen.value = false
  cameraError.value = ''
  cameraStatus.value = 'Placez le code-barres devant la caméra'
  refocusScanInput()
}

const handleCameraScanResult = async (rawBarcode: string, sessionId: number): Promise<void> => {
  const normalizedBarcode = normalizeBarcode(rawBarcode)

  if (hasEmittedFromCamera || !isSessionScanning(sessionId) || !normalizedBarcode) {
    debugLog('scan result ignored', { sessionId, rawBarcode })
    return
  }

  hasEmittedFromCamera = true
  debugLog('result detected', normalizedBarcode)

  // L'émission doit précéder toute fermeture/cleanup susceptible de démonter le modal
  // avant que le parent reçoive barcode-detected.
  debugLog('emitting barcode', { value: normalizedBarcode })
  emitBarcode(normalizedBarcode)

  await cleanupCameraResources()

  stopDecodeDiagnostics()
  cameraOpen.value = false
  cameraError.value = ''
  cameraStatus.value = 'Placez le code-barres devant la caméra'
}

const createScanCallback = (
  sessionId: number,
  statsTracker: ReturnType<typeof createBarcodeDecodeStatsTracker>,
) => {
  return (
    result: { getText: () => string; getBarcodeFormat?: () => unknown } | undefined,
    error: unknown,
    _controls: IScannerControls,
  ) => {
    if (!isComponentMounted || hasEmittedFromCamera || !isSessionScanning(sessionId)) {
      statsTracker.recordIgnored()

      if (DEBUG && statsTracker.snapshot().ignoredCallbackCount <= 3) {
        debugLog('callback ignored', {
          sessionId,
          scanSessionId,
          phase: scannerPhase.value,
          cameraOpen: cameraOpen.value,
          hasEmittedFromCamera,
        })
      }

      return
    }

    statsTracker.recordCallback(error, Boolean(result))

    if (DEBUG) {
      syncDiagnosticPanelFromStats(diagnostic, statsTracker.snapshot())
      syncDiagnosticPanel()
    }

    if (result) {
      if (DEBUG) {
        diagnostic.lastResult = result.getText()
        diagnostic.lastFormat = formatBarcodeFormatValue(
          typeof result.getBarcodeFormat === 'function' ? result.getBarcodeFormat() : undefined,
        )
      }

      debugLog('[DIAG] ZXing result', {
        text: result.getText(),
        format: typeof result.getBarcodeFormat === 'function' ? result.getBarcodeFormat() : undefined,
      })
      debugLog('decode callback result', result.getText())

      void handleCameraScanResult(result.getText(), sessionId)
      return
    }

    if (error && isFatalBarcodeScanError(error)) {
      if (DEBUG) {
        diagnostic.lastError = error instanceof Error ? error.message : String(error)
        syncDiagnosticPanelFromStats(diagnostic, statsTracker.snapshot())
        syncDiagnosticPanel()
      }

      debugLog('fatal camera scan error', error)
      cameraError.value = 'Erreur lors de la lecture du code-barres.'
      cameraStatus.value = 'Scanner indisponible'
      void cleanupCameraResources().then(() => {
        stopDecodeDiagnostics()
        cameraPreviewActive.value = false
      })
      return
    }

    if (isSessionScanning(sessionId)) {
      cameraStatus.value = 'Placez le code-barres devant la caméra'
    }
  }
}

const openCamera = async (): Promise<void> => {
  if (!isDev || !props.showCameraButton) {
    return
  }

  if (props.disabled || props.processing || cameraOpen.value || scannerPhase.value !== 'idle') {
    return
  }

  debugLog('scanner button clicked')

  capabilityNotice.value = ''
  cameraError.value = ''

  const availability = getBarcodeCameraAvailability()
  debugLog('camera capability detected', availability)

  if (availability.state !== 'ready') {
    capabilityNotice.value = availability.message
    refocusScanInput()
    return
  }

  await cleanupCameraResources()
  stopDecodeDiagnostics()

  if (DEBUG) {
    Object.assign(diagnostic, createInitialDiagnosticPanelState())
  }

  const currentSessionId = beginScanSession()
  hasEmittedFromCamera = false
  resetCameraEngineState()
  scannerPhase.value = 'starting'
  if (DEBUG) {
    diagnostic.cameraState = scannerPhase.value
  }
  cameraOpen.value = true
  cameraPreviewActive.value = true
  cameraStatus.value = 'Initialisation de la caméra...'

  await nextTick()

  if (!isSessionActive(currentSessionId) || !videoRef.value) {
    await cleanupCameraResources()

    if (cameraOpen.value && !videoRef.value) {
      cameraPreviewActive.value = false
      cameraError.value = 'Impossible d’ouvrir le scanner caméra.'
      cameraStatus.value = 'Scanner indisponible'
    }

    return
  }

  try {
    debugLog('getUserMedia started')

    if (!isSessionActive(currentSessionId) || !videoRef.value) {
      await cleanupCameraResources()
      return
    }

    const cameraConstraints = getBarcodeCameraConstraints()
    debugLog('starting camera stream', { constraints: cameraConstraints })

    const stream = await navigator.mediaDevices.getUserMedia(cameraConstraints)

    if (!isSessionActive(currentSessionId) || !videoRef.value) {
      stopMediaStreamTracks(stream)
      await cleanupCameraResources()
      return
    }

    activeMediaStream = stream
    videoRef.value.srcObject = stream

    try {
      await videoRef.value.play()
    } catch (error) {
      debugLog('video.play ignored', error)
    }

    await applyPreferredCameraTrackSettings(activeMediaStream)

    const videoReady = await waitForVideoFrameReady(videoRef.value)

    if (!isSessionActive(currentSessionId) || !videoRef.value) {
      await cleanupCameraResources()
      return
    }

    logVideoDiagnostics(videoRef.value, activeMediaStream, 'video ready before decode')

    if (DEBUG) {
      syncDiagnosticPanel()
    }

    if (!videoReady) {
      debugLog('video frame not ready before decode')
    }

    const statsTracker = startDecodeDiagnostics(currentSessionId, videoRef.value, activeMediaStream)

    const nativeStarted = await tryStartNativeEngine(currentSessionId)

    if (!nativeStarted) {
      sessionUsesZxingFallback = true
      fallbackUsed.value = true

      if (!cameraEngineFallbackReason.value) {
        cameraEngineFallbackReason.value = isNativeBarcodeDetectorAvailable()
          ? 'Initialisation BarcodeDetector impossible'
          : 'BarcodeDetector non disponible'
      }

      debugLog('switching to ZXing fallback')
      debugLog('fallback reason', cameraEngineFallbackReason.value)
      await startZxingDecodeForSession(currentSessionId, stream, statsTracker)
    }

    if (!isSessionActive(currentSessionId)) {
      scannerControls?.stop()
      scannerControls = null
      stopNativeBarcodeDetection()
      stopDecodeDiagnostics()
      await cleanupCameraResources({ invalidateSession: false })
      return
    }

    debugLog('camera started', {
      sessionId: currentSessionId,
      engine: activeCameraEngine.value,
      videoWidth: videoRef.value?.videoWidth ?? 0,
      videoHeight: videoRef.value?.videoHeight ?? 0,
      liveTracks: activeMediaStream?.getVideoTracks().length ?? 0,
      decodeStats: statsTracker.snapshot(),
    })

    scannerPhase.value = 'scanning'
    if (DEBUG) {
      syncDiagnosticPanel()
    }
    cameraStatus.value = 'Placez le code-barres devant la caméra'
  } catch (error) {
    const shouldShowError = cameraOpen.value && isSessionActive(currentSessionId)

    pendingScannerStart = null
    stopDecodeDiagnostics()
    await cleanupCameraResources({ invalidateSession: false })

    if (!shouldShowError) {
      cameraOpen.value = false
      cameraError.value = ''
      return
    }

    cameraPreviewActive.value = false
    cameraError.value = getBarcodeCameraErrorMessage(error)
    cameraStatus.value = 'Scanner indisponible'
    debugLog('camera error', error)
  }
}

watch(
  () => props.processing,
  (isProcessing, wasProcessing) => {
    if (wasProcessing && !isProcessing) {
      refocusScanInput()
    }
  },
)

watch(
  () => props.disabled,
  (isDisabled) => {
    if (isDisabled) {
      void closeCamera()
      return
    }

    refocusScanInput()
  },
)

watch(cameraOpen, (isOpen, _oldValue, onCleanup) => {
  if (!isOpen) {
    return
  }

  const handleEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
      event.preventDefault()
      void closeCamera()
    }
  }

  document.addEventListener('keydown', handleEscape)
  onCleanup(() => {
    document.removeEventListener('keydown', handleEscape)
  })
})

onBeforeUnmount(() => {
  isComponentMounted = false

  void cleanupCameraResources()
})

onMounted(() => {
  debugLog('component mounted', getBarcodeCameraAvailability())
  refocusScanInput()
})

const focus = () => {
  refocusScanInput()
}

defineExpose({
  focus,
})
</script>
