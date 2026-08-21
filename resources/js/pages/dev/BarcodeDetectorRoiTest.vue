<script setup lang="ts">
import {
  buildComparisonTableRows,
  buildRoiConclusion,
  buildRoiDiagnosticClipboard,
  buildVariantComparisonEntries,
  computeAverageDetectionMs,
  computeErrorRate,
  computeNotFoundRate,
  computeOverlayRectPercent,
  computeSuccessRate,
  createEmptyDetectionStats,
  createInitialVariantAggregates,
  createNativeBarcodeDetector,
  DETECTION_INTERVAL_MS,
  FIXED_CAMERA_CONSTRAINTS,
  formatDataSufficient,
  formatDurationMs,
  formatNativeBarcodeFormat,
  getEnvironmentDiagnostics,
  getVariantDefinition,
  getVideoOrientationLabel,
  hasSufficientTestData,
  MAX_SUCCESS_HISTORY,
  pickBestNativeBarcode,
  readActualTrackDetails,
  readCameraTrackDiagnostics,
  readTrackCapabilitiesSummary,
  REFERENCE_EAN_FORMAT,
  REFERENCE_EAN_VALUE,
  renderRoiToCanvas,
  ROI_VARIANTS,
  SMALL_EAN_TEST_LABEL,
  STANDARD_EAN_TEST_LABEL,
  REQUESTED_HEIGHT,
  REQUESTED_WIDTH,
  type BarcodeDetectorLike,
  type CodeTestCategory,
  type DetectionStats,
  type RoiVariantId,
  type SuccessHistoryEntry,
  type VariantCodeAggregate,
} from '@/utils/barcodeDetectorRoiTest'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

const videoRef = ref<HTMLVideoElement | null>(null)
const roiCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const supportedFormats = ref<string[]>([])
const activeVariantId = ref<RoiVariantId>('full')
const activeTestCategory = ref<CodeTestCategory>('small-ean')
const cameraState = ref<'idle' | 'starting' | 'active' | 'stopping' | 'error'>('idle')
const detectionLoopState = ref<'stopped' | 'running'>('stopped')
const cameraError = ref<{ name: string; message: string; constraint: string } | null>(null)
const copyMessage = ref<string | null>(null)

const videoWidth = ref(0)
const videoHeight = ref(0)
const readyState = ref(0)
const currentTime = ref(0)
const streamActive = ref(false)
const trackDiagnostics = ref(readCameraTrackDiagnostics(null))
const actualTrackDetails = ref(readActualTrackDetails(null))
const trackCapabilities = ref(readTrackCapabilitiesSummary(null))
const lastObservedCurrentTimeForFlow = ref(0)
const currentTimeProgressing = ref(true)

const stats = ref<DetectionStats>(createEmptyDetectionStats())
const variantAggregates = ref<VariantCodeAggregate[]>(createInitialVariantAggregates())
const successHistory = ref<SuccessHistoryEntry[]>([])
const lastSuccess = ref<SuccessHistoryEntry | null>(null)
const analyzedPreviewUrl = ref<string | null>(null)
const analyzedSourceMeta = ref<{ sourceLabel: string; width: number; height: number; zoomLabel: string } | null>(null)
const capturedFrames = ref<Array<{ label: string; url: string; width: number; height: number }>>([])

let cameraSessionId = 0
let detectionSessionId = 0
let diagnosticsTimer: number | null = null
let frameAnimationId: number | null = null
let detectionLoopAnimationId: number | null = null
let detectionInProgress = false
let lastDetectionTime = 0
let lastObservedCurrentTime = -1
const completedDetectionDurations: number[] = []
const aggregateDetectionDurations = new Map<string, number[]>()

const activeVariantLabel = computed(() => getVariantDefinition(activeVariantId.value).label)
const activeTestLabel = computed(() =>
  activeTestCategory.value === 'standard-ean' ? STANDARD_EAN_TEST_LABEL : SMALL_EAN_TEST_LABEL,
)
const overlayRect = computed(() => computeOverlayRectPercent(getVariantDefinition(activeVariantId.value)))
const videoOrientation = computed(() => getVideoOrientationLabel(videoWidth.value, videoHeight.value))
const comparisonTableRows = computed(() => buildComparisonTableRows(variantAggregates.value))
const variantComparisonEntries = computed(() => buildVariantComparisonEntries(variantAggregates.value))

