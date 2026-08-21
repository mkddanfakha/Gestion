<script setup lang="ts">
import {
  aggregateByFocus,
  applyExperimentConfiguration,
  buildBestFocusRanking,
  buildConfigurationOrder,
  buildMultiFrameAnalysis,
  buildStabilityConclusion,
  buildStabilityExportJson,
  buildStabilityReport,
  classifyDetection,
  createComparisonBarcodeDetector,
  createEmptyStabilityResult,
  DEFAULT_DURATION_SECONDS,
  DEFAULT_EXPECTED_BARCODE,
  DEFAULT_EXPECTED_FORMAT,
  DEFAULT_FOCUS_LEVELS,
  DEFAULT_REPETITIONS,
  DEFAULT_SETTLE_MS,
  DETECTION_INTERVAL_MS,
  DURATION_OPTIONS,
  extractBarcodeGeometry,
  finalizeStabilityResult,
  FIXED_ZOOM,
  getEnvironmentDiagnostics,
  isManualFocusSupported,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  MEDIUM_GUIDE_WIDTH_RATIO,
  MEDIUM_SIZE_TARGET,
  normalizeDetections,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  REPETITION_OPTIONS,
  resolveFocusLevels,
  SETTLE_OPTIONS,
  STABILITY_CAMERA_CONSTRAINTS,
  type AppliedExperimentSnapshot,
  type DetectionSnapshot,
  type FocusAggregateSummary,
  type MultiFrameThresholdResult,
  type StabilityConfigurationResult,
  type StabilityRawDetection,
  type StabilityRunConfiguration,
} from '@/utils/barcodeStabilityFocusRepeatability'
import type { BarcodeDetectorLike } from '@/utils/barcodeSizeZoomComparison'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type RunUiState = 'IDLE' | 'RUNNING' | 'STOPPED' | 'COMPLETED'
type RawFilter = 'all' | 'correct' | 'incorrect'

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const runState = ref<RunUiState>('IDLE')
const cameraError = ref<string | null>(null)
const copyMessage = ref<string | null>(null)

const expectedBarcode = ref(DEFAULT_EXPECTED_BARCODE)
const expectedFormat = ref(DEFAULT_EXPECTED_FORMAT)
const focusInput = ref(DEFAULT_FOCUS_LEVELS.join(', '))
const repetitions = ref<number>(DEFAULT_REPETITIONS)
const durationSeconds = ref<number>(DEFAULT_DURATION_SECONDS)
const settleMs = ref<number>(DEFAULT_SETTLE_MS)
const useRandomOrder = ref(false)
const randomSeed = ref<number | null>(null)
const rawFilter = ref<RawFilter>('all')

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<StabilityRunConfiguration[]>([])
const results = ref<StabilityConfigurationResult[]>([])
const rawDetections = ref<StabilityRawDetection[]>([])
const lastApplied = ref<AppliedExperimentSnapshot | null>(null)

const currentConfig = ref<StabilityRunConfiguration | null>(null)
const currentConfigIndex = ref(0)
const elapsedSeconds = ref(0)
const remainingSeconds = ref(0)
const liveWidthRatio = ref<number | null>(null)
const liveFocus = ref<string>('—')
const liveZoom = ref<string>('1×')

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let countdownTimer: number | null = null

const parsedFocusLevels = computed(() => {
  const values = focusInput.value
    .split(/[,;\s]+/)
    .map((item) => Number.parseFloat(item.trim()))
    .filter((value) => Number.isFinite(value))

  return resolveFocusLevels(values, capabilities.value.focusDistance)
})

const manualFocusSupported = computed(() => isManualFocusSupported(capabilities.value))
const configCount = computed(() => configurations.value.length)

const focusSummary = computed<FocusAggregateSummary[]>(() =>
  aggregateByFocus(results.value, parsedFocusLevels.value),
)

const detectionSnapshots = computed<DetectionSnapshot[]>(() =>
  rawDetections.value.map((item) => ({
    rawValue: item.rawValue,
    classification: item.classification,
    elapsedMs: item.elapsedMs,
    sharpness: item.sharpness,
    timestamp: item.timestamp,
  })),
)

