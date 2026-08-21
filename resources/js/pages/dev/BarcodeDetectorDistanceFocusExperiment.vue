<script setup lang="ts">
import {
  applyExperimentConfiguration,
  buildBestConfigurationsRanking,
  buildDistanceFocusConfigurations,
  buildExperimentConclusion,
  buildExperimentReport,
  buildFocusComparisonSummary,
  buildSizeComparisonSummary,
  buildSummaryTableRows,
  classifyReadResult,
  createComparisonBarcodeDetector,
  createEmptyDistanceFocusResult,
  DURATION_SECONDS,
  DETECTION_INTERVAL_MS,
  EXPECTED_BARCODE,
  extractBarcodeGeometry,
  finalizeDistanceFocusResult,
  findBestDistanceFocusConfiguration,
  FIXED_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  normalizeDetections,
  pickBestNativeBarcode,
  randomizeDistanceFocusOrder,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  resolveFocusLevels,
  type AppliedExperimentSnapshot,
  type BarcodeDetectorLike,
  type DistanceFocusConfiguration,
  type DistanceFocusConfigurationResult,
  type DistanceFocusRawDetection,
} from '@/utils/barcodeDistanceFocusExperiment'
import { Head, Link } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { dashboard } from '@/routes'

const START_TIMEOUT_MS = 10_000

type CameraUiState = 'IDLE' | 'STARTING' | 'READY' | 'ERROR' | 'STOPPED'
type RunUiState = 'IDLE' | 'AWAITING_START' | 'RUNNING' | 'STOPPED' | 'COMPLETED'

const videoRef = ref<HTMLVideoElement | null>(null)
const sharpnessCanvasRef = ref<HTMLCanvasElement | null>(null)
const activeStream = shallowRef<MediaStream | null>(null)
const detectorRef = shallowRef<BarcodeDetectorLike | null>(null)

const environment = ref(getEnvironmentDiagnostics())
const cameraState = ref<CameraUiState>('IDLE')
const runState = ref<RunUiState>('IDLE')
const cameraError = ref<{ name: string; message: string } | null>(null)
const copyMessage = ref<string | null>(null)
const runMessage = ref<string | null>(null)

const expectedBarcode = ref(EXPECTED_BARCODE)
const useRandomOrder = ref(true)
const showDiagnosticOverlay = ref(false)

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<DistanceFocusConfiguration[]>([])
const results = ref<DistanceFocusConfigurationResult[]>([])
const rawDetections = ref<DistanceFocusRawDetection[]>([])
const lastApplied = ref<AppliedExperimentSnapshot | null>(null)

const pendingConfig = ref<DistanceFocusConfiguration | null>(null)
const pendingConfigIndex = ref(0)
const currentConfigLabel = ref<string | null>(null)
const remainingSeconds = ref(0)
const currentBarcodeWidthRatio = ref<number | null>(null)
const diagnosticOverlay = ref<{
  rawValue: string
  focus: string
  zoom: string
  widthRatio: string
  sharpness: string
  bbox: string
} | null>(null)

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let startConfigResolver: (() => void) | null = null

const focusLevels = computed(() => resolveFocusLevels(capabilities.value.focusDistance))
const configCount = computed(() => configurations.value.length)
const focusDistanceSupported = computed(() => capabilities.value.focusDistance.supported)

const bestConfiguration = computed(() => findBestDistanceFocusConfiguration(results.value))
const conclusion = computed(() => buildExperimentConclusion(bestConfiguration.value))
const summaryRows = computed(() => buildSummaryTableRows(results.value))
const sizeSummary = computed(() => buildSizeComparisonSummary(results.value))
const focusSummary = computed(() => buildFocusComparisonSummary(results.value))
const bestRanking = computed(() => buildBestConfigurationsRanking(results.value))

const canRun = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && runState.value !== 'RUNNING'
  && runState.value !== 'AWAITING_START',
)