const videoFlowWarning = computed(() => {
  if (cameraState.value !== 'active') {
    return null
  }

  if (videoWidth.value === 0 || videoHeight.value === 0) {
    return 'videoWidth ou videoHeight vaut 0 — le problème vient probablement du flux vidéo plutôt que de BarcodeDetector.'
  }

  if (!currentTimeProgressing.value) {
    return 'currentTime ne progresse pas — le flux vidéo semble figé.'
  }

  return null
})

const conclusion = computed(() => buildRoiConclusion(variantAggregates.value))

const canStartDetection = computed(() => {
  return cameraState.value === 'active'
    && environment.value.barcodeDetectorAvailable
    && detectorRef.value != null
    && videoWidth.value > 0
    && videoHeight.value > 0
    && currentTimeProgressing.value
})

function aggregateKey(variantId: RoiVariantId, codeCategory: CodeTestCategory): string {
  return `${variantId}:${codeCategory}`
}

function getAggregate(variantId: RoiVariantId, codeCategory: CodeTestCategory): VariantCodeAggregate | undefined {
  return variantAggregates.value.find(
    (item) => item.variantId === variantId && item.codeCategory === codeCategory,
  )
}

function serializeError(error: unknown): { name: string; message: string; constraint: string } {
  if (error instanceof DOMException || error instanceof Error) {
    const record = error as Error & { constraint?: string }

    return {
      name: record.name || '—',
      message: record.message || '—',
      constraint: record.constraint ? String(record.constraint) : '—',
    }
  }

  return { name: '—', message: String(error), constraint: '—' }
}

function isCameraSessionActive(sessionId: number): boolean {
  return sessionId === cameraSessionId
}

function isDetectionSessionActive(sessionId: number): boolean {
  return sessionId === detectionSessionId
}

function stopTracks(stream: MediaStream | null): void {
  stream?.getTracks().forEach((track) => {
    try {
      track.stop()
    } catch {
      // ignorer
    }
  })
}

function stopDetectionLoopAnimation(): void {
  if (detectionLoopAnimationId !== null) {
    cancelAnimationFrame(detectionLoopAnimationId)
    detectionLoopAnimationId = null
  }
}

function stopFrameCounter(): void {
  if (frameAnimationId !== null) {
    cancelAnimationFrame(frameAnimationId)
    frameAnimationId = null
  }
}

function stopDiagnosticsPolling(): void {
  if (diagnosticsTimer !== null) {
    window.clearInterval(diagnosticsTimer)
    diagnosticsTimer = null
  }
}

function refreshDiagnostics(): void {
  const video = videoRef.value

  videoWidth.value = video?.videoWidth ?? 0
  videoHeight.value = video?.videoHeight ?? 0
  readyState.value = video?.readyState ?? 0
  currentTime.value = video?.currentTime ?? 0
  streamActive.value = activeStream.value?.active ?? false
  trackDiagnostics.value = readCameraTrackDiagnostics(activeStream.value)
  actualTrackDetails.value = readActualTrackDetails(activeStream.value)
  trackCapabilities.value = readTrackCapabilitiesSummary(activeStream.value)

  if (video && cameraState.value === 'active') {
    currentTimeProgressing.value = video.currentTime > lastObservedCurrentTimeForFlow.value
    lastObservedCurrentTimeForFlow.value = video.currentTime
  }
}

function updateTestDurations(): void {
  const now = Date.now()

  if (stats.value.testStartedAt != null) {
    stats.value = { ...stats.value, testDurationMs: now - stats.value.testStartedAt }
  }

  const key = aggregateKey(activeVariantId.value, activeTestCategory.value)
  const aggregate = getAggregate(activeVariantId.value, activeTestCategory.value)

  if (!aggregate || aggregate.testStartedAt == null) {
    return
  }

  variantAggregates.value = variantAggregates.value.map((item) => {
    if (aggregateKey(item.variantId, item.codeCategory) !== key) {
      return item
    }

    return { ...item, testDurationMs: now - item.testStartedAt! }
  })
}

function startDiagnosticsPolling(): void {
  stopDiagnosticsPolling()
  diagnosticsTimer = window.setInterval(() => {
    refreshDiagnostics()
    updateTestDurations()
  }, 500)
}