const multiFrameAnalysis = computed<MultiFrameThresholdResult[]>(() =>
  buildMultiFrameAnalysis(detectionSnapshots.value, expectedBarcode.value),
)

const conclusion = computed(() => buildStabilityConclusion(results.value, parsedFocusLevels.value))
const bestRanking = computed(() => buildBestFocusRanking(results.value))

const filteredRawDetections = computed(() => {
  if (rawFilter.value === 'correct') {
    return rawDetections.value.filter((item) => item.classification === 'CORRECT')
  }

  if (rawFilter.value === 'incorrect') {
    return rawDetections.value.filter((item) => item.classification === 'INCORRECT')
  }

  return rawDetections.value
})

const progressPercent = computed(() =>
  configCount.value > 0 ? Math.round((currentConfigIndex.value / configCount.value) * 100) : 0,
)

const canStartExperiment = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && manualFocusSupported.value
  && expectedBarcode.value.trim().length > 0
  && parsedFocusLevels.value.length > 0
  && runState.value !== 'RUNNING',
)

const guideStyle = computed(() => ({
  width: `${MEDIUM_GUIDE_WIDTH_RATIO * 100}%`,
  aspectRatio: '2 / 1',
}))

function getVideoTrack(): MediaStreamTrack | null {
  return activeStream.value?.getVideoTracks()[0] ?? null
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

function stopTimers(): void {
  if (countdownTimer !== null) {
    window.clearInterval(countdownTimer)
    countdownTimer = null
  }
}

function rebuildConfigurations(): void {
  configurations.value = buildConfigurationOrder({
    focusLevels: parsedFocusLevels.value,
    repetitions: repetitions.value,
    expectedBarcode: expectedBarcode.value,
    preserveOrder: useRandomOrder.value ? undefined : configurations.value,
    randomized: useRandomOrder.value,
    randomSeed: randomSeed.value ?? Date.now(),
  })

  if (useRandomOrder.value && randomSeed.value == null) {
    randomSeed.value = Date.now()
  }

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyStabilityResult(config)
  })
}

function resetExperiment(): void {
  stopRun()
  rawDetections.value = []
  results.value = configurations.value.map((config) => createEmptyStabilityResult(config))
  currentConfig.value = null
  currentConfigIndex.value = 0
  elapsedSeconds.value = 0
  remainingSeconds.value = 0
  randomSeed.value = useRandomOrder.value ? Date.now() : null
  rebuildConfigurations()
}

function refreshDiagnostics(): void {
  const track = getVideoTrack()
  capabilities.value = readTrackCapabilitiesSnapshot(track)
  trackSettings.value = readTrackSettingsSnapshot(track)
}

async function startCamera(): Promise<void> {
  if (cameraState.value === 'STARTING') {
    return
  }

  cameraSessionId += 1
  const sessionId = cameraSessionId
  cameraState.value = 'STARTING'
  cameraError.value = null

  try {
    stopTracks(activeStream.value)

    const stream = await navigator.mediaDevices.getUserMedia(STABILITY_CAMERA_CONSTRAINTS)

    if (sessionId !== cameraSessionId) {
      stopTracks(stream)
      return
    }

    activeStream.value = stream
    await nextTick()

    const video = videoRef.value

    if (!video) {
      throw new Error('Video element unavailable')
    }

    video.srcObject = stream
    video.playsInline = true
    video.muted = true

    await Promise.race([
      video.play(),
      new Promise<never>((_, reject) => {
        window.setTimeout(() => reject(new Error('Video play timeout')), START_TIMEOUT_MS)
      }),
    ])

    refreshDiagnostics()
    rebuildConfigurations()

    const detectorResult = await createComparisonBarcodeDetector()
    detectorRef.value = detectorResult.detector
    cameraState.value = 'READY'
  } catch (error) {
    cameraState.value = 'ERROR'

    if (error instanceof DOMException && error.name === 'NotAllowedError') {
      cameraError.value = 'Camera permission denied'
    } else if (error instanceof Error) {
      cameraError.value = error.message.includes('Video') ? 'Camera stream failed' : error.message
    } else {
      cameraError.value = 'Unknown error'
    }
  }
}