const guideStyle = computed(() => {
  const ratio = pendingConfig.value?.guideWidthRatio ?? 0.45
  const widthPercent = ratio * 100

  return {
    width: `${widthPercent}%`,
    aspectRatio: '2 / 1',
  }
})

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

function rebuildConfigurations(shuffle = false): void {
  const base = buildDistanceFocusConfigurations({
    expectedBarcode: expectedBarcode.value,
    focusLevels: focusLevels.value.length > 0 ? focusLevels.value : [0.39],
    preserveOrder: shuffle ? undefined : configurations.value,
  })

  configurations.value = shuffle && useRandomOrder.value
    ? randomizeDistanceFocusOrder(base)
    : base

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyDistanceFocusResult(config)
  })
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

    const stream = await navigator.mediaDevices.getUserMedia(FIXED_CAMERA_CONSTRAINTS)

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
    rebuildConfigurations(useRandomOrder.value)

    const detectorResult = await createComparisonBarcodeDetector()
    detectorRef.value = detectorResult.detector
    cameraState.value = 'READY'
  } catch (error) {
    cameraState.value = 'ERROR'
    cameraError.value = error instanceof Error
      ? { name: error.name, message: error.message }
      : { name: 'Error', message: String(error) }
  }
}

async function stopCamera(): Promise<void> {
  stopRun()
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
  pendingConfig.value = null
  startConfigResolver = null

  if (runState.value === 'RUNNING' || runState.value === 'AWAITING_START') {
    runState.value = 'STOPPED'
    runMessage.value = 'Experiment stopped by user.'
  }
}

function waitForConfigurationStart(): Promise<void> {
  return new Promise((resolve) => {
    startConfigResolver = resolve
  })
}

function startPendingConfiguration(): void {
  if (startConfigResolver) {
    const resolve = startConfigResolver
    startConfigResolver = null
    resolve()
  }
}

