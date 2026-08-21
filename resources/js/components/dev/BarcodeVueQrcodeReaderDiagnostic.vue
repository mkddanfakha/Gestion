<template>
  <div class="barcode-vue-qrcode-reader-diagnostic barcode-vue-qrcode-reader-diagnostic--embedded">
    <DiagnosticPanelContent
      ref="panelRef"
      :attempt-count="attemptCount"
      :camera-active="cameraActive"
      :camera-error-count="cameraErrorCount"
      :camera-facing-mode="cameraFacingMode"
      :camera-frame-rate-label="cameraFrameRateLabel"
      :camera-init-status="cameraInitStatus"
      :camera-resolution-label="cameraResolutionLabel"
      :camera-track-state="cameraTrackState"
      :detection-count="detectionCount"
      :diagnostic-conclusion="diagnosticConclusion"
      :field-validation-result="fieldValidationResult"
      :get-user-media-test="getUserMediaTest"
      :last-analysis="lastAnalysis"
      :last-detection-at="lastDetectionAt"
      :last-error-details="lastErrorDetails"
      :last-event="lastEvent"
      :last-library-error="lastLibraryError"
      :library-error-count="libraryErrorCount"
      :operational-summary="operationalSummary"
      :page-camera-busy="pageCameraBusy"
      :page-camera-busy-message="pageCameraBusyMessage"
      :raw-video-test="rawVideoTest"
      :raw-value-block="rawValueBlock"
      :secure-context="secureContextInfo"
      :status-label="statusLabel"
      :stream-active="streamActive"
      :test-running="testRunning"
      :video-probe="videoProbe"
      :vue-qrcode-reader-version="vueQrcodeReaderVersion"
      @clear="clearDiagnosticData"
      @restart="restartTest"
      @run-get-user-media-test="runGetUserMediaTest"
      @run-raw-video-test="runRawVideoTest"
      @stop="stopTest"
      @stop-page-cameras="stopPageTestCameras"
      @validate-field="runFieldValidationPreview"
    >
      <QrcodeStream
        v-if="streamActive"
        :constraints="cameraConstraints"
        :formats="barcodeFormats"
        :paused="streamPaused"
        @detect="handleDetect"
        @camera-on="handleCameraOn"
        @camera-off="handleCameraOff"
        @error="handleError"
      />
      <p v-else class="barcode-vue-qrcode-reader-diagnostic__placeholder mb-0">
        Caméra arrêtée.
      </p>
    </DiagnosticPanelContent>
  </div>
</template>

<script setup lang="ts">
import DiagnosticPanelContent from '@/components/dev/BarcodeVueQrcodeReaderDiagnosticPanel.vue'
import {
  QrcodeStream,
  type BarcodeFormat,
  type DetectedBarcode,
  type EmittedError,
} from 'vue-qrcode-reader'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  buildOperationalSummary,
  getSecureContextInfo,
  hasLiveTracks,
  logVueQrcodeReaderCameraError,
  probeVideoInContainer,
  runIndependentGetUserMediaTest,
  serializeUnknownError,
  stopMediaStreams,
  VUE_QRCODE_READER_VERSION,
  type DiagnosticErrorDetails,
  type IndependentMediaTestResult,
  type RawVideoTestResult,
  type SecureContextInfo,
  type VideoProbeInfo,
} from '@/utils/barcodeReaderTestDiagnostics'
import {
  analyzeRawBarcodeValue,
  buildDetectionHistoryEntry,
  getDiagnosticConclusionText,
  logVueQrcodeReaderDiagnostic,
  resolveDiagnosticScenario,
  type BarcodeDetectionHistoryEntry,
  type BarcodeRawAnalysis,
} from '@/utils/barcodeVueQrcodeReaderDiagnostic'
import { isValidBarcode, normalizeBarcode } from '@/utils/barcodeNormalizer'

type DiagnosticStatus = 'READY' | 'SCANNING' | 'DETECTED' | 'ERROR'

withDefaults(
  defineProps<{
    embedded?: boolean
    openCount?: number
  }>(),
  {
    embedded: true,
    openCount: 1,
  },
)

const vueQrcodeReaderVersion = VUE_QRCODE_READER_VERSION
const barcodeFormats: BarcodeFormat[] = [
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
  'code_128',
  'code_39',
]

const cameraConstraints: MediaTrackConstraints = {
  facingMode: { ideal: 'environment' },
  width: { ideal: 1280, min: 640 },
  height: { ideal: 720, min: 480 },
}