async function stopCamera(): Promise<void> {
  stopRun()
  stopTimers()
  cameraSessionId += 1
  cameraState.value = 'STOPPED'

  stopTracks(activeStream.value)

  if (videoRef.value) {
    videoRef.value.pause()
    videoRef.value.srcObject = null
  }

  activeStream.value = null
  detectorRef.value = null
  refreshDiagnostics()
  cameraState.value = 'IDLE'
}

function stopRun(): void {
  runAbort = true
  runSessionId += 1
  stopTimers()
  currentConfig.value = null

  if (runState.value === 'RUNNING') {
    runState.value = 'STOPPED'
  }
}

async function runConfiguration(
  config: StabilityRunConfiguration,
  configIndex: number,
  sessionId: number,
): Promise<StabilityConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyStabilityResult(config)
  }

  currentConfig.value = config
  currentConfigIndex.value = configIndex + 1
  liveWidthRatio.value = null
  liveFocus.value = String(config.focusRequested)
  liveZoom.value = '1×'

  const applied = await applyExperimentConfiguration(track, {
    requestedFocusDistance: config.focusRequested,
    requestedZoom: config.requestedZoom,
    focusDistanceCapabilities: capabilities.value.focusDistance,
    zoomStep: capabilities.value.zoom.step,
  })

  lastApplied.value = applied
  liveFocus.value = applied.actualFocusDistance
  liveZoom.value = `${applied.actualZoom}×`

  if (applied.configurationStatus !== 'VALID') {
    return finalizeStabilityResult(config, applied, {
      frames: 0,
      detections: [],
      notFound: 0,
      sharpnessValues: [],
      sharpnessAtDetections: [],
      sharpnessAtCorrectDetections: [],
      widthRatios: [],
    })
  }

  await new Promise((resolve) => window.setTimeout(resolve, settleMs.value))

  if (runAbort || sessionId !== runSessionId) {
    return createEmptyStabilityResult(config)
  }

  let frames = 0
  let notFound = 0
  const detections: DetectionSnapshot[] = []
  const sharpnessValues: number[] = []
  const sharpnessAtDetections: number[] = []
  const sharpnessAtCorrectDetections: number[] = []
  const widthRatios: number[] = []
  let detectionInProgress = false
  let lastDetectionTime = 0

  const startedAt = performance.now()
  const endAt = startedAt + durationSeconds.value * 1000
  remainingSeconds.value = durationSeconds.value
  elapsedSeconds.value = 0

  stopTimers()
  countdownTimer = window.setInterval(() => {
    const elapsed = performance.now() - startedAt
    elapsedSeconds.value = Math.floor(elapsed / 1000)
    remainingSeconds.value = Math.max(0, Math.ceil((endAt - performance.now()) / 1000))
  }, 200)

  await new Promise<void>((resolve) => {
    const tick = (timestamp: number): void => {
      if (runAbort || sessionId !== runSessionId || performance.now() >= endAt) {
        resolve()
        return
      }

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp

        void (async () => {
          const frameStartedAt = performance.now()
          const elapsedMs = Math.round(frameStartedAt - startedAt)
          const sharpness = measureVideoSharpness(video, canvas)

          if (sharpness != null) {
            sharpnessValues.push(sharpness)
          }

          try {
            frames += 1
            const rawResults = normalizeDetections(await detector.detect(video))

            if (rawResults.length === 0) {
              notFound += 1
            } else {
              for (const result of rawResults) {
                const rawValue = result.rawValue ?? ''

                if (!rawValue) {
                  continue
                }

                const classification = classifyDetection(rawValue, config.expectedBarcode)
                const geometry = extractBarcodeGeometry(result, video.videoWidth)
                const heightRatio = result.boundingBox && video.videoHeight > 0
                  ? result.boundingBox.height / video.videoHeight
                  : null
                const timestamp = new Date().toLocaleTimeString('fr-FR')

                detections.push({
                  rawValue,
                  classification,
                  elapsedMs,
                  sharpness,
                  timestamp,
                })

                if (sharpness != null) {
                  sharpnessAtDetections.push(sharpness)
                }

                if (classification === 'CORRECT' && sharpness != null) {
                  sharpnessAtCorrectDetections.push(sharpness)
                }

                if (geometry.widthRatio != null) {
                  liveWidthRatio.value = geometry.widthRatio
                  widthRatios.push(geometry.widthRatio)
                }

                rawDetections.value = [{
                  id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                  timestamp,
                  elapsedMs,
                  focusRequested: config.focusRequested,
                  focusActual: applied.actualFocusDistance,
                  repetition: config.repetition,
                  format: result.format ?? '—',
                  rawValue,
                  classification,
                  boundingBox: result.boundingBox ?? null,
                  boundingBoxWidth: geometry.width,
                  boundingBoxHeight: geometry.height,
                  widthRatio: geometry.widthRatio,
                  heightRatio,
                  sharpness,
                }, ...rawDetections.value]
              }
            }
          } catch {
            notFound += 1
          } finally {
            detectionInProgress = false
          }
        })()
      }

      requestAnimationFrame(tick)
    }

    requestAnimationFrame(tick)
  })

  stopTimers()
  refreshDiagnostics()

  return finalizeStabilityResult(config, applied, {
    frames,
    detections,
    notFound,
    sharpnessValues,
    sharpnessAtDetections,
    sharpnessAtCorrectDetections,
    widthRatios,
  })
}

