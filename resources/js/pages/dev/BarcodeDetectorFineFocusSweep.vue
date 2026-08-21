<script setup lang="ts">
import {
  applyExperimentConfiguration,
  buildBestFocusRanking,
  buildFineFocusConfigurations,
  buildObservedFocusWindow,
  buildSummaryTableRows,
  buildSweepConclusion,
  buildSweepReport,
  classifyReadResult,
  createComparisonBarcodeDetector,
  createEmptyFineFocusResult,
  DETECTION_INTERVAL_MS,
  DURATION_SECONDS,
  EXPECTED_BARCODE,
  extractBarcodeGeometry,
  finalizeFineFocusResult,
  FIXED_CAMERA_CONSTRAINTS,
  getEnvironmentDiagnostics,
  isNativeBarcodeDetectorAvailable,
  measureVideoSharpness,
  MEDIUM_GUIDE_WIDTH_RATIO,
  MEDIUM_INSTRUCTION,
  MEDIUM_SIZE_TARGET,
  normalizeDetections,
  pickBestNativeBarcode,
  randomizeFineFocusOrder,
  readTrackCapabilitiesSnapshot,
  readTrackSettingsSnapshot,
  resolveSweepFocusLevels,
  type AppliedExperimentSnapshot,
  type FineFocusConfiguration,
  type FineFocusConfigurationResult,
  type FineFocusRawDetection,
} from '@/utils/barcodeFineFocusSweep'
import type { BarcodeDetectorLike } from '@/utils/barcodeSizeZoomComparison'
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

const capabilities = ref(readTrackCapabilitiesSnapshot(null))
const trackSettings = ref(readTrackSettingsSnapshot(null))
const configurations = ref<FineFocusConfiguration[]>([])
const results = ref<FineFocusConfigurationResult[]>([])
const rawDetections = ref<FineFocusRawDetection[]>([])
const lastApplied = ref<AppliedExperimentSnapshot | null>(null)

const pendingConfig = ref<FineFocusConfiguration | null>(null)
const pendingConfigIndex = ref(0)
const currentConfigLabel = ref<string | null>(null)
const remainingSeconds = ref(0)
const liveStatus = ref<{
  widthRatio: number | null
  focus: string
  zoom: string
} | null>(null)

let cameraSessionId = 0
let runSessionId = 0
let runAbort = false
let startConfigResolver: (() => void) | null = null

const focusLevels = computed(() => resolveSweepFocusLevels(capabilities.value.focusDistance))
const configCount = computed(() => configurations.value.length)
const focusDistanceSupported = computed(() => capabilities.value.focusDistance.supported)

const focusWindow = computed(() => buildObservedFocusWindow(results.value))
const conclusion = computed(() => buildSweepConclusion(results.value, focusWindow.value))
const summaryRows = computed(() => buildSummaryTableRows(results.value))
const bestRanking = computed(() => buildBestFocusRanking(results.value))

const guideStyle = computed(() => ({
  width: `${MEDIUM_GUIDE_WIDTH_RATIO * 100}%`,
  aspectRatio: '2 / 1',
}))