const panelRef = ref<InstanceType<typeof DiagnosticPanelContent> | null>(null)
const secureContextInfo = ref<SecureContextInfo>(getSecureContextInfo())
const isActive = ref(true)
const streamActive = ref(true)
const streamPaused = ref(false)
const testRunning = ref(true)
const status = ref<DiagnosticStatus>('READY')
const cameraInitStatus = ref<'SUCCESS' | 'ERROR' | 'WAITING'>('WAITING')
const attemptCount = ref(0)
const detectionCount = ref(0)
const cameraErrorCount = ref(0)
const libraryErrorCount = ref(0)
const lastAnalysis = ref<BarcodeRawAnalysis | null>(null)
const lastDetectionAt = ref<string | null>(null)
const lastLibraryError = ref<string | null>(null)
const lastErrorDetails = ref<DiagnosticErrorDetails | null>(null)
const lastEvent = ref('Initialisation...')
const fieldValidationResult = ref<string | null>(null)
const cameraActive = ref(false)
const cameraFacingMode = ref('')
const cameraWidth = ref(0)
const cameraHeight = ref(0)
const cameraFrameRate = ref(0)
const cameraTrackState = ref('')
const pageCameraBusyMessage = ref<string | null>(null)
const getUserMediaTest = ref<IndependentMediaTestResult | null>(null)
const rawVideoTest = ref<RawVideoTestResult | null>(null)
const videoProbe = ref<VideoProbeInfo>(probeVideoInContainer(null))
const detectionHistory = ref<BarcodeDetectionHistoryEntry[]>([])
const pageTestStreams = ref<MediaStream[]>([])
let historySequence = 0
let videoProbeIntervalId: number | null = null
let rawVideoElement: HTMLVideoElement | null = null

const pageCameraBusy = computed(() => {
  return (streamActive.value && testRunning.value)
    || hasLiveTracks(pageTestStreams.value)
})

const statusLabel = computed(() => {
  if (!testRunning.value) {
    return 'stopped'
  }

  if (status.value === 'DETECTED') {
    return 'detected'
  }

  if (status.value === 'ERROR') {
    return 'error'
  }

  if (status.value === 'SCANNING') {
    return 'running'
  }

  return 'ready'
})

const cameraResolutionLabel = computed(() => {
  if (!cameraWidth.value || !cameraHeight.value) {
    return '—'
  }

  return `${cameraWidth.value} × ${cameraHeight.value}`
})

const cameraFrameRateLabel = computed(() => {
  if (!cameraFrameRate.value) {
    return '—'
  }

  return `${cameraFrameRate.value} fps`
})

const rawValueBlock = computed(() => {
  if (!lastAnalysis.value) {
    return ''
  }

  return `Valeur brute :\n${lastAnalysis.value.rawValue}`
})

const diagnosticConclusion = computed(() => getDiagnosticConclusionText(
  resolveDiagnosticScenario(detectionCount.value, lastAnalysis.value),
  detectionCount.value,
  lastAnalysis.value,
))

const operationalSummary = computed(() => buildOperationalSummary({
  cameraInitStatus: cameraInitStatus.value,
  cameraActive: cameraActive.value,
  videoProbe: videoProbe.value,
  statusLabel: statusLabel.value,
  detectionCount: detectionCount.value,
  getUserMediaTest: getUserMediaTest.value,
  lastErrorDetails: lastErrorDetails.value,
}))

function refreshVideoProbe(): void {
  videoProbe.value = probeVideoInContainer(panelRef.value?.viewportRef ?? null)
}

function startVideoProbeMonitor(): void {
  stopVideoProbeMonitor()
  refreshVideoProbe()

  videoProbeIntervalId = window.setInterval(() => {
    refreshVideoProbe()
  }, 1500)
}

function stopVideoProbeMonitor(): void {
  if (videoProbeIntervalId !== null) {
    window.clearInterval(videoProbeIntervalId)
    videoProbeIntervalId = null
  }
}

function setPageCameraBusyMessageIfNeeded(): boolean {
  if ((streamActive.value && testRunning.value) || hasLiveTracks(pageTestStreams.value)) {
    pageCameraBusyMessage.value = 'Une caméra de test est déjà active. Arrêtez-la avant de lancer ce diagnostic.'
    return true
  }

  pageCameraBusyMessage.value = null
  return false
}

function pickDetectedBarcode(detectedCodes: DetectedBarcode[]): DetectedBarcode | null {
  if (detectedCodes.length === 0) {
    return null
  }

  const retailFormats = new Set(['ean_13', 'ean_8', 'upc_a', 'upc_e'])
  const retailMatch = detectedCodes.find((code) => retailFormats.has(code.format))

  return retailMatch ?? detectedCodes[0] ?? null
}