async function startExperiment(): Promise<void> {
  if (!canStartExperiment.value) {
    return
  }

  rebuildConfigurations()

  const configs = [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex)
  const minutes = Math.ceil((configs.length * (durationSeconds.value + settleMs.value / 1000)) / 60)
  const confirmed = window.confirm(
    `Run ${configs.length} configurations × ${durationSeconds.value}s (+ ${settleMs.value}ms settle) ≈ ${minutes} min?`,
  )

  if (!confirmed) {
    return
  }

  stopRun()
  runSessionId += 1
  const sessionId = runSessionId
  runAbort = false
  runState.value = 'RUNNING'
  rawDetections.value = []
  results.value = configs.map((config) => createEmptyStabilityResult(config))

  for (let index = 0; index < configs.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = configs[index]!
    const result = await runConfiguration(config, index, sessionId)
    results.value = results.value.map((item) => item.configId === config.id ? result : item)
  }

  currentConfig.value = null
  stopTimers()

  if (runAbort) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
}

function addFocusValue(): void {
  const values = parsedFocusLevels.value
  const next = values.length ? Math.min((values.at(-1) ?? 0.2) + 0.02, capabilities.value.focusDistance.max ?? 0.78) : 0.2
  focusInput.value = [...values, Number(next.toFixed(2))].join(', ')
  rebuildConfigurations()
}

function resetFocusList(): void {
  focusInput.value = DEFAULT_FOCUS_LEVELS.join(', ')
  rebuildConfigurations()
}

function buildReportPayload() {
  return {
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    expectedBarcode: expectedBarcode.value,
    expectedFormat: expectedFormat.value,
    focusLevels: parsedFocusLevels.value,
    repetitions: repetitions.value,
    durationSeconds: durationSeconds.value,
    settleMs: settleMs.value,
    randomized: useRandomOrder.value,
    randomSeed: randomSeed.value,
    configurationOrder: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
    results: [...results.value].sort((a, b) => a.orderIndex - b.orderIndex),
    rawDetections: rawDetections.value,
    multiFrameAnalysis: multiFrameAnalysis.value,
    focusSummary: focusSummary.value,
    conclusion: conclusion.value,
  }
}