function startFrameCounter(): void {
  stopFrameCounter()
  lastObservedCurrentTime = -1

  const tick = (): void => {
    const video = videoRef.value

    if (!video || cameraState.value !== 'active') {
      frameAnimationId = null
      return
    }

    if (video.currentTime !== lastObservedCurrentTime) {
      lastObservedCurrentTime = video.currentTime
      stats.value = { ...stats.value, framesSeen: stats.value.framesSeen + 1 }

      const key = aggregateKey(activeVariantId.value, activeTestCategory.value)
      variantAggregates.value = variantAggregates.value.map((item) => {
        if (aggregateKey(item.variantId, item.codeCategory) !== key) {
          return item
        }

        return { ...item, framesSeen: item.framesSeen + 1 }
      })
    }

    frameAnimationId = requestAnimationFrame(tick)
  }

  frameAnimationId = requestAnimationFrame(tick)
}

async function waitForVideoReady(video: HTMLVideoElement, sessionId: number): Promise<boolean> {
  const startedAt = Date.now()

  while (Date.now() - startedAt < START_TIMEOUT_MS) {
    if (!isCameraSessionActive(sessionId)) {
      return false
    }

    if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA && video.videoWidth > 0 && video.videoHeight > 0) {
      return true
    }

    await new Promise((resolve) => window.setTimeout(resolve, 100))
  }

  return false
}

async function ensureDetector(): Promise<boolean> {
  if (detectorRef.value) {
    return true
  }

  if (!environment.value.barcodeDetectorAvailable) {
    cameraError.value = {
      name: 'BarcodeDetectorUnavailable',
      message: 'BarcodeDetector natif non disponible sur ce navigateur.',
      constraint: '—',
    }

    return false
  }

  try {
    const created = await createNativeBarcodeDetector()
    detectorRef.value = created.detector
    supportedFormats.value = created.formatsUsed.length > 0
      ? created.formatsUsed
      : ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39']

    return true
  } catch (error) {
    cameraError.value = serializeError(error)
    return false
  }
}

function markTestStarted(variantId: RoiVariantId, codeCategory: CodeTestCategory): void {
  const now = Date.now()
  stats.value = { ...stats.value, testStartedAt: now, testDurationMs: 0 }

  variantAggregates.value = variantAggregates.value.map((item) => {
    if (item.variantId !== variantId || item.codeCategory !== codeCategory) {
      return item
    }

    return {
      ...item,
      testStartedAt: item.testStartedAt ?? now,
      testDurationMs: item.testStartedAt ? now - item.testStartedAt : 0,
    }
  })
}

function updateActiveAggregate(options: {
  incrementAttempts?: boolean
  incrementSuccess?: boolean
  incrementNotFound?: boolean
  incrementErrors?: boolean
  durationMs?: number
}): void {
  const variantId = activeVariantId.value
  const codeCategory = activeTestCategory.value
  const key = aggregateKey(variantId, codeCategory)

  variantAggregates.value = variantAggregates.value.map((aggregate) => {
    if (aggregate.variantId !== variantId || aggregate.codeCategory !== codeCategory) {
      return aggregate
    }

    const next = { ...aggregate }

    if (options.incrementAttempts) next.detectionAttempts += 1
    if (options.incrementSuccess) next.successfulDetections += 1
    if (options.incrementNotFound) next.notFound += 1
    if (options.incrementErrors) next.errors += 1

    if (options.durationMs != null) {
      const durations = [...(aggregateDetectionDurations.get(key) ?? []), options.durationMs]
      aggregateDetectionDurations.set(key, durations)
      next.lastDetectionMs = options.durationMs
      next.averageDetectionMs = computeAverageDetectionMs(durations)
      next.minDetectionMs = Math.min(...durations)
      next.maxDetectionMs = Math.max(...durations)
    }

    return next
  })
}

function revokePreviewUrl(): void {
  if (analyzedPreviewUrl.value?.startsWith('blob:')) {
    URL.revokeObjectURL(analyzedPreviewUrl.value)
  }
}

function setAnalyzedPreview(canvas: HTMLCanvasElement, meta: { sourceLabel: string; width: number; height: number; zoomLabel: string }): void {
  revokePreviewUrl()
  analyzedPreviewUrl.value = canvas.toDataURL('image/jpeg', 0.85)
  analyzedSourceMeta.value = meta
}

async function buildDetectionSource(video: HTMLVideoElement): Promise<{
  source: CanvasImageSource | HTMLVideoElement
  meta: { sourceLabel: string; width: number; height: number; zoomLabel: string } | null
}> {
  const variant = getVariantDefinition(activeVariantId.value)

  if (!variant.usesCanvas) {
    return {
      source: video,
      meta: {
        sourceLabel: variant.label,
        width: video.videoWidth,
        height: video.videoHeight,
        zoomLabel: `${variant.zoomFactor}×`,
      },
    }
  }

  const canvas = roiCanvasRef.value

  if (!canvas) {
    throw new Error('Canvas ROI indisponible.')
  }

  const meta = renderRoiToCanvas(video, canvas, variant)
  setAnalyzedPreview(canvas, meta)

  return { source: canvas, meta }
}