function pushHistoryEntry(analysis: BarcodeRawAnalysis): void {
  historySequence += 1

  detectionHistory.value = [
    buildDetectionHistoryEntry(historySequence, new Date().toLocaleTimeString(), analysis),
    ...detectionHistory.value,
  ].slice(0, 10)
}

function handleDetect(detectedCodes: DetectedBarcode[]): void {
  if (!isActive.value || !testRunning.value) {
    return
  }

  attemptCount.value += 1
  lastEvent.value = 'detection received'

  if (detectedCodes.length === 0) {
    logVueQrcodeReaderDiagnostic('detection received (empty)')
    return
  }

  const detected = pickDetectedBarcode(detectedCodes)

  if (!detected?.rawValue) {
    logVueQrcodeReaderDiagnostic('detection received (no rawValue)')
    return
  }

  logVueQrcodeReaderDiagnostic('detection received')

  const analysis = analyzeRawBarcodeValue(
    detected.rawValue,
    detected.format ?? 'inconnu',
  )

  detectionCount.value += 1
  lastAnalysis.value = analysis
  lastDetectionAt.value = new Date().toLocaleTimeString()
  status.value = 'DETECTED'
  pushHistoryEntry(analysis)
  refreshVideoProbe()

  logVueQrcodeReaderDiagnostic(`raw value: ${analysis.json}`)
  logVueQrcodeReaderDiagnostic(`raw length: ${analysis.length}`)
}

function handleCameraOn(capabilities: Partial<MediaTrackCapabilities>): void {
  if (!isActive.value) {
    return
  }

  cameraActive.value = true
  cameraInitStatus.value = 'SUCCESS'
  cameraTrackState.value = 'live'
  cameraFacingMode.value = typeof capabilities.facingMode === 'string'
    ? capabilities.facingMode
    : Array.isArray(capabilities.facingMode)
      ? capabilities.facingMode.join(', ')
      : ''
  cameraWidth.value = typeof capabilities.width === 'number' ? capabilities.width : 0
  cameraHeight.value = typeof capabilities.height === 'number' ? capabilities.height : 0
  cameraFrameRate.value = typeof capabilities.frameRate === 'number' ? capabilities.frameRate : 0
  status.value = 'SCANNING'
  lastLibraryError.value = null
  lastErrorDetails.value = null
  lastEvent.value = 'camera started'

  void nextTick(() => {
    refreshVideoProbe()
  })

  logVueQrcodeReaderDiagnostic('camera started', {
    facingMode: cameraFacingMode.value,
    width: cameraWidth.value,
    height: cameraHeight.value,
    frameRate: cameraFrameRate.value,
  })
}

function handleCameraOff(): void {
  cameraActive.value = false
  cameraTrackState.value = 'ended'
  lastEvent.value = 'camera stopped'
  refreshVideoProbe()

  logVueQrcodeReaderDiagnostic('camera stopped')
}

function handleError(error: EmittedError): void {
  if (!isActive.value) {
    return
  }

  libraryErrorCount.value += 1
  cameraErrorCount.value += 1
  cameraInitStatus.value = 'ERROR'
  lastErrorDetails.value = serializeUnknownError(error)
  lastLibraryError.value = lastErrorDetails.value.message !== '—'
    ? `${lastErrorDetails.value.name}: ${lastErrorDetails.value.message}`
    : String(error)
  status.value = 'ERROR'
  lastEvent.value = 'library error'

  logVueQrcodeReaderCameraError(error)
  refreshVideoProbe()
}

function runFieldValidationPreview(): void {
  if (!lastAnalysis.value) {
    fieldValidationResult.value = 'Aucune détection à tester.'
    return
  }

  const normalized = normalizeBarcode(lastAnalysis.value.rawValue)
  const valid = isValidBarcode(lastAnalysis.value.rawValue)

  fieldValidationResult.value = valid
    ? `Validation champ (lecture seule) : PASS — "${normalized}" serait accepté par isValidBarcode().`
    : `Validation champ (lecture seule) : FAIL — "${normalized}" serait rejeté par isValidBarcode().`
}

function clearDiagnosticData(): void {
  attemptCount.value = 0
  detectionCount.value = 0
  lastAnalysis.value = null
  lastDetectionAt.value = null
  lastLibraryError.value = null
  lastErrorDetails.value = null
  fieldValidationResult.value = null
  libraryErrorCount.value = 0
  cameraErrorCount.value = 0
  detectionHistory.value = []
  getUserMediaTest.value = null
  rawVideoTest.value = null
  historySequence = 0
  status.value = cameraActive.value && testRunning.value ? 'SCANNING' : 'READY'
  lastEvent.value = 'diagnostic cleared'
  pageCameraBusyMessage.value = null

  logVueQrcodeReaderDiagnostic('diagnostic cleared')
}