const canRun = computed(() =>
  cameraState.value === 'READY'
  && isNativeBarcodeDetectorAvailable()
  && detectorRef.value != null
  && runState.value !== 'RUNNING'
  && runState.value !== 'AWAITING_START',
)

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
  const base = buildFineFocusConfigurations({
    expectedBarcode: expectedBarcode.value,
    focusLevels: focusLevels.value.length > 0 ? focusLevels.value : [0.2],
    preserveOrder: shuffle ? undefined : configurations.value,
  })

  configurations.value = shuffle && useRandomOrder.value
    ? randomizeFineFocusOrder(base)
    : base

  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyFineFocusResult(config)
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
    runMessage.value = 'Sweep stopped by user.'
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
  config: FineFocusConfiguration,
  configIndex: number,
  sessionId: number,
): Promise<FineFocusConfigurationResult> {
  const track = getVideoTrack()
  const detector = detectorRef.value
  const video = videoRef.value
  const canvas = sharpnessCanvasRef.value

  if (!track || !detector || !video || !canvas) {
    return createEmptyFineFocusResult(config)
  }

  pendingConfig.value = config
  pendingConfigIndex.value = configIndex
  currentConfigLabel.value = config.label
  liveStatus.value = { widthRatio: null, focus: String(config.requestedFocusDistance), zoom: '1×' }
  runState.value = 'AWAITING_START'
  runMessage.value = `Configuration ${configIndex + 1}/${configCount.value} — positionnez le code MEDIUM puis cliquez Start`

  await waitForConfigurationStart()

  if (runAbort || sessionId !== runSessionId) {
    pendingConfig.value = null
    return createEmptyFineFocusResult(config)
  }

  runState.value = 'RUNNING'

  const applied = await applyExperimentConfiguration(track, {
    requestedFocusDistance: config.requestedFocusDistance,
    requestedZoom: config.requestedZoom,
    focusDistanceCapabilities: capabilities.value.focusDistance,
    zoomStep: capabilities.value.zoom.step,
  })

  lastApplied.value = applied
  liveStatus.value = {
    widthRatio: liveStatus.value?.widthRatio ?? null,
    focus: applied.actualFocusDistance,
    zoom: `${applied.actualZoom}×`,
  }

  if (applied.configurationStatus !== 'VALID') {
    pendingConfig.value = null
    return finalizeFineFocusResult(config, applied, {
      frames: 0,
      detections: 0,
      correct: 0,
      incorrect: 0,
      notFound: 0,
      timeToFirstCorrectMs: null,
      sharpnessValues: [],
      barcodeWidths: [],
      barcodeHeights: [],
      widthRatios: [],
      correctTimestamps: [],
      correctTimestampsMs: [],
    })
  }

  let frames = 0
  let detections = 0
  let correct = 0
  let incorrect = 0
  let notFound = 0
  const sharpnessValues: number[] = []
  const barcodeWidths: number[] = []
  const barcodeHeights: number[] = []
  const widthRatios: number[] = []
  const correctTimestamps: string[] = []
  const correctTimestampsMs: number[] = []
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

              if (geometry.widthRatio != null) {
                liveStatus.value = {
                  widthRatio: geometry.widthRatio,
                  focus: applied.actualFocusDistance,
                  zoom: `${applied.actualZoom}×`,
                }
              }

              rawDetections.value = [{
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                timestamp: new Date().toLocaleTimeString('fr-FR'),
                focusRequested: config.requestedFocusDistance,
                focusActual: applied.actualFocusDistance,
                format: best?.format ?? '—',
                rawValue,
                classification: resultType,
                boundingBox: best?.boundingBox ?? null,
                boundingBoxWidth: geometry.width,
                boundingBoxHeight: geometry.height,
                widthRatio: geometry.widthRatio,
                sharpness,
              }, ...rawDetections.value]

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
              const nowLabel = new Date().toLocaleTimeString('fr-FR')
              correctTimestamps.push(nowLabel)
              correctTimestampsMs.push(performance.now())

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

  return finalizeFineFocusResult(config, applied, {
    frames,
    detections,
    correct,
    incorrect,
    notFound,
    timeToFirstCorrectMs,
    sharpnessValues,
    barcodeWidths,
    barcodeHeights,
    widthRatios,
    correctTimestamps,
    correctTimestampsMs,
  })
}