async function runConfiguration(
  config: DistanceFocusConfiguration,
  configIndex: number,
  sessionId: number,
): Promise<DistanceFocusConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyDistanceFocusResult(config)
  }

  pendingConfig.value = config
  pendingConfigIndex.value = configIndex
  currentConfigLabel.value = config.label
  currentBarcodeWidthRatio.value = null
  diagnosticOverlay.value = null
  runState.value = 'AWAITING_START'
  runMessage.value = `Configuration ${configIndex + 1}/${configCount.value} — positionnez le code puis cliquez Start configuration`

  await waitForConfigurationStart()

  if (runAbort || sessionId !== runSessionId) {
    pendingConfig.value = null
    return createEmptyDistanceFocusResult(config)
  }

  runState.value = 'RUNNING'

  const applied = await applyExperimentConfiguration(track, {
    requestedFocusDistance: config.requestedFocusDistance,
    requestedZoom: config.requestedZoom,
    focusDistanceCapabilities: capabilities.value.focusDistance,
    zoomStep: capabilities.value.zoom.step,
  })

  lastApplied.value = applied

  if (applied.configurationStatus !== 'VALID') {
    pendingConfig.value = null
    return finalizeDistanceFocusResult(config, applied, {
      frames: 0,
      detections: 0,
      correct: 0,
      incorrect: 0,
      notFound: 0,
      timeToFirstCorrectMs: null,
      sharpnessValues: [],
      sharpnessAtDetections: [],
      sharpnessAtCorrectDetections: [],
      barcodeWidths: [],
      barcodeHeights: [],
      widthRatios: [],
    })
  }

  let frames = 0
  let detections = 0
  let correct = 0
  let incorrect = 0
  let notFound = 0
  const sharpnessValues: number[] = []
  const sharpnessAtDetections: number[] = []
  const sharpnessAtCorrectDetections: number[] = []
  const barcodeWidths: number[] = []
  const barcodeHeights: number[] = []
  const widthRatios: number[] = []
  let timeToFirstCorrectMs: number | null = null
  let detectionInProgress = false
  let lastDetectionTime = 0

  const startedAt = performance.now()
  const endAt = startedAt + DURATION_SECONDS * 1000
  remainingSeconds.value = DURATION_SECONDS

  const countdownTimer = window.setInterval(() => {
    remainingSeconds.value = Math.max(0, Math.ceil((endAt - performance.now()) / 1000))
  }, 200)

  await new Promise<void>((resolve) => {
    const tick = (timestamp: number): void => {
      if (runAbort || sessionId !== runSessionId || performance.now() >= endAt) {
        window.clearInterval(countdownTimer)
        resolve()
        return
      }

      if (!detectionInProgress && timestamp - lastDetectionTime >= DETECTION_INTERVAL_MS) {
        detectionInProgress = true
        lastDetectionTime = timestamp

        void (async () => {
          const sharpness = measureVideoSharpness(video, canvas)

          if (sharpness != null) {
            sharpnessValues.push(sharpness)
          }

          try {
            frames += 1
            const rawResults = normalizeDetections(await detector.detect(video))
            const best = pickBestNativeBarcode(rawResults)
            const rawValue = best?.rawValue ?? ''
            const resultType = classifyReadResult(rawValue, config.expectedBarcode)
            const geometry = extractBarcodeGeometry(best, video.videoWidth)

            if (rawValue) {
              detections += 1

              if (sharpness != null) {
                sharpnessAtDetections.push(sharpness)
              }

              if (geometry.widthRatio != null) {
                currentBarcodeWidthRatio.value = geometry.widthRatio
              }

              rawDetections.value = [{
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                timestamp: new Date().toLocaleTimeString('fr-FR'),
                configuration: config.label,
                size: config.size,
                focus: applied.actualFocusDistance,
                zoom: applied.actualZoom,
                format: best?.format ?? '—',
                rawValue,
                classification: resultType,
                boundingBox: best?.boundingBox ?? null,
                boundingBoxWidth: geometry.width,
                boundingBoxHeight: geometry.height,
                boundingBoxWidthRatio: geometry.widthRatio,
                sharpness,
              }, ...rawDetections.value].slice(0, 50)

              if (showDiagnosticOverlay.value) {
                diagnosticOverlay.value = {
                  rawValue,
                  focus: applied.actualFocusDistance,
                  zoom: `${applied.actualZoom}×`,
                  widthRatio: geometry.widthRatio != null ? `${(geometry.widthRatio * 100).toFixed(1)}%` : '—',
                  sharpness: sharpness != null ? String(Math.round(sharpness)) : '—',
                  bbox: geometry.width != null && geometry.height != null
                    ? `${geometry.width} × ${geometry.height}`
                    : '—',
                }
              }

              if (geometry.width != null) {
                barcodeWidths.push(geometry.width)
              }

              if (geometry.height != null) {
                barcodeHeights.push(geometry.height)
              }

              if (geometry.widthRatio != null) {
                widthRatios.push(geometry.widthRatio)
              }
            }

            if (resultType === 'CORRECT') {
              correct += 1

              if (sharpness != null) {
                sharpnessAtCorrectDetections.push(sharpness)
              }

              if (timeToFirstCorrectMs == null) {
                timeToFirstCorrectMs = Math.round(performance.now() - startedAt)
              }
            } else if (resultType === 'INCORRECT') {
              incorrect += 1
            } else {
              notFound += 1
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

  pendingConfig.value = null
  remainingSeconds.value = 0
  refreshDiagnostics()

  return finalizeDistanceFocusResult(config, applied, {
    frames,
    detections,
    correct,
    incorrect,
    notFound,
    timeToFirstCorrectMs,
    sharpnessValues,
    sharpnessAtDetections,
    sharpnessAtCorrectDetections,
    barcodeWidths,
    barcodeHeights,
    widthRatios,
  })
}

async function runExperiment(): Promise<void> {
  if (!canRun.value && runState.value !== 'IDLE' && runState.value !== 'COMPLETED' && runState.value !== 'STOPPED') {
    return
  }

  if (useRandomOrder.value) {
    rebuildConfigurations(true)
  }

  const configs = [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex)
  const minutes = Math.ceil((configs.length * DURATION_SECONDS) / 60)
  const confirmed = window.confirm(
    `Run ${configs.length} configurations × ${DURATION_SECONDS}s ≈ ${minutes} min?\n\nPosition each barcode size before starting each configuration.`,
  )

  if (!confirmed) {
    return
  }

  stopRun()
  runSessionId += 1
  const sessionId = runSessionId
  runAbort = false
  runState.value = 'AWAITING_START'
  runMessage.value = null
  rawDetections.value = []

  for (let index = 0; index < configs.length; index += 1) {
    if (runAbort || sessionId !== runSessionId) {
      break
    }

    const config = configs[index]!
    const result = await runConfiguration(config, index, sessionId)
    results.value = results.value.map((item) => item.configId === config.id ? result : item)
  }

  currentConfigLabel.value = null
  pendingConfig.value = null

  if (runAbort) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
  runMessage.value = `Experiment completed — ${configs.length} configurations tested.`
}

function shuffleOrder(): void {
  configurations.value = randomizeDistanceFocusOrder(configurations.value)
  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyDistanceFocusResult(config)
  })
}

function applyFixedOrder(): void {
  useRandomOrder.value = false
  rebuildConfigurations(false)
}

function applyRandomOrder(): void {
  useRandomOrder.value = true
  shuffleOrder()
}

async function copyReport(): Promise<void> {
  const text = buildExperimentReport({
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    expectedBarcode: expectedBarcode.value,
    randomized: useRandomOrder.value,
    configurationOrder: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
    results: [...results.value].sort((a, b) => a.orderIndex - b.orderIndex),
    rawDetections: rawDetections.value,
    best: bestConfiguration.value,
    conclusion: conclusion.value,
  })

  try {
    await navigator.clipboard.writeText(text)
    copyMessage.value = 'Rapport copié.'
  } catch {
    copyMessage.value = 'Impossible de copier.'
  }
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'hidden') {
    stopRun()
  }
}

onMounted(() => {
  environment.value = getEnvironmentDiagnostics()
  rebuildConfigurations(false)
  window.addEventListener('pagehide', () => { void stopCamera() })
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  void stopCamera()
})
</script>

<template>
  <Head title="Barcode Distance × Focus Experiment" />

  <div class="barcode-reader-test-page barcode-distance-focus-experiment">
    <div class="barcode-reader-test-page__container barcode-distance-focus-experiment__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Distance × Focus Experiment</h1>
          <p class="barcode-reader-test-page__subtitle">Taille apparente × focus — zoom 1× uniquement (DEV isolé)</p>
        </div>
        <div class="barcode-distance-focus-experiment__header-links">
          <Link href="/dev/barcode-detector-size-zoom-comparison" class="btn btn-sm btn-outline-secondary">Size × Zoom</Link>
          <Link href="/dev/barcode-detector-focus-zoom-benchmark" class="btn btn-sm btn-outline-secondary">Focus × Zoom</Link>
          <Link href="/dev/barcode-detector-fine-focus" class="btn btn-sm btn-outline-secondary">Fine Focus Sweep</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-distance-focus-experiment__banner">
        <p class="mb-1">BarcodeDetector: <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong></p>
        <p class="mb-1">focusDistance: <strong>{{ focusDistanceSupported ? 'SUPPORTED' : 'NOT SUPPORTED' }}</strong></p>
        <p class="mb-0 barcode-distance-focus-experiment__muted">Outil de mesure uniquement — aucun impact sur le scanner de production.</p>
      </section>

      <section v-if="pendingConfig" class="barcode-distance-focus-experiment__config-card">
        <p class="barcode-distance-focus-experiment__config-card-title mb-2">
          CONFIGURATION {{ pendingConfigIndex + 1 }} / {{ configCount }}
        </p>
        <pre class="barcode-distance-focus-experiment__pre mb-2">SIZE: {{ pendingConfig.size }}
TARGET: {{ pendingConfig.sizeTarget }}

FOCUS: {{ pendingConfig.requestedFocusDistance }}
ZOOM: 1×

EXPECTED:
{{ expectedBarcode }}</pre>
        <pre class="barcode-distance-focus-experiment__pre mb-3">{{ pendingConfig.sizeInstruction }}</pre>
        <button
          type="button"
          class="btn btn-success"
          :disabled="runState !== 'AWAITING_START'"
          @click="startPendingConfiguration"
        >
          Start configuration
        </button>
      </section>

      <section class="barcode-distance-focus-experiment__section barcode-distance-focus-experiment__section--video">
        <div class="barcode-distance-focus-experiment__video-wrap">
          <video ref="videoRef" class="barcode-distance-focus-experiment__video" autoplay muted playsinline />
          <div
            v-if="pendingConfig"
            class="barcode-distance-focus-experiment__size-guide"
            :style="guideStyle"
          >
            <span class="barcode-distance-focus-experiment__size-guide-label">{{ pendingConfig.size }}</span>
          </div>
          <div v-if="showDiagnosticOverlay && diagnosticOverlay" class="barcode-distance-focus-experiment__diag-overlay">
            <div>rawValue: {{ diagnosticOverlay.rawValue }}</div>
            <div>bbox: {{ diagnosticOverlay.bbox }}</div>
            <div>focus: {{ diagnosticOverlay.focus }}</div>
            <div>zoom: {{ diagnosticOverlay.zoom }}</div>
            <div>width ratio: {{ diagnosticOverlay.widthRatio }}</div>
            <div>sharpness: {{ diagnosticOverlay.sharpness }}</div>
          </div>
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-distance-focus-experiment__hidden-canvas" aria-hidden="true" />

        <p v-if="currentBarcodeWidthRatio != null" class="barcode-distance-focus-experiment__muted mb-0 mt-2">
          Barcode width: {{ (currentBarcodeWidthRatio * 100).toFixed(1) }}% of image width
        </p>

        <div class="barcode-distance-focus-experiment__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCamera">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canRun" @click="runExperiment">Run Experiment</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING' && runState !== 'AWAITING_START'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Copy Report</button>
        </div>

        <div class="barcode-distance-focus-experiment__actions mt-2">
          <button
            type="button"
            class="btn btn-sm"
            :class="useRandomOrder ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING' || runState === 'AWAITING_START'"
            @click="applyRandomOrder"
          >
            Randomize order
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="!useRandomOrder ? 'btn-primary' : 'btn-outline-secondary'"
            :disabled="runState === 'RUNNING' || runState === 'AWAITING_START'"
            @click="applyFixedOrder"
          >
            Fixed order
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="showDiagnosticOverlay ? 'btn-warning' : 'btn-outline-secondary'"
            @click="showDiagnosticOverlay = !showDiagnosticOverlay"
          >
            Diagnostic overlay
          </button>
        </div>

        <p v-if="runMessage" class="barcode-distance-focus-experiment__muted mb-0 mt-2">{{ runMessage }}</p>
        <p v-if="currentConfigLabel && runState === 'RUNNING'" class="barcode-distance-focus-experiment__muted mb-0">
          Current: {{ currentConfigLabel }} — Remaining: {{ remainingSeconds }} s
        </p>
      </section>

      <section class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">TEST CONDITIONS</h2>
        <pre class="barcode-distance-focus-experiment__pre mb-3">Barcode A only — expected {{ expectedBarcode }}
Zoom fixed at 1×
Focus levels: {{ focusLevels.join(', ') || '—' }}
Duration per configuration: {{ DURATION_SECONDS }}s
Keep the phone immobile during each 15-second capture.</pre>

        <p class="mb-2">Expected barcode: <strong class="font-monospace">{{ expectedBarcode }}</strong></p>

        <h3 class="barcode-distance-focus-experiment__subsection-title">Configuration order</h3>
        <ol class="barcode-distance-focus-experiment__order-list">
          <li v-for="config in [...configurations].sort((a, b) => a.orderIndex - b.orderIndex)" :key="config.id">
            {{ config.label }}
          </li>
        </ol>
      </section>

      <section v-if="lastApplied" class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">Last camera validation</h2>
        <dl class="barcode-distance-focus-experiment__grid">
          <div><dt>Validation focus mode</dt><dd>{{ lastApplied.focusModeValidation }}</dd></div>
          <div><dt>Validation focus distance</dt><dd>{{ lastApplied.focusDistanceValidation }}</dd></div>
          <div><dt>Validation zoom</dt><dd>{{ lastApplied.zoomValidation }}</dd></div>
          <div><dt>Focus requested</dt><dd>{{ lastApplied.requestedFocusDistance }}</dd></div>
          <div><dt>Focus actual</dt><dd>{{ lastApplied.actualFocusDistance }}</dd></div>
          <div><dt>Zoom actual</dt><dd>{{ lastApplied.actualZoom }}×</dd></div>
        </dl>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">SUMMARY TABLE</h2>
        <div class="barcode-distance-focus-experiment__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Size</th>
                <th>Focus</th>
                <th>Detection rate</th>
                <th>Correct rate</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Avg width</th>
                <th>Avg sharpness</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in summaryRows" :key="`${row.size}-${row.focus}`">
                <td>{{ row.size }}</td>
                <td>{{ row.focus }}</td>
                <td>{{ row.detectionRate }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.incorrect }}</td>
                <td>{{ row.avgWidth }}</td>
                <td>{{ row.avgSharpness }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">Best configurations by CORRECT READS</h2>
        <ol class="barcode-distance-focus-experiment__order-list">
          <li v-for="item in bestRanking" :key="item.configId">
            {{ item.configuration }} — correct {{ item.correct }} — correct rate {{ item.correctRate }} — detection rate {{ item.detectionRate }}
          </li>
        </ol>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">Comparison by apparent size</h2>
        <pre class="barcode-distance-focus-experiment__pre mb-0"><template v-for="item in sizeSummary" :key="item.size">{{ item.size }}
correct reads: {{ item.correctReads }}
detection rate: {{ item.detectionRate }}

</template></pre>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">Comparison by focus</h2>
        <pre class="barcode-distance-focus-experiment__pre mb-0"><template v-for="item in focusSummary" :key="item.focus">FOCUS {{ item.focus.toFixed(2) }}
correct reads: {{ item.correctReads }}

</template></pre>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-distance-focus-experiment__section">
        <h2 class="barcode-distance-focus-experiment__section-title">RAW DETECTIONS</h2>
        <div v-for="entry in rawDetections" :key="entry.id" class="barcode-distance-focus-experiment__raw-item font-monospace">
          {{ entry.timestamp }} — {{ entry.size }} — focus {{ entry.focus }}<br>
          format: {{ entry.format }}<br>
          rawValue: {{ entry.rawValue }}<br>
          classification: {{ entry.classification }}<br>
          boundingBox: {{ entry.boundingBoxWidth ?? '—' }} × {{ entry.boundingBoxHeight ?? '—' }}<br>
          widthRatio: {{ entry.boundingBoxWidthRatio != null ? `${(entry.boundingBoxWidthRatio * 100).toFixed(1)}%` : '—' }}<br>
          sharpness: {{ entry.sharpness ?? '—' }}
        </div>
      </section>

      <section class="barcode-distance-focus-experiment__section barcode-distance-focus-experiment__conclusion">
        <h2 class="barcode-distance-focus-experiment__section-title">Conclusion</h2>
        <pre class="barcode-distance-focus-experiment__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-distance-focus-experiment__muted mb-0">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-distance-focus-experiment__warning mb-0">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