function recordSuccess(rawValue: string, format: string, durationMs: number): void {
  const entry: SuccessHistoryEntry = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    timestamp: new Date().toLocaleTimeString('fr-FR'),
    variantLabel: activeVariantLabel.value,
    rawValue,
    format,
    durationMs,
    codeCategory: activeTestCategory.value,
  }

  lastSuccess.value = entry
  successHistory.value = [entry, ...successHistory.value].slice(0, MAX_SUCCESS_HISTORY)
}

async function runSingleDetection(sessionId: number): Promise<void> {
  if (detectionInProgress || !isDetectionSessionActive(sessionId)) {
    return
  }

  const detector = detectorRef.value
  const video = videoRef.value

  if (!detector || !video || video.videoWidth <= 0 || video.videoHeight <= 0) {
    return
  }

  detectionInProgress = true
  const startedAt = performance.now()

  try {
    updateActiveAggregate({ incrementAttempts: true })
    stats.value = { ...stats.value, detectionAttempts: stats.value.detectionAttempts + 1 }

    const { source } = await buildDetectionSource(video)
    const results = await detector.detect(source)
    const durationMs = performance.now() - startedAt
    const best = pickBestNativeBarcode(results)

    completedDetectionDurations.push(durationMs)
    stats.value = {
      ...stats.value,
      lastDetectionMs: durationMs,
      averageDetectionMs: computeAverageDetectionMs(completedDetectionDurations),
      minDetectionMs: Math.min(...completedDetectionDurations),
      maxDetectionMs: Math.max(...completedDetectionDurations),
    }

    if (best?.rawValue) {
      updateActiveAggregate({ incrementSuccess: true, durationMs })
      stats.value = { ...stats.value, successfulDetections: stats.value.successfulDetections + 1 }
      recordSuccess(best.rawValue, formatNativeBarcodeFormat(best.format), durationMs)
    } else {
      updateActiveAggregate({ incrementNotFound: true, durationMs })
      stats.value = { ...stats.value, notFound: stats.value.notFound + 1 }
    }
  } catch {
    const durationMs = performance.now() - startedAt
    updateActiveAggregate({ incrementErrors: true, durationMs })
    stats.value = { ...stats.value, errors: stats.value.errors + 1 }
  } finally {
    detectionInProgress = false
  }
}

async function detectNow(): Promise<void> {
  await runSingleDetection(detectionSessionId)
}

function stopDetectionLoop(): void {
  detectionSessionId += 1
  detectionLoopState.value = 'stopped'
  stopDetectionLoopAnimation()
  detectionInProgress = false
}

function startDetectionLoop(): void {
  if (!canStartDetection.value) {
    return
  }

  stopDetectionLoop()
  detectionSessionId += 1
  const sessionId = detectionSessionId
  detectionLoopState.value = 'running'
  markTestStarted(activeVariantId.value, activeTestCategory.value)
  lastDetectionTime = 0

  const tick = (timestamp: number): void => {
    if (!isDetectionSessionActive(sessionId)) {
      detectionLoopAnimationId = null
      return
    }

    if (timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
      lastDetectionTime = timestamp
      void runSingleDetection(sessionId)
    }

    detectionLoopAnimationId = requestAnimationFrame(tick)
  }

  detectionLoopAnimationId = requestAnimationFrame(tick)
}

async function stopCamera(): Promise<void> {
  stopDetectionLoop()
  cameraSessionId += 1
  cameraState.value = 'stopping'

  const stream = activeStream.value
  stopTracks(stream)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  stopFrameCounter()
  stopDiagnosticsPolling()
  cameraState.value = 'idle'
  refreshDiagnostics()
}