async function copyReport(): Promise<void> {
  try {
    await navigator.clipboard.writeText(buildStabilityReport(buildReportPayload()))
    copyMessage.value = 'Rapport copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function exportJson(): void {
  const blob = new Blob([buildStabilityExportJson(buildReportPayload())], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `barcode-stability-focus-repeatability-${Date.now()}.json`
  anchor.click()
  URL.revokeObjectURL(url)
  copyMessage.value = 'JSON exporté.'
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopRun()
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  rebuildConfigurations()
  window.addEventListener('pagehide', () => { void stopCamera() })
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  void stopCamera()
})
</script>

<template>
  <Head title="Barcode Stability Focus Repeatability" />

  <div class="barcode-reader-test-page barcode-stability-focus-repeatability">
    <div class="barcode-reader-test-page__container barcode-stability-focus-repeatability__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Stability Focus Repeatability</h1>
          <p class="barcode-reader-test-page__subtitle">Répétabilité des lectures BarcodeDetector — MEDIUM, zoom 1× (DEV isolé)</p>
        </div>
        <div class="barcode-stability-focus-repeatability__header-links">
          <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
          <Link href="/dev/barcode-detector-distance-focus" class="btn btn-sm btn-outline-secondary">Distance × Focus</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
          <Link href="/dev/barcode-detector-decode-reliability-matrix" class="btn btn-sm btn-outline-secondary">Reliability Matrix</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-stability-focus-repeatability__banner">
        <p class="mb-1">BarcodeDetector: <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong></p>
        <p class="mb-1">Manual focus: <strong>{{ manualFocusSupported ? 'SUPPORTED' : 'MANUAL FOCUS NOT SUPPORTED' }}</strong></p>
        <p class="mb-0 barcode-stability-focus-repeatability__muted">Analyse expérimentale uniquement — aucun impact sur le scanner de production.</p>
      </section>

      <section class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">1. TEST CONFIGURATION</h2>
        <div class="barcode-stability-focus-repeatability__grid-form">
          <div>
            <label>Expected barcode</label>
            <input v-model="expectedBarcode" type="text" class="form-control form-control-sm font-monospace">
          </div>
          <div>
            <label>Expected format</label>
            <input v-model="expectedFormat" type="text" class="form-control form-control-sm font-monospace">
          </div>
        </div>
        <div class="mb-2">
          <label>Focus list (comma separated)</label>
          <input v-model="focusInput" type="text" class="form-control form-control-sm font-monospace" :disabled="runState === 'RUNNING'">
          <div class="barcode-stability-focus-repeatability__actions mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="runState === 'RUNNING'" @click="addFocusValue">Add value</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="runState === 'RUNNING'" @click="resetFocusList">Reset</button>
          </div>
          <p class="barcode-stability-focus-repeatability__muted mb-0 mt-1">Resolved: {{ parsedFocusLevels.join(', ') || '—' }}</p>
        </div>
        <div class="barcode-stability-focus-repeatability__grid-form">
          <div>
            <label>Repetitions per focus</label>
            <select v-model.number="repetitions" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in REPETITION_OPTIONS" :key="option" :value="option">{{ option }}</option>
            </select>
          </div>
          <div>
            <label>Duration</label>
            <select v-model.number="durationSeconds" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in DURATION_OPTIONS" :key="option" :value="option">{{ option }} s</option>
            </select>
          </div>
          <div>
            <label>Settle time</label>
            <select v-model.number="settleMs" class="form-select form-select-sm" :disabled="runState === 'RUNNING'">
              <option v-for="option in SETTLE_OPTIONS" :key="option" :value="option">{{ option }} ms</option>
            </select>
          </div>
        </div>
        <div class="barcode-stability-focus-repeatability__actions">
          <button
            type="button"
            class="btn btn-sm"
            :class="!useRandomOrder ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING'"
            @click="useRandomOrder = false; rebuildConfigurations()"
          >
            Fixed order
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="useRandomOrder ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING'"
            @click="useRandomOrder = true; randomSeed = Date.now(); rebuildConfigurations()"
          >
            Randomize order
          </button>
        </div>
        <p class="barcode-stability-focus-repeatability__muted mb-0 mt-2">
          Size: MEDIUM ({{ MEDIUM_SIZE_TARGET }}) · Zoom: 1× · Total configurations: {{ configCount }}
        </p>
      </section>

      <section class="barcode-stability-focus-repeatability__section barcode-stability-focus-repeatability__section--video">
        <h2 class="barcode-stability-focus-repeatability__section-title">2. CAMERA</h2>
        <div class="barcode-stability-focus-repeatability__video-wrap">
          <video ref="videoRef" class="barcode-stability-focus-repeatability__video" autoplay muted playsinline />
          <div class="barcode-stability-focus-repeatability__size-guide" :style="guideStyle">
            <span class="barcode-stability-focus-repeatability__size-guide-label">MEDIUM</span>
          </div>
          <div v-if="liveWidthRatio != null || runState === 'RUNNING'" class="barcode-stability-focus-repeatability__live-status">
            <div>Detected barcode width:</div>
            <div><strong>{{ liveWidthRatio != null ? `${(liveWidthRatio * 100).toFixed(1)}%` : '—' }}</strong></div>
            <div>Focus: {{ liveFocus }}</div>
            <div>Zoom: {{ liveZoom }}</div>
          </div>
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-stability-focus-repeatability__hidden-canvas" aria-hidden="true" />

        <dl class="barcode-stability-focus-repeatability__grid mt-3">
          <div><dt>Resolution</dt><dd>{{ trackSettings.width ?? '—' }}×{{ trackSettings.height ?? '—' }}</dd></div>
          <div><dt>FPS</dt><dd>{{ trackSettings.frameRate }}</dd></div>
          <div><dt>Facing</dt><dd>{{ trackSettings.facingMode }}</dd></div>
          <div><dt>Focus capabilities</dt><dd>{{ capabilities.focusDistance.min ?? '—' }} → {{ capabilities.focusDistance.max ?? '—' }} (step {{ capabilities.focusDistance.step ?? '—' }})</dd></div>
          <div><dt>Zoom capabilities</dt><dd>{{ capabilities.zoom.min ?? '—' }} → {{ capabilities.zoom.max ?? '—' }}</dd></div>
          <div><dt>Zoom requested / actual</dt><dd>{{ FIXED_ZOOM }}× / {{ lastApplied?.actualZoom ?? trackSettings.zoom }}×</dd></div>
        </dl>

        <div class="barcode-stability-focus-repeatability__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCamera">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canStartExperiment" @click="startExperiment">Start Experiment</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="runState === 'RUNNING'" @click="resetExperiment">Reset</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Copy Report</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportJson">Export JSON</button>
        </div>
      </section>

      <section v-if="currentConfig && runState === 'RUNNING'" class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">3. CURRENT TEST</h2>
        <pre class="barcode-stability-focus-repeatability__pre mb-0">Focus: {{ currentConfig.focusRequested }}
Repetition: {{ currentConfig.repetition }} / {{ repetitions }}
Elapsed: {{ elapsedSeconds }} s
Remaining: {{ remainingSeconds }} s</pre>
      </section>

      <section v-if="runState === 'RUNNING' || runState === 'COMPLETED'" class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">4. PROGRESS</h2>
        <p class="mb-2">Progress: {{ currentConfigIndex }} / {{ configCount }} configurations</p>
        <div class="progress mb-2" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-bar" :style="{ width: `${progressPercent}%` }">{{ progressPercent }}%</div>
        </div>
        <ol class="barcode-stability-focus-repeatability__order-list">
          <li v-for="config in [...configurations].sort((a, b) => a.orderIndex - b.orderIndex)" :key="config.id">
            focus {{ config.focusRequested }} — repetition {{ config.repetition }}
          </li>
        </ol>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">5. RESULTS BY CONFIGURATION</h2>
        <article
          v-for="result in [...results].sort((a, b) => a.orderIndex - b.orderIndex)"
          :key="result.configId"
          class="barcode-stability-focus-repeatability__result-card"
        >
          <h3 class="barcode-stability-focus-repeatability__result-title">FOCUS {{ result.focusRequested }} — REPETITION {{ result.repetition }}</h3>
          <pre class="barcode-stability-focus-repeatability__pre mb-0">Frames: {{ result.frames }}
Detections: {{ result.detections }}
Correct: {{ result.correct }}
Incorrect: {{ result.incorrect }}
Detection rate: {{ result.detectionRate }}
Correct rate: {{ result.correctRate }}

Distinct values: {{ result.distinctValues }}

Most frequent:
{{ result.mostFrequentValue ?? '—' }}

Occurrences:
{{ result.mostFrequentOccurrences }} / {{ result.detections }}

Temporal stability:
{{ result.temporalStability ?? '—' }}

Longest identical sequence: {{ result.longestIdenticalSequence }}
Longest correct sequence: {{ result.longestCorrectSequence }}
Correct stability: {{ result.correctStability ?? '—' }}

Average sharpness: {{ result.averageSharpness ?? '—' }}
Average barcode width: {{ result.averageBarcodeWidthRatio != null ? `${(result.averageBarcodeWidthRatio * 100).toFixed(1)}%` : '—' }}

Stability: {{ result.stability }}</pre>
        </article>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">6. SUMMARY BY FOCUS</h2>
        <div class="barcode-stability-focus-repeatability__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Focus</th>
                <th>Frames</th>
                <th>Detections</th>
                <th>Correct</th>
                <th>Correct rate</th>
                <th>Detection rate</th>
                <th>Distinct</th>
                <th>Temporal stability</th>
                <th>Repeatability</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in focusSummary" :key="row.focus">
                <td>{{ row.focus }}</td>
                <td>{{ row.totalFrames }}</td>
                <td>{{ row.totalDetections }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.detectionRate }}</td>
                <td>{{ row.averageDistinctValues }}</td>
                <td>{{ row.averageTemporalStability }}</td>
                <td>{{ row.repeatability }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">7. MULTI-FRAME ANALYSIS</h2>
        <p class="barcode-stability-focus-repeatability__muted">Simulation analytique sur les détections enregistrées — n'affecte pas le scanner.</p>
        <div class="barcode-stability-focus-repeatability__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Threshold</th>
                <th>Confirmations</th>
                <th>Correct confirmations</th>
                <th>Incorrect confirmations</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in multiFrameAnalysis" :key="row.threshold">
                <td>{{ row.threshold }}</td>
                <td>{{ row.confirmations }}</td>
                <td>{{ row.correctConfirmations }}</td>
                <td>{{ row.incorrectConfirmations }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-stability-focus-repeatability__section">
        <h2 class="barcode-stability-focus-repeatability__section-title">8. RAW DETECTIONS</h2>
        <div class="barcode-stability-focus-repeatability__actions mb-2">
          <button type="button" class="btn btn-sm" :class="rawFilter === 'all' ? 'btn-primary' : 'btn-outline-secondary'" @click="rawFilter = 'all'">Show all</button>
          <button type="button" class="btn btn-sm" :class="rawFilter === 'correct' ? 'btn-primary' : 'btn-outline-secondary'" @click="rawFilter = 'correct'">Show correct only</button>
          <button type="button" class="btn btn-sm" :class="rawFilter === 'incorrect' ? 'btn-primary' : 'btn-outline-secondary'" @click="rawFilter = 'incorrect'">Show incorrect only</button>
        </div>
        <div v-for="entry in filteredRawDetections" :key="entry.id" class="barcode-stability-focus-repeatability__raw-item font-monospace">
          {{ entry.timestamp }} — focus {{ entry.focusRequested }} — repetition {{ entry.repetition }}<br>
          format: {{ entry.format }}<br>
          rawValue: {{ entry.rawValue }}<br>
          classification: {{ entry.classification }}<br>
          boundingBox: {{ entry.boundingBoxWidth ?? '—' }} × {{ entry.boundingBoxHeight ?? '—' }}<br>
          widthRatio: {{ entry.widthRatio != null ? `${(entry.widthRatio * 100).toFixed(1)}%` : '—' }}<br>
          sharpness: {{ entry.sharpness ?? '—' }}
        </div>
      </section>

      <section class="barcode-stability-focus-repeatability__section barcode-stability-focus-repeatability__conclusion">
        <h2 class="barcode-stability-focus-repeatability__section-title">9. BENCHMARK CONCLUSION</h2>
        <pre class="barcode-stability-focus-repeatability__pre mb-3">{{ conclusion }}</pre>
        <div v-if="bestRanking.length > 0 && bestRanking[0]!.correct > 0">
          <h3 class="barcode-stability-focus-repeatability__subsection-title">Ranking (correct reads first)</h3>
          <ol class="barcode-stability-focus-repeatability__order-list">
            <li v-for="item in bestRanking.filter((row) => row.frames > 0).slice(0, 5)" :key="item.configId">
              focus {{ item.focusRequested }} rep {{ item.repetition }} — correct {{ item.correct }} — sequence {{ item.longestCorrectSequence }}
            </li>
          </ol>
        </div>
      </section>

      <p v-if="copyMessage" class="barcode-stability-focus-repeatability__muted mb-0">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-stability-focus-repeatability__warning mb-0">{{ cameraError }}</p>
    </div>
  </div>
</template>