async function runSweep(): Promise<void> {
  if (useRandomOrder.value) {
    rebuildConfigurations(true)
  }

  const configs = [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex)
  const minutes = Math.ceil((configs.length * DURATION_SECONDS) / 60)
  const confirmed = window.confirm(
    `Run ${configs.length} focus values × ${DURATION_SECONDS}s ≈ ${minutes} min?\n\nKeep barcode at MEDIUM size and phone immobile during each measurement.`,
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
  liveStatus.value = null

  if (runAbort) {
    runState.value = 'STOPPED'
    return
  }

  runState.value = 'COMPLETED'
  runMessage.value = `Fine focus sweep completed — ${configs.length} configurations tested.`
}

function shuffleOrder(): void {
  configurations.value = randomizeFineFocusOrder(configurations.value)
  results.value = configurations.value.map((config) => {
    const existing = results.value.find((item) => item.configId === config.id)
    return existing ?? createEmptyFineFocusResult(config)
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
  const text = buildSweepReport({
    environment: environment.value,
    trackSettings: trackSettings.value,
    capabilities: capabilities.value,
    expectedBarcode: expectedBarcode.value,
    randomized: useRandomOrder.value,
    configurationOrder: [...configurations.value].sort((a, b) => a.orderIndex - b.orderIndex),
    results: [...results.value].sort((a, b) => a.focusRequested - b.focusRequested),
    rawDetections: rawDetections.value,
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
  <Head title="Barcode Fine Focus Sweep" />

  <div class="barcode-reader-test-page barcode-fine-focus-sweep">
    <div class="barcode-reader-test-page__container barcode-fine-focus-sweep__container">
      <header class="barcode-reader-test-page__header">
        <div>
          <h1 class="barcode-reader-test-page__title">Barcode Fine Focus Sweep</h1>
          <p class="barcode-reader-test-page__subtitle">Focus fin autour de 0.20 — MEDIUM, zoom 1×, Barcode A (DEV isolé)</p>
        </div>
        <div class="barcode-fine-focus-sweep__header-links">
          <Link href="/dev/barcode-detector-distance-focus" class="btn btn-sm btn-outline-secondary">Distance × Focus</Link>
          <Link href="/dev/barcode-detector-stability-focus-repeatability" class="btn btn-sm btn-outline-secondary">Stability Repeatability</Link>
          <Link href="/dev/barcode-detector-decode-reliability" class="btn btn-sm btn-outline-secondary">Decode Reliability</Link>
          <Link href="/dev/barcode-detector-size-zoom-comparison" class="btn btn-sm btn-outline-secondary">Size × Zoom</Link>
          <Link :href="dashboard()" class="btn btn-sm btn-outline-secondary">Retour</Link>
        </div>
      </header>

      <section class="barcode-fine-focus-sweep__banner">
        <p class="mb-1">BarcodeDetector: <strong>{{ isNativeBarcodeDetectorAvailable() ? 'AVAILABLE' : 'NOT AVAILABLE' }}</strong></p>
        <p class="mb-1">focusDistance: <strong>{{ focusDistanceSupported ? 'SUPPORTED' : 'NOT SUPPORTED' }}</strong></p>
        <p class="mb-0 barcode-fine-focus-sweep__muted">Mesure de répétabilité uniquement — aucun impact sur le scanner de production.</p>
      </section>

      <section v-if="pendingConfig" class="barcode-fine-focus-sweep__config-card">
        <p class="barcode-fine-focus-sweep__config-card-title mb-2">
          CONFIGURATION {{ pendingConfigIndex + 1 }} / {{ configCount }}
        </p>
        <pre class="barcode-fine-focus-sweep__pre mb-2">BARCODE A

Expected:
{{ expectedBarcode }}

SIZE:
MEDIUM

TARGET:
{{ MEDIUM_SIZE_TARGET }}

FOCUS:
{{ pendingConfig.requestedFocusDistance }}

ZOOM:
1×</pre>
        <pre class="barcode-fine-focus-sweep__pre mb-3">{{ MEDIUM_INSTRUCTION }}</pre>
        <button
          type="button"
          class="btn btn-success"
          :disabled="runState !== 'AWAITING_START'"
          @click="startPendingConfiguration"
        >
          Start
        </button>
      </section>

      <section class="barcode-fine-focus-sweep__section barcode-fine-focus-sweep__section--video">
        <div class="barcode-fine-focus-sweep__video-wrap">
          <video ref="videoRef" class="barcode-fine-focus-sweep__video" autoplay muted playsinline />
          <div class="barcode-fine-focus-sweep__size-guide" :style="guideStyle">
            <span class="barcode-fine-focus-sweep__size-guide-label">MEDIUM</span>
          </div>
          <div v-if="liveStatus?.widthRatio != null" class="barcode-fine-focus-sweep__live-status">
            <div>Detected barcode width:</div>
            <div><strong>{{ (liveStatus.widthRatio * 100).toFixed(1) }}%</strong></div>
            <div>Focus: {{ liveStatus.focus }}</div>
            <div>Zoom: {{ liveStatus.zoom }}</div>
          </div>
        </div>
        <canvas ref="sharpnessCanvasRef" class="barcode-fine-focus-sweep__hidden-canvas" aria-hidden="true" />

        <p v-if="runState === 'RUNNING'" class="barcode-fine-focus-sweep__countdown mb-0 mt-2">
          {{ remainingSeconds }}
        </p>

        <div class="barcode-fine-focus-sweep__actions">
          <button type="button" class="btn btn-primary btn-sm" :disabled="cameraState === 'STARTING'" @click="startCamera">Start Camera</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="cameraState !== 'READY'" @click="stopCamera">Stop Camera</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="!canRun" @click="runSweep">Run Sweep</button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="runState !== 'RUNNING' && runState !== 'AWAITING_START'" @click="stopRun">Stop</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyReport">Copy Report</button>
        </div>

        <div class="barcode-fine-focus-sweep__actions mt-2">
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
        </div>

        <p v-if="runMessage" class="barcode-fine-focus-sweep__muted mb-0 mt-2">{{ runMessage }}</p>
        <p v-if="currentConfigLabel && runState === 'RUNNING'" class="barcode-fine-focus-sweep__muted mb-0">
          Current: {{ currentConfigLabel }}
        </p>
      </section>

      <section class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">TEST CONDITIONS</h2>
        <pre class="barcode-fine-focus-sweep__pre mb-3">Barcode A — {{ expectedBarcode }}
Size: MEDIUM ({{ MEDIUM_SIZE_TARGET }})
Zoom: 1× fixed
Focus sweep: {{ focusLevels.join(', ') || '—' }}
Duration: {{ DURATION_SECONDS }}s per configuration
Sharpness is diagnostic only — not used for ranking.</pre>

        <h3 class="barcode-fine-focus-sweep__subsection-title">Configuration order</h3>
        <ol class="barcode-fine-focus-sweep__order-list">
          <li v-for="config in [...configurations].sort((a, b) => a.orderIndex - b.orderIndex)" :key="config.id">
            focus {{ config.requestedFocusDistance }}
          </li>
        </ol>
      </section>

      <section v-if="lastApplied" class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">Last camera validation</h2>
        <dl class="barcode-fine-focus-sweep__grid">
          <div><dt>Validation focus mode</dt><dd>{{ lastApplied.focusModeValidation }}</dd></div>
          <div><dt>Validation focus distance</dt><dd>{{ lastApplied.focusDistanceValidation }}</dd></div>
          <div><dt>Validation zoom</dt><dd>{{ lastApplied.zoomValidation }}</dd></div>
        </dl>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">SUMMARY TABLE</h2>
        <div class="barcode-fine-focus-sweep__table-wrap">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Focus</th>
                <th>Actual</th>
                <th>Frames</th>
                <th>Detections</th>
                <th>Correct</th>
                <th>Incorrect</th>
                <th>Detection rate</th>
                <th>Correct rate</th>
                <th>Avg width</th>
                <th>Sharpness</th>
                <th>Stability</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in summaryRows" :key="row.focus">
                <td>{{ row.focus }}</td>
                <td>{{ row.actual }}</td>
                <td>{{ row.frames }}</td>
                <td>{{ row.detections }}</td>
                <td>{{ row.correct }}</td>
                <td>{{ row.incorrect }}</td>
                <td>{{ row.detectionRate }}</td>
                <td>{{ row.correctRate }}</td>
                <td>{{ row.avgWidth }}</td>
                <td>{{ row.sharpness }}</td>
                <td>{{ row.stability }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="results.some((item) => item.correct > 0)" class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">Repeatability by focus</h2>
        <div v-for="item in summaryRows.filter((row) => row.correct > 0)" :key="`repeat-${item.focus}`" class="mb-3">
          <p class="mb-1"><strong>Focus {{ item.focus }}</strong></p>
          <p class="mb-1">Correct detections: {{ item.correct }}</p>
          <p class="mb-1">Timestamps:</p>
          <pre class="barcode-fine-focus-sweep__pre mb-0">{{ results.find((r) => r.focusRequested === item.focus)?.correctTimestamps.join('\n') || '—' }}</pre>
        </div>
      </section>

      <section v-if="results.some((item) => item.frames > 0)" class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">BEST FOCUS CONFIGURATIONS</h2>
        <ol class="barcode-fine-focus-sweep__order-list">
          <li v-for="item in bestRanking" :key="item.configId">
            focus {{ item.focusRequested }} — correct {{ item.correct }} — correct rate {{ item.correctRate }} — stability {{ item.stability }}
          </li>
        </ol>
      </section>

      <section v-if="focusWindow.rangeLabel" class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">OBSERVED FOCUS WINDOW</h2>
        <pre class="barcode-fine-focus-sweep__pre mb-0">Approximate promising range:
{{ focusWindow.rangeLabel }}

Best observed:
{{ focusWindow.bestFocus?.toFixed(2) }}</pre>
      </section>

      <section v-if="rawDetections.length > 0" class="barcode-fine-focus-sweep__section">
        <h2 class="barcode-fine-focus-sweep__section-title">RAW DETECTIONS</h2>
        <div v-for="entry in rawDetections" :key="entry.id" class="barcode-fine-focus-sweep__raw-item font-monospace">
          {{ entry.timestamp }} — focus {{ entry.focusRequested }}<br>
          format: {{ entry.format }}<br>
          rawValue: {{ entry.rawValue }}<br>
          classification: {{ entry.classification }}<br>
          boundingBox: {{ entry.boundingBoxWidth ?? '—' }} × {{ entry.boundingBoxHeight ?? '—' }}<br>
          widthRatio: {{ entry.widthRatio != null ? `${(entry.widthRatio * 100).toFixed(1)}%` : '—' }}<br>
          sharpness: {{ entry.sharpness ?? '—' }}
        </div>
      </section>

      <section class="barcode-fine-focus-sweep__section barcode-fine-focus-sweep__conclusion">
        <h2 class="barcode-fine-focus-sweep__section-title">Conclusion</h2>
        <pre class="barcode-fine-focus-sweep__pre mb-0">{{ conclusion }}</pre>
      </section>

      <p v-if="copyMessage" class="barcode-fine-focus-sweep__muted mb-0">{{ copyMessage }}</p>
      <p v-if="cameraError" class="barcode-fine-focus-sweep__warning mb-0">{{ cameraError.name }} — {{ cameraError.message }}</p>
    </div>
  </div>
</template>