async function startCamera(): Promise<void> {
  await stopCamera()

  if (!(await ensureDetector())) {
    cameraState.value = 'error'
    return
  }

  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'starting'
  cameraError.value = null

  try {
    const stream = await navigator.mediaDevices.getUserMedia(FIXED_CAMERA_CONSTRAINTS)

    if (!isCameraSessionActive(sessionId)) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream

    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Élément vidéo indisponible.')
    }

    video.srcObject = stream
    video.playsInline = true
    video.muted = true

    await video.play()

    const ready = await waitForVideoReady(video, sessionId)

    if (!ready || !isCameraSessionActive(sessionId)) {
      throw new Error('La vidéo n\'est pas devenue active dans le délai imparti.')
    }

    cameraState.value = 'active'
    lastObservedCurrentTimeForFlow.value = video.currentTime
    currentTimeProgressing.value = true
    refreshDiagnostics()
    startDiagnosticsPolling()
    startFrameCounter()
  } catch (error) {
    if (isCameraSessionActive(sessionId)) {
      cameraError.value = serializeError(error)
      cameraState.value = 'error'
      stopTracks(activeStream.value)
      activeStream.value = null
    }
  }
}

function resetStatistics(): void {
  stopDetectionLoop()
  stats.value = createEmptyDetectionStats()
  variantAggregates.value = createInitialVariantAggregates()
  successHistory.value = []
  lastSuccess.value = null
  completedDetectionDurations.length = 0
  aggregateDetectionDurations.clear()
  revokePreviewUrl()
  analyzedPreviewUrl.value = null
  analyzedSourceMeta.value = null
}

function selectVariant(variantId: RoiVariantId): void {
  activeVariantId.value = variantId
  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0
}

function selectTestCategory(category: CodeTestCategory): void {
  activeTestCategory.value = category
  stats.value = createEmptyDetectionStats()
  completedDetectionDurations.length = 0
}

function captureFrame(): void {
  const video = videoRef.value
  const canvas = document.createElement('canvas')

  if (!video || video.videoWidth <= 0 || video.videoHeight <= 0) {
    return
  }

  capturedFrames.value.forEach((frame) => {
    if (frame.url.startsWith('blob:')) {
      URL.revokeObjectURL(frame.url)
    }
  })

  const frames: Array<{ label: string; url: string; width: number; height: number }> = []

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const context = canvas.getContext('2d')

  if (context) {
    context.drawImage(video, 0, 0)
    frames.push({
      label: 'Original',
      url: canvas.toDataURL('image/jpeg', 0.85),
      width: canvas.width,
      height: canvas.height,
    })
  }

  const roiCanvas = roiCanvasRef.value ?? document.createElement('canvas')

  for (const variant of ROI_VARIANTS) {
    if (!variant.usesCanvas) {
      continue
    }

    const meta = renderRoiToCanvas(video, roiCanvas, variant)
    frames.push({
      label: variant.label,
      url: roiCanvas.toDataURL('image/jpeg', 0.85),
      width: meta.width,
      height: meta.height,
    })
  }

  capturedFrames.value = frames
}

async function copyDiagnostic(): Promise<void> {
  const text = buildRoiDiagnosticClipboard({
    environment: environment.value,
    supportedFormats: supportedFormats.value,
    requestedWidth: REQUESTED_WIDTH,
    requestedHeight: REQUESTED_HEIGHT,
    actualTrack: actualTrackDetails.value,
    trackDiagnostics: trackDiagnostics.value,
    trackCapabilities: trackCapabilities.value,
    videoOrientation: videoOrientation.value,
    aggregates: variantAggregates.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Diagnostic copié dans le presse-papiers.'
  } catch {
    copyMessage.value = 'Impossible de copier le diagnostic.'
  }
}

function handlePageHide(): void {
  void stopCamera()
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  window.addEventListener('pagehide', handlePageHide)
})

onBeforeUnmount(() => {
  window.removeEventListener('pagehide', handlePageHide)
  revokePreviewUrl()
  capturedFrames.value.forEach((frame) => {
    if (frame.url.startsWith('blob:')) {
      URL.revokeObjectURL(frame.url)
    }
  })
  void stopCamera()
})
</script>