function cleanupRawVideoElement(): void {
  if (rawVideoElement) {
    rawVideoElement.srcObject = null
    rawVideoElement.remove()
    rawVideoElement = null
  }

  panelRef.value?.rawVideoMountRef?.replaceChildren()
}

function stopIndependentStreams(): void {
  stopMediaStreams(pageTestStreams.value)
  pageTestStreams.value = []
  cleanupRawVideoElement()
}

function shutdownStream(): void {
  isActive.value = false
  streamPaused.value = true
  streamActive.value = false
  testRunning.value = false
  cameraActive.value = false
  cameraTrackState.value = 'stopped'
  lastEvent.value = 'vue-qrcode-reader test stopped'
  stopVideoProbeMonitor()
  refreshVideoProbe()
}

function stopPageTestCameras(): void {
  shutdownStream()
  stopIndependentStreams()
  pageCameraBusyMessage.value = null
  lastEvent.value = 'page test cameras stopped'

  logVueQrcodeReaderDiagnostic('page test cameras stopped')
}

function stopTest(): void {
  stopPageTestCameras()
  logVueQrcodeReaderDiagnostic('test stopped')
}

function restartTest(): void {
  stopIndependentStreams()
  isActive.value = true
  streamPaused.value = false
  streamActive.value = true
  testRunning.value = true
  cameraInitStatus.value = 'WAITING'
  status.value = 'READY'
  lastEvent.value = 'test restarted'
  pageCameraBusyMessage.value = null
  startVideoProbeMonitor()

  logVueQrcodeReaderDiagnostic('test restarted')
}

async function runGetUserMediaTest(): Promise<void> {
  if (setPageCameraBusyMessageIfNeeded()) {
    return
  }

  const { stream, result } = await runIndependentGetUserMediaTest()
  getUserMediaTest.value = result

  if (stream) {
    pageTestStreams.value.push(stream)
  }

  lastEvent.value = result.message
}

async function runRawVideoTest(): Promise<void> {
  if (setPageCameraBusyMessageIfNeeded()) {
    return
  }

  cleanupRawVideoElement()

  const { stream, result } = await runIndependentGetUserMediaTest()

  if (!stream) {
    rawVideoTest.value = {
      status: result.status === 'ERROR' ? 'ERROR' : 'BLOCKED',
      message: result.message,
      videoWidth: '—',
      videoHeight: '—',
      readyState: '—',
      trackReadyState: '—',
      facingMode: '—',
      error: result.error,
    }
    return
  }

  pageTestStreams.value.push(stream)

  await nextTick()

  const mount = panelRef.value?.rawVideoMountRef

  if (!mount) {
    rawVideoTest.value = {
      status: 'ERROR',
      message: 'Conteneur vidéo brut indisponible.',
      videoWidth: '—',
      videoHeight: '—',
      readyState: '—',
      trackReadyState: '—',
      facingMode: '—',
      error: serializeUnknownError(new Error('Conteneur vidéo brut indisponible.')),
    }
    return
  }

  rawVideoElement = document.createElement('video')
  rawVideoElement.className = 'barcode-vue-qrcode-reader-diagnostic__raw-video'
  rawVideoElement.playsInline = true
  rawVideoElement.muted = true
  rawVideoElement.autoplay = true
  rawVideoElement.srcObject = stream
  mount.appendChild(rawVideoElement)

  try {
    await rawVideoElement.play()
  } catch (error) {
    logVueQrcodeReaderCameraError(error)
  }

  await new Promise((resolve) => window.setTimeout(resolve, 500))

  const videoTrack = stream.getVideoTracks()[0]
  const settings = videoTrack?.getSettings?.()

  rawVideoTest.value = {
    status: 'SUCCESS',
    message: 'Vidéo brute SUCCESS',
    videoWidth: String(rawVideoElement.videoWidth || settings?.width || '—'),
    videoHeight: String(rawVideoElement.videoHeight || settings?.height || '—'),
    readyState: String(rawVideoElement.readyState),
    trackReadyState: String(videoTrack?.readyState || '—'),
    facingMode: String(settings?.facingMode || '—'),
    error: null,
  }

  lastEvent.value = 'raw video test success'
}

onMounted(() => {
  secureContextInfo.value = getSecureContextInfo()
  startVideoProbeMonitor()
  logVueQrcodeReaderDiagnostic('opened', {
    version: vueQrcodeReaderVersion,
    secureContext: secureContextInfo.value,
  })
})

onBeforeUnmount(() => {
  stopPageTestCameras()
})

watch([streamActive, testRunning], () => {
  if (!streamActive.value || !testRunning.value) {
    refreshVideoProbe()
  }
})
</script>