<template>
  <Head title="Test ROI BarcodeDetector" />

  <div class="barcode-reader-test-page barcode-detector-roi-test">
    <div class="barcode-reader-test-page__container barcode-detector-roi-test__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Test ROI / Zoom BarcodeDetector</h1>
          <p class="barcode-reader-test-page__subtitle">
            Diagnostic DEV — taille apparente du code dans l'image analysée (résolution fixe).
          </p>
        </div>
        <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
      </header>

      <section class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Type de test actif</h2>
        <div class="barcode-detector-roi-test__actions mb-2">
          <button
            type="button"
            class="btn btn-sm"
            :class="activeTestCategory === 'small-ean' ? 'btn-primary' : 'btn-outline-primary'"
            @click="selectTestCategory('small-ean')"
          >
            Petit EAN-13
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="activeTestCategory === 'standard-ean' ? 'btn-primary' : 'btn-outline-primary'"
            @click="selectTestCategory('standard-ean')"
          >
            EAN-13 standard
          </button>
        </div>
        <p class="barcode-detector-roi-test__muted mb-1">
          Test actif : <strong>{{ activeTestLabel }}</strong>
        </p>
        <p v-if="activeTestCategory === 'standard-ean'" class="barcode-detector-roi-test__reference mb-0">
          Code de référence : <span class="font-monospace">{{ REFERENCE_EAN_VALUE }}</span> — Format : {{ REFERENCE_EAN_FORMAT }}
        </p>
        <p v-else class="barcode-detector-roi-test__muted mb-0">
          Présentez le petit EAN-13 problématique devant la caméra (valeur non requise).
        </p>
      </section>

      <section class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Environnement</h2>
        <dl class="barcode-detector-roi-test__grid">
          <div><dt>Browser</dt><dd>{{ environment.browserLabel }}</dd></div>
          <div><dt>Secure context</dt><dd>{{ environment.secureContext ? 'yes' : 'no' }}</dd></div>
          <div><dt>BarcodeDetector</dt><dd>{{ environment.barcodeDetectorAvailable ? 'disponible' : 'indisponible' }}</dd></div>
          <div class="barcode-detector-roi-test__grid-full">
            <dt>Formats utilisés</dt>
            <dd>{{ supportedFormats.length > 0 ? supportedFormats.join(', ') : '—' }}</dd>
          </div>
          <div><dt>Detection interval</dt><dd>{{ DETECTION_INTERVAL_MS }} ms</dd></div>
          <div><dt>CPU-friendly mode</dt><dd>yes</dd></div>
        </dl>
      </section>

      <section class="barcode-detector-roi-test__section barcode-detector-roi-test__section--video">
        <div class="barcode-detector-roi-test__video-wrap">
          <video
            ref="videoRef"
            class="barcode-detector-roi-test__video"
            autoplay
            muted
            playsinline
          />
          <div
            class="barcode-detector-roi-test__roi-overlay"
            :style="{
              left: `${overlayRect.left}%`,
              top: `${overlayRect.top}%`,
              width: `${overlayRect.width}%`,
              height: `${overlayRect.height}%`,
            }"
          >
            <span class="barcode-detector-roi-test__roi-label">Zone analysée — {{ activeVariantLabel }}</span>
          </div>
        </div>
        <canvas ref="roiCanvasRef" class="barcode-detector-roi-test__hidden-canvas" aria-hidden="true" />

        <div class="barcode-detector-roi-test__actions">
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="resetStatistics">Réinitialiser statistiques</button>
          <button type="button" class="btn btn-sm btn-primary" :disabled="cameraState === 'starting'" @click="startCamera">Démarrer caméra</button>
          <button type="button" class="btn btn-sm btn-outline-danger" :disabled="cameraState === 'idle'" @click="stopCamera">Arrêter caméra</button>
        </div>

        <div class="barcode-detector-roi-test__actions mt-2">
          <button
            v-for="variant in ROI_VARIANTS"
            :key="variant.id"
            type="button"
            class="btn btn-sm"
            :class="activeVariantId === variant.id ? 'btn-warning' : 'btn-outline-warning'"
            @click="selectVariant(variant.id)"
          >
            {{ variant.label }}
          </button>
        </div>

        <div class="barcode-detector-roi-test__actions mt-2">
          <button type="button" class="btn btn-sm btn-success" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="startDetectionLoop">
            Start test
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="detectionLoopState !== 'running'" @click="stopDetectionLoop">
            Stop test
          </button>
          <button type="button" class="btn btn-sm btn-outline-primary" :disabled="!canStartDetection || detectionLoopState === 'running'" @click="detectNow">
            Détecter maintenant
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="cameraState !== 'active'" @click="captureFrame">
            Capturer frame
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="copyDiagnostic">Copier diagnostic</button>
        </div>

        <p class="barcode-detector-roi-test__muted mt-2 mb-0">
          Profil actif : <strong>{{ activeVariantLabel }}</strong> — Variante : {{ activeVariantLabel }} — Test : {{ activeTestLabel }}
        </p>
      </section>

      <section class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Diagnostic caméra</h2>
        <dl class="barcode-detector-roi-test__grid">
          <div><dt>Camera</dt><dd>{{ cameraState }}</dd></div>
          <div><dt>Stream</dt><dd>{{ streamActive ? 'active' : 'inactive' }}</dd></div>
          <div><dt>Video readyState</dt><dd>{{ readyState }}</dd></div>
          <div><dt>Video width</dt><dd>{{ videoWidth }}</dd></div>
          <div><dt>Video height</dt><dd>{{ videoHeight }}</dd></div>
          <div><dt>CurrentTime</dt><dd>{{ currentTime.toFixed(2) }}</dd></div>
          <div><dt>Frames</dt><dd>{{ stats.framesSeen }}</dd></div>
          <div><dt>Track state</dt><dd>{{ trackDiagnostics.trackState }}</dd></div>
          <div><dt>Facing mode</dt><dd>{{ actualTrackDetails.facingMode }}</dd></div>
          <div><dt>Requested</dt><dd>{{ REQUESTED_WIDTH }} × {{ REQUESTED_HEIGHT }}</dd></div>
          <div><dt>Actual</dt><dd>{{ actualTrackDetails.width ?? '—' }} × {{ actualTrackDetails.height ?? '—' }}</dd></div>
          <div><dt>FPS</dt><dd>{{ actualTrackDetails.frameRate }}</dd></div>
          <div><dt>Video orientation</dt><dd>{{ videoOrientation }}</dd></div>
          <div><dt>Actual dimensions</dt><dd>{{ videoWidth }} × {{ videoHeight }}</dd></div>
          <div><dt>focusMode</dt><dd>{{ trackCapabilities.focusMode }}</dd></div>
          <div><dt>zoom</dt><dd>{{ trackCapabilities.zoom }}</dd></div>
          <div><dt>torch</dt><dd>{{ trackCapabilities.torch }}</dd></div>
        </dl>
        <p v-if="videoFlowWarning" class="barcode-detector-roi-test__warning mb-0 mt-2">{{ videoFlowWarning }}</p>
        <div v-if="cameraError" class="barcode-detector-roi-test__error-block mt-3">
          <p class="barcode-detector-roi-test__error-title mb-2">Erreur</p>
          <dl class="barcode-detector-roi-test__grid">
            <div><dt>Name</dt><dd>{{ cameraError.name }}</dd></div>
            <div><dt>Constraint</dt><dd>{{ cameraError.constraint }}</dd></div>
            <div class="barcode-detector-roi-test__grid-full">
              <dt>Message</dt>
              <dd class="barcode-detector-roi-test__break">{{ cameraError.message }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Compteurs session ({{ activeVariantLabel }} — {{ activeTestLabel }})</h2>
        <dl class="barcode-detector-roi-test__grid">
          <div><dt>Attempts</dt><dd>{{ stats.detectionAttempts }}</dd></div>
          <div><dt>Success</dt><dd>{{ stats.successfulDetections }}</dd></div>
          <div><dt>Not found</dt><dd>{{ stats.notFound }}</dd></div>
          <div><dt>Errors</dt><dd>{{ stats.errors }}</dd></div>
          <div><dt>Success rate</dt><dd>{{ computeSuccessRate(stats) }}</dd></div>
          <div><dt>NOT FOUND rate</dt><dd>{{ computeNotFoundRate(stats) }}</dd></div>
          <div><dt>Error rate</dt><dd>{{ computeErrorRate(stats) }}</dd></div>
          <div><dt>Average</dt><dd>{{ formatDurationMs(stats.averageDetectionMs) }}</dd></div>
          <div><dt>Min</dt><dd>{{ formatDurationMs(stats.minDetectionMs) }}</dd></div>
          <div><dt>Max</dt><dd>{{ formatDurationMs(stats.maxDetectionMs) }}</dd></div>
          <div><dt>Data sufficient</dt><dd>{{ formatDataSufficient(stats) }}</dd></div>
          <div><dt>Test duration</dt><dd>{{ Math.round(stats.testDurationMs / 1000) }} s</dd></div>
        </dl>
      </section>

      <section v-if="lastSuccess" class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Dernier succès</h2>
        <p class="barcode-detector-roi-test__success mb-1">SUCCESS</p>
        <dl class="barcode-detector-roi-test__grid">
          <div class="barcode-detector-roi-test__grid-full"><dt>Value</dt><dd class="font-monospace">{{ lastSuccess.rawValue }}</dd></div>
          <div><dt>Format</dt><dd>{{ lastSuccess.format }}</dd></div>
          <div><dt>Variante</dt><dd>{{ lastSuccess.variantLabel }}</dd></div>
          <div><dt>Temps</dt><dd>{{ formatDurationMs(lastSuccess.durationMs) }}</dd></div>
        </dl>
      </section>

      <section v-if="analyzedPreviewUrl && analyzedSourceMeta" class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Image analysée</h2>
        <p class="barcode-detector-roi-test__muted">
          Aperçu de la dernière source réellement envoyée au BarcodeDetector (variantes canvas uniquement).
        </p>
        <dl class="barcode-detector-roi-test__grid">
          <div><dt>Source</dt><dd>{{ analyzedSourceMeta.sourceLabel }}</dd></div>
          <div><dt>Dimensions</dt><dd>{{ analyzedSourceMeta.width }} × {{ analyzedSourceMeta.height }}</dd></div>
          <div><dt>Zoom</dt><dd>{{ analyzedSourceMeta.zoomLabel }}</dd></div>
        </dl>
        <img :src="analyzedPreviewUrl" alt="Image analysée" class="barcode-detector-roi-test__preview-image">
      </section>

      <section class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Tableau comparatif</h2>
        <div class="barcode-detector-roi-test__table-wrap">
          <table class="table table-sm barcode-detector-roi-test__table mb-0">
            <thead>
              <tr>
                <th>Variante</th>
                <th>Zone</th>
                <th>Zoom</th>
                <th>Code</th>
                <th class="text-end">Attempts</th>
                <th class="text-end">Success</th>
                <th class="text-end">Rate</th>
                <th class="text-end">Avg</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in comparisonTableRows" :key="`${row.variantLabel}-${row.codeCategory}`">
                <td>{{ row.variantLabel }}</td>
                <td>{{ row.zoneLabel }}</td>
                <td>{{ row.zoomLabel }}</td>
                <td>{{ row.codeLabel }}</td>
                <td class="text-end">{{ row.attempts }}</td>
                <td class="text-end">{{ row.success }}</td>
                <td class="text-end">{{ row.successRate }}</td>
                <td class="text-end">{{ row.averageMs }}</td>
                <td>{{ row.dataSufficient ? 'YES' : 'NO' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Comparaison petit EAN vs standard</h2>
        <div class="barcode-detector-roi-test__table-wrap">
          <table class="table table-sm barcode-detector-roi-test__table mb-0">
            <thead>
              <tr>
                <th>Variante</th>
                <th class="text-end">Petit EAN-13</th>
                <th class="text-end">EAN-13 standard</th>
                <th class="text-end">Écart</th>
                <th>Data</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in variantComparisonEntries" :key="entry.variantLabel">
                <td>{{ entry.variantLabel }}</td>
                <td class="text-end">{{ entry.smallSuccessRate }}</td>
                <td class="text-end">{{ entry.standardSuccessRate }}</td>
                <td class="text-end">{{ entry.differencePoints }}</td>
                <td>{{ entry.dataSufficient ? 'YES' : 'NO' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="successHistory.length > 0" class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Historique (20 derniers succès)</h2>
        <div class="barcode-detector-roi-test__history">
          <div v-for="entry in successHistory" :key="entry.id" class="barcode-detector-roi-test__history-item font-monospace">
            {{ entry.timestamp }} — {{ entry.variantLabel }} — {{ entry.rawValue }} — {{ entry.format }} — {{ formatDurationMs(entry.durationMs) }}
          </div>
        </div>
      </section>

      <section v-if="capturedFrames.length > 0" class="barcode-detector-roi-test__section">
        <h2 class="barcode-detector-roi-test__section-title">Capture visuelle</h2>
        <p class="barcode-detector-roi-test__muted">
          Ces captures sont uniquement visuelles et ne sont pas utilisées pour le décodage automatique.
        </p>
        <div class="barcode-detector-roi-test__capture-grid">
          <figure v-for="frame in capturedFrames" :key="frame.label" class="barcode-detector-roi-test__capture-item">
            <figcaption>{{ frame.label }} — {{ frame.width }} × {{ frame.height }}</figcaption>
            <img :src="frame.url" :alt="frame.label" class="barcode-detector-roi-test__preview-image">
          </figure>
        </div>
      </section>

      <section class="barcode-detector-roi-test__section barcode-detector-roi-test__conclusion">
        <h2 class="barcode-detector-roi-test__section-title">Conclusion</h2>
        <pre class="barcode-detector-roi-test__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-detector-roi-test__muted mb-0">{{ copyMessage }}</p>
    </div>
  </div>
</template>
